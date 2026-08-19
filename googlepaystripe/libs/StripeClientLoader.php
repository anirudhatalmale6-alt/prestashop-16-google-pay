<?php
/**
 * Loads the bundled Stripe PHP library.
 *
 * The library is bundled inside the module rather than installed globally so
 * that (a) no other module's copy of stripe-php can win the autoloader race and
 * break us, and (b) a PrestaShop or theme upgrade cannot remove it.
 *
 * Version pinned: stripe-php 7.128.0 — the last 7.x line, which supports
 * PHP 5.6 through 7.4. The shop runs PHP 7.2, so the current 1x.x releases
 * (which assume PHP 8 in places) are deliberately NOT used here.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class GooglePayStripeClientLoader
{
    /** @var bool */
    protected static $loaded = false;

    public static function load()
    {
        if (self::$loaded) {
            return;
        }

        // If another module already pulled in a Stripe library, do not load a
        // second, conflicting copy — just use whatever is already there.
        if (class_exists('\Stripe\Stripe', false)) {
            self::$loaded = true;
            self::identify();

            return;
        }

        require_once dirname(__FILE__).'/stripe-php/init.php';

        self::$loaded = true;
        self::identify();
    }

    /**
     * Tags our API calls so they are identifiable in the Stripe dashboard logs.
     */
    protected static function identify()
    {
        if (!class_exists('\Stripe\Stripe')) {
            return;
        }

        \Stripe\Stripe::setAppInfo(
            'PrestaShop 1.6 Google Pay',
            '1.0.0',
            'https://www.prestashop.com/'
        );
    }
}
