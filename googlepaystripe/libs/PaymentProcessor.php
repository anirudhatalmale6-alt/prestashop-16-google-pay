<?php
/**
 * Turns a succeeded Stripe PaymentIntent into a PrestaShop order.
 *
 * Shared by two callers that can both fire for the same payment:
 *   - the browser returning from the Google Pay sheet (validation controller)
 *   - Stripe's server-to-server webhook (webhook controller)
 *
 * Whichever arrives first creates the order; the other one must not create a
 * duplicate. That is what claimIntent() is for.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GooglePayStripePaymentProcessor
{
    /** Sentinel written into id_order while one process is creating the order. */
    const CLAIM_IN_PROGRESS = -1;

    /** @var GooglePayStripe */
    protected $module;

    public function __construct(GooglePayStripe $module)
    {
        $this->module = $module;
    }

    /**
     * Atomically take ownership of an intent.
     *
     * Returns true if this process may create the order. Returns false if the
     * order already exists or another process got there first — the caller
     * should then just show the existing order.
     *
     * The single UPDATE is the lock: only one caller can move a row from
     * id_order = 0 to the in-progress sentinel.
     */
    public function claimIntent($payment_intent_id)
    {
        Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'googlepaystripe_payment`
             SET `id_order` = '.(int)self::CLAIM_IN_PROGRESS.', `date_upd` = NOW()
             WHERE `payment_intent_id` = "'.pSQL($payment_intent_id).'"
               AND `id_order` = 0'
        );

        return (int)Db::getInstance()->Affected_Rows() === 1;
    }

    /**
     * Undo a claim so a later attempt (or the webhook) can retry.
     */
    public function releaseIntent($payment_intent_id)
    {
        Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'googlepaystripe_payment`
             SET `id_order` = 0, `date_upd` = NOW()
             WHERE `payment_intent_id` = "'.pSQL($payment_intent_id).'"
               AND `id_order` = '.(int)self::CLAIM_IN_PROGRESS
        );
    }

    /**
     * Validates that an intent really belongs to this cart and this shop.
     *
     * @return true|string true when safe, otherwise the reason it is not
     */
    public function verifyIntent($intent, Cart $cart, Customer $customer)
    {
        if (!isset($intent->metadata) || !isset($intent->metadata['ps_cart_id'])) {
            return 'intent carries no cart reference';
        }

        if ((int)$intent->metadata['ps_cart_id'] !== (int)$cart->id) {
            return 'intent belongs to cart '.(int)$intent->metadata['ps_cart_id'].', not '.(int)$cart->id;
        }

        // Stops a stolen intent id from being replayed against another
        // customer's session.
        if (isset($intent->metadata['ps_secure_key'])
            && $intent->metadata['ps_secure_key'] !== $customer->secure_key) {
            return 'secure key mismatch';
        }

        if (!in_array($intent->status, array('succeeded', 'requires_capture'))) {
            return 'intent status is '.$intent->status;
        }

        // A live key must never settle an order that was paid in test mode and
        // the other way round.
        $expected_live = !$this->module->isTestMode();
        if (isset($intent->livemode) && (bool)$intent->livemode !== $expected_live) {
            return 'intent livemode does not match the module mode';
        }

        return true;
    }

    /**
     * Creates the PrestaShop order for a verified, paid intent.
     *
     * @return int the order id
     * @throws Exception
     */
    public function createOrder($intent, Cart $cart, Customer $customer)
    {
        $currency = new Currency((int)$cart->id_currency);
        $paid = (float)$this->module->fromStripeAmount($intent->amount_received > 0 ? $intent->amount_received : $intent->amount, $currency->iso_code);
        $expected = $this->module->getCartTotal($cart);

        $details = $this->extractCardDetails($intent);

        // Compare in minor units: floats do not compare cleanly, and one cent
        // of drift here would flag every single order.
        $paid_minor = $this->module->toStripeAmount($paid, $currency->iso_code);
        $expected_minor = $this->module->toStripeAmount($expected, $currency->iso_code);

        $order_state = (int)Configuration::get('PS_OS_PAYMENT');
        $message = '';

        if ($paid_minor !== $expected_minor) {
            // The cart changed between the price being quoted and the card
            // being charged. Do not silently accept it: park the order in the
            // payment-error state so a human looks at it.
            $order_state = (int)Configuration::get('PS_OS_ERROR');
            $message = 'Amount mismatch: Stripe captured '.$paid.' '.$currency->iso_code
                .' but the cart totalled '.$expected.' '.$currency->iso_code.'. Check before shipping.';

            $this->module->log('Amount mismatch on cart '.(int)$cart->id.' — '.$message, 3);
        } elseif ($intent->status === 'requires_capture') {
            // Authorised but not captured: the money is held, not taken. Do not
            // use "Payment accepted" — that would tell the merchant they have
            // been paid when they have not been.
            $order_state = (int)Configuration::get('PS_OS_PREPARATION');
            $message = 'Funds authorised but NOT captured. Capture the payment in the Stripe dashboard to actually take the money.';
        }

        $extra_vars = array();
        if ($details['charge_id'] !== '') {
            $extra_vars['transaction_id'] = $details['charge_id'];
        }

        $payment_method_name = $this->module->displayName;
        if ($details['card_brand'] !== '' && $details['card_last4'] !== '') {
            $payment_method_name .= ' - '.Tools::ucfirst($details['card_brand']).' ****'.$details['card_last4'];
        }

        $this->module->validateOrder(
            (int)$cart->id,
            $order_state,
            $paid,
            $payment_method_name,
            $message,
            $extra_vars,
            (int)$currency->id,
            false,
            $customer->secure_key
        );

        $id_order = (int)$this->module->currentOrder;

        $this->attachOrder($intent->id, $id_order, $intent, $details, $paid, $currency->iso_code);

        $this->module->log('Order '.$id_order.' created from intent '.$intent->id.' (cart '.(int)$cart->id.')');

        return $id_order;
    }

    /**
     * Fetches a charge by id when the intent only carried a reference.
     *
     * Never fatal: the card brand is a display convenience, so a failure here
     * must not stop an order being created for a payment that succeeded.
     *
     * @return \Stripe\Charge|null
     */
    protected function retrieveCharge($charge_id)
    {
        try {
            $stripe = $this->module->getStripeClient();

            return $stripe->charges->retrieve($charge_id, array());
        } catch (Exception $e) {
            $this->module->log('Could not retrieve charge '.$charge_id.': '.$e->getMessage(), 2);

            return null;
        }
    }

    /**
     * Pulls the readable bits out of the intent so the merchant can see what
     * was actually used, without ever storing a card number.
     */
    public function extractCardDetails($intent)
    {
        $out = array(
            'charge_id'         => '',
            'payment_method_id' => '',
            'card_brand'        => '',
            'card_last4'        => '',
            'card_funding'      => '',
            'wallet_type'       => '',
        );

        if (isset($intent->payment_method)) {
            $out['payment_method_id'] = is_string($intent->payment_method)
                ? $intent->payment_method
                : (isset($intent->payment_method->id) ? $intent->payment_method->id : '');
        }

        $charge = null;
        if (isset($intent->charges) && isset($intent->charges->data) && count($intent->charges->data)) {
            $charge = $intent->charges->data[0];
        } elseif (isset($intent->latest_charge)) {
            $charge = $intent->latest_charge;
        }

        if (!$charge) {
            return $out;
        }

        $out['charge_id'] = is_string($charge) ? $charge : (isset($charge->id) ? $charge->id : '');

        if (is_string($charge)) {
            // Newer Stripe API versions drop the `charges` list and return
            // `latest_charge` as a bare id, so the card details are simply not
            // in the intent. Without this the back office showed the charge id
            // but no brand or last 4 — which is exactly what happened on the
            // first live order. Fetch the charge rather than lose them.
            $charge = $this->retrieveCharge($charge);
            if (!$charge) {
                return $out;
            }
        }

        if (isset($charge->payment_method_details) && isset($charge->payment_method_details->card)) {
            $card = $charge->payment_method_details->card;
            $out['card_brand'] = isset($card->brand) ? $card->brand : '';
            $out['card_last4'] = isset($card->last4) ? $card->last4 : '';
            $out['card_funding'] = isset($card->funding) ? $card->funding : '';

            // Confirms in the back office that this really came through Google
            // Pay rather than a plain card form.
            if (isset($card->wallet) && isset($card->wallet->type)) {
                $out['wallet_type'] = $card->wallet->type;
            }
        }

        return $out;
    }

    /**
     * Writes the final transaction record and links it to the order.
     */
    public function attachOrder($payment_intent_id, $id_order, $intent, array $details, $paid, $currency_iso)
    {
        Db::getInstance()->update(
            'googlepaystripe_payment',
            array(
                'id_order'          => (int)$id_order,
                'charge_id'         => pSQL($details['charge_id']),
                'payment_method_id' => pSQL($details['payment_method_id']),
                'card_brand'        => pSQL($details['card_brand']),
                'card_last4'        => pSQL($details['card_last4']),
                'card_funding'      => pSQL($details['card_funding']),
                'wallet_type'       => pSQL($details['wallet_type']),
                'status'            => pSQL($intent->status),
                'amount'            => (float)$paid,
                'currency'          => pSQL(Tools::strtoupper($currency_iso)),
                'live_mode'         => (int)(isset($intent->livemode) ? $intent->livemode : !$this->module->isTestMode()),
                'date_upd'          => pSQL(date('Y-m-d H:i:s')),
            ),
            '`payment_intent_id` = "'.pSQL($payment_intent_id).'"'
        );
    }

    /**
     * Records a payment attempt that did not become an order.
     */
    public function markStatus($payment_intent_id, $status)
    {
        Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'googlepaystripe_payment`
             SET `status` = "'.pSQL($status).'", `date_upd` = NOW()
             WHERE `payment_intent_id` = "'.pSQL($payment_intent_id).'"'
        );
    }
}
