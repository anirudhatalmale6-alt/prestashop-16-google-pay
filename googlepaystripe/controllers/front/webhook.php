<?php
/**
 * Stripe server-to-server webhook.
 *
 * This is the safety net. If the shopper's browser dies between Google Pay
 * saying "paid" and PrestaShop creating the order — closed laptop, lost signal,
 * crashed tab — Stripe still tells us here, and the order gets created anyway.
 *
 * Endpoint URL (give this to Stripe):
 *   https://your-shop/module/googlepaystripe/webhook
 *
 * Events to subscribe to:
 *   payment_intent.succeeded
 *   payment_intent.amount_capturable_updated
 *   payment_intent.payment_failed
 */

require_once _PS_MODULE_DIR_.'googlepaystripe/libs/PaymentProcessor.php';

class GooglePayStripeWebhookModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;

    /** Stripe retries on any non-2xx, so we answer 200 for anything we have decided to ignore. */
    public function postProcess()
    {
        /** @var GooglePayStripe $module */
        $module = $this->module;

        // Read the raw body directly — Tools::file_get_contents() routes through
        // cURL for anything URL-shaped and would not give us the request body.
        $payload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
        $secret = Configuration::get('GPS_WEBHOOK_SECRET');

        if (!$payload) {
            $this->finish(400, 'Empty payload.');

            return;
        }

        // Without a signing secret we cannot tell Stripe apart from anyone else
        // who found the URL, so we refuse rather than trust the body.
        if (!$secret) {
            $module->log('Webhook called but no signing secret is configured — ignoring.', 3);
            $this->finish(400, 'Webhook secret not configured.');

            return;
        }

        GooglePayStripeClientLoader::load();

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $module->log('Webhook signature rejected: '.$e->getMessage(), 3);
            $this->finish(400, 'Invalid signature.');

            return;
        } catch (Exception $e) {
            $module->log('Webhook could not be parsed: '.$e->getMessage(), 3);
            $this->finish(400, 'Invalid payload.');

            return;
        }

        // A live endpoint must not act on test traffic, or vice versa.
        $expected_live = !$module->isTestMode();
        if (isset($event->livemode) && (bool)$event->livemode !== $expected_live) {
            $this->finish(200, 'Ignored: mode mismatch.');

            return;
        }

        $intent = isset($event->data->object) ? $event->data->object : null;
        if (!$intent || !isset($intent->id)) {
            $this->finish(200, 'Ignored: no object.');

            return;
        }

        switch ($event->type) {
            case 'payment_intent.succeeded':
            case 'payment_intent.amount_capturable_updated':
                $this->handlePaid($intent);
                break;

            case 'payment_intent.payment_failed':
                $processor = new GooglePayStripePaymentProcessor($module);
                $processor->markStatus($intent->id, 'payment_failed');
                $module->log('Intent '.$intent->id.' reported as failed by webhook.');
                $this->finish(200, 'Recorded.');
                break;

            default:
                $this->finish(200, 'Ignored: '.$event->type);
        }
    }

    protected function handlePaid($intent)
    {
        /** @var GooglePayStripe $module */
        $module = $this->module;
        $processor = new GooglePayStripePaymentProcessor($module);

        if (!isset($intent->metadata) || !isset($intent->metadata['ps_cart_id'])) {
            $this->finish(200, 'Ignored: not one of ours.');

            return;
        }

        $id_cart = (int)$intent->metadata['ps_cart_id'];
        $cart = new Cart($id_cart);

        if (!Validate::isLoadedObject($cart)) {
            $module->log('Webhook references unknown cart '.$id_cart.' (intent '.$intent->id.')', 3);
            $this->finish(200, 'Ignored: unknown cart.');

            return;
        }

        if ((int)Order::getOrderByCartId($id_cart)) {
            $processor->markStatus($intent->id, $intent->status);
            $this->finish(200, 'Already ordered.');

            return;
        }

        $customer = new Customer((int)$cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->finish(200, 'Ignored: unknown customer.');

            return;
        }

        $verdict = $processor->verifyIntent($intent, $cart, $customer);
        if ($verdict !== true) {
            $module->log('Webhook rejected intent '.$intent->id.': '.$verdict, 3);
            $this->finish(200, 'Ignored: '.$verdict);

            return;
        }

        if (!$processor->claimIntent($intent->id)) {
            // The browser is already finishing this one.
            $this->finish(200, 'Already being processed.');

            return;
        }

        // validateOrder() reads the shop, currency, language and customer from
        // the context. In a webhook there is no browsing session, so rebuild it
        // from the cart before creating anything.
        $this->rebuildContext($cart, $customer);

        try {
            $id_order = $processor->createOrder($intent, $cart, $customer);
            $module->log('Webhook created order '.$id_order.' for cart '.$id_cart);
            $this->finish(200, 'Order created.');
        } catch (Exception $e) {
            $processor->releaseIntent($intent->id);
            $module->log('Webhook failed to create order for cart '.$id_cart.': '.$e->getMessage(), 4);

            // A 500 makes Stripe retry, which is what we want here.
            $this->finish(500, 'Order creation failed.');
        }
    }

    protected function rebuildContext(Cart $cart, Customer $customer)
    {
        $this->context->cart = $cart;
        $this->context->customer = $customer;

        $currency = new Currency((int)$cart->id_currency);
        if (Validate::isLoadedObject($currency)) {
            $this->context->currency = $currency;
        }

        $language = new Language((int)$cart->id_lang);
        if (Validate::isLoadedObject($language)) {
            $this->context->language = $language;
        }

        $shop = new Shop((int)$cart->id_shop);
        if (Validate::isLoadedObject($shop)) {
            $this->context->shop = $shop;
            Shop::setContext(Shop::CONTEXT_SHOP, (int)$cart->id_shop);
        }

        $this->context->country = new Country((int)Configuration::get('PS_COUNTRY_DEFAULT'));
        $address = new Address((int)$cart->id_address_invoice);
        if (Validate::isLoadedObject($address)) {
            $country = new Country((int)$address->id_country);
            if (Validate::isLoadedObject($country)) {
                $this->context->country = $country;
            }
        }
    }

    protected function finish($status, $message)
    {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            if ((int)$status !== 200) {
                header('HTTP/1.1 '.(int)$status.' Webhook Error');
            }
        }

        echo $message;
        exit;
    }
}
