<?php
/**
 * Creates (or refreshes) the Stripe PaymentIntent for the current cart.
 *
 * The browser never tells us how much to charge. The amount is always
 * recomputed here from the cart, so a tampered request cannot buy a cart for
 * one cent.
 */

class GooglePayStripeAjaxModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;

    public function postProcess()
    {
        $action = Tools::getValue('action');

        switch ($action) {
            case 'createIntent':
                $this->createIntent();
                break;
            default:
                $this->respond(array('error' => 'Unknown action.'), 400);
        }
    }

    protected function createIntent()
    {
        /** @var GooglePayStripe $module */
        $module = $this->module;

        if (!$module->isConfigured()) {
            $this->respond(array('error' => 'Payment module is not configured.'), 500);
        }

        $cart = $this->context->cart;

        if (!Validate::isLoadedObject($cart) || !$cart->id) {
            $this->respond(array('error' => 'No active cart.'), 400);
        }

        // Mirrors the guard PrestaShop itself applies before the payment step.
        if (!$cart->id_customer || !$cart->id_address_delivery || !$cart->id_address_invoice) {
            $this->respond(array('error' => 'The cart is not ready for payment yet.'), 400);
        }

        if (!$module->checkCurrency($cart)) {
            $this->respond(array('error' => 'This currency is not accepted by Google Pay on this shop.'), 400);
        }

        $customer = new Customer((int)$cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->respond(array('error' => 'Customer not found.'), 400);
        }

        $currency = new Currency((int)$cart->id_currency);
        $total = $module->getCartTotal($cart);

        if ($total <= 0) {
            $this->respond(array('error' => 'Nothing to pay.'), 400);
        }

        $amount = $module->toStripeAmount($total, $currency->iso_code);

        // Stripe rejects anything under roughly 0.50 EUR. Say so plainly rather
        // than surfacing a raw API error to the shopper.
        if ($amount < 50 && !in_array(Tools::strtoupper($currency->iso_code), GooglePayStripe::$zero_decimal_currencies)) {
            $this->respond(array('error' => 'The order total is below the minimum amount Stripe accepts.'), 400);
        }

        $metadata = array(
            'ps_cart_id'     => (int)$cart->id,
            'ps_customer_id' => (int)$cart->id_customer,
            'ps_shop_id'     => (int)$this->context->shop->id,
            'ps_secure_key'  => $customer->secure_key,
            'ps_shop_url'    => Tools::substr(Tools::getShopDomainSsl(true), 0, 480),
            'integration'    => 'googlepaystripe 1.0.0',
        );

        try {
            $stripe = $module->getStripeClient();
            $existing = $this->findReusableIntent($cart);

            if ($existing) {
                // Same shopper still on the payment page, but they changed a
                // voucher or carrier in another tab: keep one intent per cart
                // and just move the amount.
                $intent = $stripe->paymentIntents->update($existing['payment_intent_id'], array(
                    'amount'   => $amount,
                    'currency' => Tools::strtolower($currency->iso_code),
                    'metadata' => $metadata,
                ));
            } else {
                $intent = $stripe->paymentIntents->create(array(
                    'amount'               => $amount,
                    'currency'             => Tools::strtolower($currency->iso_code),
                    'payment_method_types' => array('card'),
                    'capture_method'       => Configuration::get('GPS_CAPTURE_METHOD') === 'manual' ? 'manual' : 'automatic',
                    'description'          => 'Cart #'.(int)$cart->id.' - '.$module->getShopName(),
                    'metadata'             => $metadata,
                ));
            }
        } catch (Exception $e) {
            $module->log('createIntent failed for cart '.(int)$cart->id.': '.$e->getMessage(), 3);
            $this->respond(array('error' => 'The payment provider refused the request.'), 502);

            return;
        }

        $this->storeIntent($cart, $intent, $currency->iso_code);

        $module->log('Intent '.$intent->id.' ready for cart '.(int)$cart->id.' ('.$amount.' '.$currency->iso_code.')');

        $this->respond(array(
            'clientSecret' => $intent->client_secret,
            'intentId'     => $intent->id,
            'amount'       => $amount,
            'currency'     => Tools::strtolower($currency->iso_code),
            'label'        => $module->getShopName(),
        ));
    }

    /**
     * Returns a stored intent for this cart that is still safe to reuse.
     * Anything already paid, cancelled or attached to an order is not.
     */
    protected function findReusableIntent(Cart $cart)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'googlepaystripe_payment`
             WHERE `id_cart` = '.(int)$cart->id.'
               AND `id_order` = 0
               AND `live_mode` = '.(int)(!$this->module->isTestMode()).'
               AND `status` IN ("requires_payment_method", "requires_confirmation", "requires_action")
             ORDER BY `id_googlepaystripe_payment` DESC'
        );

        return $row ? $row : null;
    }

    protected function storeIntent(Cart $cart, $intent, $currency_iso)
    {
        $now = date('Y-m-d H:i:s');
        $existing = $this->module->getPaymentRowByIntent($intent->id);

        $data = array(
            'id_cart'           => (int)$cart->id,
            'id_shop'           => (int)$this->context->shop->id,
            'payment_intent_id' => pSQL($intent->id),
            'status'            => pSQL($intent->status),
            'amount'            => (float)$this->module->fromStripeAmount($intent->amount, $currency_iso),
            'currency'          => pSQL(Tools::strtoupper($currency_iso)),
            'live_mode'         => (int)(isset($intent->livemode) ? $intent->livemode : !$this->module->isTestMode()),
            'date_upd'          => pSQL($now),
        );

        if ($existing) {
            Db::getInstance()->update(
                'googlepaystripe_payment',
                $data,
                '`id_googlepaystripe_payment` = '.(int)$existing['id_googlepaystripe_payment']
            );
        } else {
            $data['date_add'] = pSQL($now);
            Db::getInstance()->insert('googlepaystripe_payment', $data);
        }
    }

    /**
     * @param array $payload
     * @param int   $status
     */
    protected function respond(array $payload, $status = 200)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');

            if ($status !== 200) {
                header('HTTP/1.1 '.(int)$status.' '.($status === 400 ? 'Bad Request' : 'Error'));
            }
        }

        echo json_encode($payload);
        exit;
    }
}
