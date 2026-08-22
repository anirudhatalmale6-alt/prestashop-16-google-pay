<?php
/**
 * The browser lands here after the Google Pay sheet reports success.
 *
 * Nothing the browser sends is trusted: the intent is re-fetched from Stripe
 * and re-checked against the cart before any order is created.
 */

require_once _PS_MODULE_DIR_.'googlepaystripe/libs/PaymentProcessor.php';

class GooglePayStripeValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        /** @var GooglePayStripe $module */
        $module = $this->module;

        $intent_id = Tools::getValue('payment_intent');
        $cart = $this->context->cart;

        if (!$intent_id || !Validate::isLoadedObject($cart)) {
            $this->failTo(GooglePayStripe::ERR_NO_PAYMENT);

            return;
        }

        $customer = new Customer((int)$cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->failTo(GooglePayStripe::ERR_NO_CUSTOMER);

            return;
        }

        $processor = new GooglePayStripePaymentProcessor($module);

        // The customer may simply be reloading the confirmation page.
        $existing_order_id = (int)Order::getOrderByCartId((int)$cart->id);
        if ($existing_order_id) {
            $this->redirectToConfirmation($cart, $customer, $existing_order_id);

            return;
        }

        try {
            $stripe = $module->getStripeClient();
            // Expanding the charge saves a second API call on the happy path;
            // extractCardDetails() still fetches it separately if this ever
            // comes back as a bare id.
            $intent = $stripe->paymentIntents->retrieve($intent_id, array(
                'expand' => array('latest_charge'),
            ));
        } catch (Exception $e) {
            $module->log('Could not retrieve intent '.$intent_id.': '.$e->getMessage(), 3);
            $this->failTo(GooglePayStripe::ERR_UNREACHABLE);

            return;
        }

        $verdict = $processor->verifyIntent($intent, $cart, $customer);
        if ($verdict !== true) {
            $module->log('Rejected intent '.$intent_id.' for cart '.(int)$cart->id.': '.$verdict, 3);
            $processor->markStatus($intent_id, $intent->status);
            $this->failTo(GooglePayStripe::ERR_NOT_COMPLETED);

            return;
        }

        // Only one of {this request, the Stripe webhook} may build the order.
        if (!$processor->claimIntent($intent_id)) {
            // Someone else is mid-flight. Give them a moment, then show the
            // order they created rather than making a second one.
            $order_id = $this->waitForOrder((int)$cart->id);
            if ($order_id) {
                $this->redirectToConfirmation($cart, $customer, $order_id);
            } else {
                $this->failTo(GooglePayStripe::ERR_IN_PROGRESS);
            }

            return;
        }

        try {
            $id_order = $processor->createOrder($intent, $cart, $customer);
        } catch (Exception $e) {
            $processor->releaseIntent($intent_id);
            $module->log('Order creation failed for intent '.$intent_id.': '.$e->getMessage(), 4);
            $this->failTo(GooglePayStripe::ERR_ORDER_FAILED);

            return;
        }

        $this->redirectToConfirmation($cart, $customer, $id_order);
    }

    /**
     * Waits briefly for a concurrent process to finish creating the order.
     */
    protected function waitForOrder($id_cart)
    {
        for ($i = 0; $i < 10; $i++) {
            $id_order = (int)Order::getOrderByCartId((int)$id_cart);
            if ($id_order) {
                return $id_order;
            }
            usleep(300000);
        }

        return 0;
    }

    protected function redirectToConfirmation(Cart $cart, Customer $customer, $id_order)
    {
        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, array(
            'id_cart'   => (int)$cart->id,
            'id_module' => (int)$this->module->id,
            'id_order'  => (int)$id_order,
            'key'       => $customer->secure_key,
        )));
    }

    /**
     * Sends the shopper back to the payment step with a readable explanation
     * instead of a blank page.
     *
     * Only a numeric code travels in the URL. The wording lives in the template,
     * so nothing user-supplied is ever echoed back into the page.
     */
    protected function failTo($code)
    {
        $url = $this->context->link->getPageLink('order', true, null, array(
            'step'      => 3,
            'gps_error' => (int)$code,
        ));

        Tools::redirect($url);
    }
}
