<?php
/**
 * Google Pay for PrestaShop 1.6 (via Stripe)
 *
 * Adds a Google Pay button to the existing checkout flow. Card data never touches
 * this server: Google Pay returns an encrypted token, Stripe.js exchanges it for a
 * PaymentMethod, and only opaque identifiers ever reach PHP. That keeps the shop in
 * PCI DSS SAQ A scope.
 *
 * No PrestaShop core file is modified by this module.
 *
 * @author    Anirudha Talmale
 * @copyright 2026
 * @license   Commercial - delivered to the client for this project
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__).'/libs/StripeClientLoader.php';

class GooglePayStripe extends PaymentModule
{
    /**
     * Failure reasons shown back to the shopper. Only these integers ever
     * travel in a URL — never a message built from request data.
     */
    const ERR_NO_PAYMENT    = 1;
    const ERR_NO_CUSTOMER   = 2;
    const ERR_UNREACHABLE   = 3;
    const ERR_NOT_COMPLETED = 4;
    const ERR_IN_PROGRESS   = 5;
    const ERR_ORDER_FAILED  = 6;

    /** @var array Currencies Stripe settles in that have no minor unit (amounts are NOT multiplied by 100) */
    public static $zero_decimal_currencies = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    );

    /** @var array Config keys created on install and removed on uninstall */
    protected static $config_keys = array(
        'GPS_TEST_MODE'           => '1',
        'GPS_TEST_PUBLISHABLE'    => '',
        'GPS_TEST_SECRET'         => '',
        'GPS_LIVE_PUBLISHABLE'    => '',
        'GPS_LIVE_SECRET'         => '',
        'GPS_WEBHOOK_SECRET'      => '',
        'GPS_MERCHANT_COUNTRY'    => '',
        'GPS_BUTTON_TYPE'         => 'buy',
        'GPS_BUTTON_THEME'        => 'dark',
        'GPS_CAPTURE_METHOD'      => 'automatic',
        'GPS_TITLE'               => '',
        'GPS_DEBUG_LOG'           => '0',
    );

    /** @var string */
    protected $config_error = '';

    public function __construct()
    {
        $this->name = 'googlepaystripe';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.2';
        $this->author = 'Anirudha Talmale';
        // Must be 1 for a payment module. With 0, Module::getModulesOnDisk()
        // builds the object straight from config.xml, which carries no
        // currencies/currencies_mode, so AdminPayment renders "--" instead of
        // the currency checkboxes and the merchant cannot manage them at all.
        $this->need_instance = 1;
        $this->ps_versions_compliancy = array('min' => '1.6.0.0', 'max' => '1.6.99.99');
        $this->bootstrap = true;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->controllers = array('ajax', 'validation', 'webhook');

        parent::__construct();

        $this->displayName = $this->l('Google Pay (via Stripe)');
        $this->description = $this->l('Adds a Google Pay button to your checkout. Payments are processed by Stripe, so card data never reaches your server.');
        $this->confirmUninstall = $this->l('Are you sure? Your saved Stripe keys will be deleted. Existing orders are not affected.');

        if (!Configuration::get('GPS_TEST_PUBLISHABLE') && !Configuration::get('GPS_LIVE_PUBLISHABLE')) {
            $this->warning = $this->l('No Stripe API key set yet. Configure the module before using it.');
        }
    }

    /* ---------------------------------------------------------------------
     * Install / uninstall
     * ------------------------------------------------------------------ */

    public function install()
    {
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->l('The PHP cURL extension is required by the Stripe library but is not enabled on this server.');

            return false;
        }

        if (!extension_loaded('json') || !extension_loaded('mbstring')) {
            $this->_errors[] = $this->l('The PHP json and mbstring extensions are required by the Stripe library.');

            return false;
        }

        if (!parent::install()) {
            return false;
        }

        foreach (self::$config_keys as $key => $default) {
            Configuration::updateValue($key, $default);
        }

        // Default the Google Pay merchant country to the shop country.
        $country = new Country((int)Configuration::get('PS_COUNTRY_DEFAULT'));
        if (Validate::isLoadedObject($country)) {
            Configuration::updateValue('GPS_MERCHANT_COUNTRY', Tools::strtoupper($country->iso_code));
        }

        $hooks = array('payment', 'paymentReturn', 'header', 'displayOrderConfirmation', 'displayAdminOrder');
        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return $this->installDb();
    }

    public function uninstall()
    {
        foreach (array_keys(self::$config_keys) as $key) {
            Configuration::deleteByName($key);
        }

        // The transaction table is deliberately kept: it is the audit trail for
        // orders that have already been placed. Drop it by hand if you really
        // want it gone (see DEPLOYMENT.md).
        return parent::uninstall();
    }

    protected function installDb()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'googlepaystripe_payment` (
            `id_googlepaystripe_payment` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_cart` INT(11) UNSIGNED NOT NULL,
            `id_order` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
            `payment_intent_id` VARCHAR(64) NOT NULL,
            `charge_id` VARCHAR(64) NOT NULL DEFAULT \'\',
            `payment_method_id` VARCHAR(64) NOT NULL DEFAULT \'\',
            `status` VARCHAR(32) NOT NULL DEFAULT \'\',
            `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `currency` VARCHAR(3) NOT NULL DEFAULT \'\',
            `card_brand` VARCHAR(32) NOT NULL DEFAULT \'\',
            `card_last4` VARCHAR(4) NOT NULL DEFAULT \'\',
            `card_funding` VARCHAR(16) NOT NULL DEFAULT \'\',
            `wallet_type` VARCHAR(32) NOT NULL DEFAULT \'\',
            `live_mode` TINYINT(1) NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_googlepaystripe_payment`),
            UNIQUE KEY `payment_intent_id` (`payment_intent_id`),
            KEY `id_cart` (`id_cart`),
            KEY `id_order` (`id_order`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';

        return (bool)Db::getInstance()->execute($sql);
    }

    /* ---------------------------------------------------------------------
     * Configuration screen
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit'.$this->name)) {
            $output .= $this->postProcess();
        }

        $output .= $this->renderStatusPanel();

        return $output.$this->renderForm();
    }

    protected function postProcess()
    {
        $errors = array();

        $test_mode = (int)Tools::getValue('GPS_TEST_MODE');
        $test_pk = trim(Tools::getValue('GPS_TEST_PUBLISHABLE'));
        $test_sk = trim(Tools::getValue('GPS_TEST_SECRET'));
        $live_pk = trim(Tools::getValue('GPS_LIVE_PUBLISHABLE'));
        $live_sk = trim(Tools::getValue('GPS_LIVE_SECRET'));
        $country = Tools::strtoupper(trim(Tools::getValue('GPS_MERCHANT_COUNTRY')));

        // Catch the single most common configuration mistake: keys pasted into
        // the wrong pair of boxes. A live secret key in the test box would send
        // real money through a "test" checkout.
        if ($test_pk !== '' && strpos($test_pk, 'pk_test_') !== 0) {
            $errors[] = $this->l('The test publishable key must start with pk_test_.');
        }
        if ($test_sk !== '' && strpos($test_sk, 'sk_test_') !== 0 && strpos($test_sk, 'rk_test_') !== 0) {
            $errors[] = $this->l('The test secret key must start with sk_test_ (or rk_test_ for a restricted key).');
        }
        if ($live_pk !== '' && strpos($live_pk, 'pk_live_') !== 0) {
            $errors[] = $this->l('The live publishable key must start with pk_live_.');
        }
        if ($live_sk !== '' && strpos($live_sk, 'sk_live_') !== 0 && strpos($live_sk, 'rk_live_') !== 0) {
            $errors[] = $this->l('The live secret key must start with sk_live_ (or rk_live_ for a restricted key).');
        }
        if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
            $errors[] = $this->l('The merchant country must be a two letter ISO code, for example SI.');
        }

        // Refuse to switch to live mode with an empty key pair rather than
        // letting the button silently disappear from the storefront.
        if (!$test_mode && ($live_pk === '' || $live_sk === '')) {
            $errors[] = $this->l('You cannot switch to live mode until both live keys are filled in.');
        }
        if ($test_mode && ($test_pk === '' || $test_sk === '')) {
            $errors[] = $this->l('Fill in both test keys before saving in test mode.');
        }

        if (count($errors)) {
            return $this->displayError(implode('<br />', $errors));
        }

        Configuration::updateValue('GPS_TEST_MODE', $test_mode);
        Configuration::updateValue('GPS_TEST_PUBLISHABLE', $test_pk);
        Configuration::updateValue('GPS_TEST_SECRET', $test_sk);
        Configuration::updateValue('GPS_LIVE_PUBLISHABLE', $live_pk);
        Configuration::updateValue('GPS_LIVE_SECRET', $live_sk);
        Configuration::updateValue('GPS_WEBHOOK_SECRET', trim(Tools::getValue('GPS_WEBHOOK_SECRET')));
        Configuration::updateValue('GPS_MERCHANT_COUNTRY', $country);
        Configuration::updateValue('GPS_BUTTON_TYPE', Tools::getValue('GPS_BUTTON_TYPE'));
        Configuration::updateValue('GPS_BUTTON_THEME', Tools::getValue('GPS_BUTTON_THEME'));
        Configuration::updateValue('GPS_CAPTURE_METHOD', Tools::getValue('GPS_CAPTURE_METHOD'));
        Configuration::updateValue('GPS_DEBUG_LOG', (int)Tools::getValue('GPS_DEBUG_LOG'));
        Configuration::updateValue('GPS_TITLE', Tools::getValue('GPS_TITLE'), true);

        $output = $this->displayConfirmation($this->l('Settings saved.'));

        // Prove the secret key actually works instead of waiting for a customer
        // to discover it does not.
        $check = $this->testApiKey();
        if ($check === true) {
            $output .= $this->displayConfirmation($this->l('Stripe API key verified successfully.'));
        } else {
            $output .= $this->displayError($this->l('Stripe rejected the secret key: ').$check);
        }

        return $output;
    }

    /**
     * @return true|string true on success, error message on failure
     */
    protected function testApiKey()
    {
        $secret = $this->getSecretKey();
        if ($secret === '') {
            return $this->l('no secret key set.');
        }

        try {
            $stripe = $this->getStripeClient();
            $stripe->balance->retrieve();

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    protected function renderStatusPanel()
    {
        $checks = array();

        $ssl = (bool)Configuration::get('PS_SSL_ENABLED');
        $checks[] = array(
            'label' => $this->t('SSL enabled in PrestaShop'),
            'ok'    => $ssl,
            'hint'  => $this->t('Google Pay only loads on https. Enable SSL under Preferences > General.'),
        );

        $ssl_everywhere = (bool)Configuration::get('PS_SSL_ENABLED_EVERYWHERE');
        $checks[] = array(
            'label' => $this->t('SSL on all pages'),
            'ok'    => $ssl_everywhere,
            'hint'  => $this->t('Recommended. Without it the cart page may be served over http and the button will not appear.'),
        );

        $checks[] = array(
            'label' => $this->t('PHP cURL extension'),
            'ok'    => extension_loaded('curl'),
            'hint'  => $this->t('Required to talk to the Stripe API.'),
        );

        $checks[] = array(
            'label' => $this->t('Stripe keys saved'),
            'ok'    => $this->getSecretKey() !== '' && $this->getPublishableKey() !== '',
            'hint'  => $this->t('Both the publishable and secret key are needed for the mode you selected.'),
        );

        $checks[] = array(
            'label' => $this->t('Webhook signing secret'),
            // Configuration::get() returns false when unset, which is not '' —
            // compare truthiness or the check always passes.
            'ok'    => (bool)Configuration::get('GPS_WEBHOOK_SECRET'),
            'hint'  => $this->t('Optional but recommended. It lets Stripe confirm an order even if the customer closes the browser.'),
        );

        // These four are the filters PrestaShop itself applies in
        // Module::getPaymentModules(). If any one of them fails the module is
        // dropped from the checkout silently — no error anywhere — so they are
        // reported here rather than left for the merchant to guess at.
        foreach ($this->getVisibilityChecks() as $check) {
            $checks[] = $check;
        }

        $this->context->smarty->assign(array(
            'gps_checks'       => $checks,
            'gps_webhook_url'  => $this->context->link->getModuleLink($this->name, 'webhook', array(), true),
            'gps_test_mode'    => (bool)Configuration::get('GPS_TEST_MODE'),
            'gps_module_dir'   => $this->_path,
        ));

        return $this->display(__FILE__, 'views/templates/hook/admin_status.tpl');
    }

    /**
     * Records what the payment hook did the last time the checkout called it.
     * One Configuration row, overwritten each time, so the merchant can read it
     * in the back office without needing FTP or server log access.
     *
     * Returns null so it can be used directly in `return $this->noteHookState(...)`.
     */
    protected function noteHookState($state)
    {
        Configuration::updateValue('GPS_LAST_HOOK', date('Y-m-d H:i:s').' — '.$state);

        return null;
    }

    /**
     * Reproduces, one by one, the joins in Module::getPaymentModules() plus the
     * module's own currency test — the four ways a payment module can vanish
     * from the checkout without printing a single error.
     *
     * Written for the merchant, not for a developer: each failure says which
     * panel on this page fixes it.
     */
    protected function getVisibilityChecks()
    {
        $out = array();
        $id_module = (int)$this->id;
        $id_shop = (int)$this->context->shop->id;
        $id_lang = (int)$this->context->language->id;
        $db = Db::getInstance();

        if (!$id_module) {
            return $out;
        }

        // 1. Countries. PrestaShop matches the customer's INVOICE address
        //    country against this list.
        $country_ids = array();
        $rows = $db->executeS(
            'SELECT `id_country` FROM `'._DB_PREFIX_.'module_country`
             WHERE `id_module` = '.$id_module.' AND `id_shop` = '.$id_shop
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $country_ids[] = (int)$row['id_country'];
            }
        }

        $missing_countries = array();
        $active_countries = Country::getCountries($id_lang, true);
        if (is_array($active_countries)) {
            foreach ($active_countries as $country) {
                if (!in_array((int)$country['id_country'], $country_ids)) {
                    $missing_countries[] = $country['name'];
                }
            }
        }

        if (count($missing_countries)) {
            $hint = sprintf(
                $this->t('Not allowed for: %s. Customers with an invoice address there will not see the button. Fix it in Country restrictions further down this page.'),
                implode(', ', array_slice($missing_countries, 0, 12)).(count($missing_countries) > 12 ? '...' : '')
            );
        } else {
            $hint = $this->t('Every country you have enabled in the shop is allowed for this module.');
        }

        $out[] = array(
            'label' => $this->t('Allowed in every enabled country'),
            'ok'    => !count($missing_countries),
            'hint'  => $hint,
        );

        // 2. Customer groups. This one is an INNER JOIN in the core query, so a
        //    module with no group rows at all disappears for everybody.
        $group_ids = array();
        $rows = $db->executeS(
            'SELECT `id_group` FROM `'._DB_PREFIX_.'module_group`
             WHERE `id_module` = '.$id_module.' AND `id_shop` = '.$id_shop
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $group_ids[] = (int)$row['id_group'];
            }
        }

        $missing_groups = array();
        $all_groups = Group::getGroups($id_lang);
        if (is_array($all_groups)) {
            foreach ($all_groups as $group) {
                if (!in_array((int)$group['id_group'], $group_ids)) {
                    $missing_groups[] = $group['name'];
                }
            }
        }

        $out[] = array(
            'label' => $this->t('Allowed for every customer group'),
            'ok'    => count($group_ids) > 0 && !count($missing_groups),
            'hint'  => count($missing_groups)
                ? sprintf(
                    $this->t('Not allowed for: %s. Customers in those groups will not see the button. Fix it in Group restrictions further down this page.'),
                    implode(', ', $missing_groups)
                )
                : $this->t('Every customer group can use this payment method.'),
        );

        // 3. Currency. Checked by the module itself against the cart currency.
        $currency_isos = array();
        $rows = $db->executeS(
            'SELECT c.`iso_code` FROM `'._DB_PREFIX_.'module_currency` mc
             INNER JOIN `'._DB_PREFIX_.'currency` c ON c.`id_currency` = mc.`id_currency`
             WHERE mc.`id_module` = '.$id_module.' AND mc.`id_shop` = '.$id_shop
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $currency_isos[] = $row['iso_code'];
            }
        }

        $default_currency = new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
        $default_iso = Validate::isLoadedObject($default_currency) ? $default_currency->iso_code : '';
        $currency_ok = $default_iso !== '' && in_array($default_iso, $currency_isos);

        $out[] = array(
            'label' => sprintf($this->t('Shop currency (%s) enabled for this module'), $default_iso),
            'ok'    => $currency_ok,
            'hint'  => $currency_ok
                ? sprintf($this->t('Enabled for: %s.'), implode(', ', $currency_isos))
                : $this->t('The cart currency must be enabled in Currency restrictions further down this page, or the button stays hidden.'),
        );

        // 4. The payment hook itself. Without this row the checkout never even
        //    asks the module for anything.
        $hook_ok = (bool)$db->getValue(
            'SELECT hm.`id_module` FROM `'._DB_PREFIX_.'hook_module` hm
             INNER JOIN `'._DB_PREFIX_.'hook` h ON h.`id_hook` = hm.`id_hook`
             WHERE hm.`id_module` = '.$id_module.' AND hm.`id_shop` = '.$id_shop.'
               AND h.`name` IN ("displayPayment", "payment")'
        );

        $out[] = array(
            'label' => $this->t('Registered on the checkout payment hook'),
            'ok'    => $hook_ok,
            'hint'  => $hook_ok
                ? $this->t('The checkout will ask this module for its payment option.')
                : $this->t('Reinstall the module — it is not attached to the payment step.'),
        );

        // 5. Enabled. Installed but disabled is a perfectly normal state and
        //    looks identical from the checkout: nothing appears.
        $out[] = array(
            'label' => $this->t('Module enabled'),
            'ok'    => (bool)$this->active,
            'hint'  => $this->active
                ? $this->t('The module is switched on.')
                : $this->t('The module is installed but switched off. Enable it in Modules and Services.'),
        );

        // 6. Attached to this shop. Missing here and the core checkout query
        //    drops the module even though everything else looks right.
        $shop_ok = (bool)$db->getValue(
            'SELECT `id_module` FROM `'._DB_PREFIX_.'module_shop`
             WHERE `id_module` = '.$id_module.' AND `id_shop` = '.$id_shop
        );
        $out[] = array(
            'label' => $this->t('Available in this shop'),
            'ok'    => $shop_ok,
            'hint'  => $shop_ok
                ? $this->t('The module is attached to this shop.')
                : $this->t('The module is not attached to this shop. Reinstall it.'),
        );

        // 7. The advanced payment API replaces the normal payment hook with a
        //    different one. A module that only implements the normal hook — like
        //    this one, and like the stock bank wire module — never appears.
        $advanced_api = (bool)Configuration::get('PS_ADVANCED_PAYMENT_API');
        $out[] = array(
            'label' => $this->t('Standard payment step (advanced payment API off)'),
            'ok'    => !$advanced_api,
            'hint'  => $advanced_api
                ? $this->t('The advanced payment API is on. It uses a different hook, so this module cannot appear. Turn it off, or tell me and I will add support for it.')
                : $this->t('Your checkout uses the standard payment step.'),
        );

        // 8. Devices. PrestaShop keeps a per-device bitmask on the module and ANDs
        //    it against the shopper's device: 1 desktop, 2 tablet, 4 mobile. Miss
        //    a bit and the module is invisible on that device only, which looks
        //    like a browser problem rather than a setting.
        $device_mask = (int)$db->getValue(
            'SELECT `enable_device` FROM `'._DB_PREFIX_.'module_shop`
             WHERE `id_module` = '.$id_module.' AND `id_shop` = '.$id_shop
        );
        $device_names = array(1 => $this->t('desktop'), 2 => $this->t('tablet'), 4 => $this->t('mobile'));
        $missing_devices = array();
        foreach ($device_names as $bit => $device_name) {
            if (!($device_mask & $bit)) {
                $missing_devices[] = $device_name;
            }
        }
        $out[] = array(
            'label' => $this->t('Enabled on desktop, tablet and mobile'),
            'ok'    => !count($missing_devices),
            'hint'  => count($missing_devices)
                ? sprintf(
                    $this->t('Hidden on: %s. Google Pay matters most on mobile, so this one is worth fixing.'),
                    implode(', ', $missing_devices)
                )
                : $this->t('Visible on every device.'),
        );

        // 9. The whole thing at once. This mirrors Hook::getHookModuleExecList(),
        //    which is what actually decides whether the payment step ever calls
        //    this module — NOT Module::getPaymentModules(), which is a different
        //    query with a different set of filters.
        //    Currency is part of THIS one: an unticked currency stops the hook
        //    being called at all, so the module never gets to explain itself and
        //    "Last checkout result" below stays frozen on an older run.
        // Already cast to int when they were read, so safe to interpolate.
        $group_sql = count($group_ids) ? implode(', ', array_map('intval', $group_ids)) : '0';
        $id_country_ctx = (int)Configuration::get('PS_COUNTRY_DEFAULT');
        $id_currency_ctx = (int)Configuration::get('PS_CURRENCY_DEFAULT');

        $survives = (bool)$db->getValue(
            'SELECT m.`id_module`
             FROM `'._DB_PREFIX_.'module` m
             INNER JOIN `'._DB_PREFIX_.'module_shop` ms
               ON (ms.`id_module` = m.`id_module` AND ms.`id_shop` = '.$id_shop.')
             INNER JOIN `'._DB_PREFIX_.'hook_module` hm ON hm.`id_module` = m.`id_module`
             INNER JOIN `'._DB_PREFIX_.'hook` h ON hm.`id_hook` = h.`id_hook`
             LEFT JOIN `'._DB_PREFIX_.'module_group` mg
               ON (mg.`id_module` = m.`id_module` AND mg.`id_shop` = '.$id_shop.')
             WHERE m.`id_module` = '.$id_module.'
               AND h.`name` = "displayPayment"
               AND hm.`id_shop` = '.$id_shop.'
               AND (SELECT `id_country` FROM `'._DB_PREFIX_.'module_country` mc
                    WHERE mc.`id_module` = m.`id_module` AND mc.`id_country` = '.$id_country_ctx.'
                      AND mc.`id_shop` = '.$id_shop.' LIMIT 1) = '.$id_country_ctx.'
               AND (SELECT `id_currency` FROM `'._DB_PREFIX_.'module_currency` mcr
                    WHERE mcr.`id_module` = m.`id_module`
                      AND mcr.`id_currency` IN ('.$id_currency_ctx.', -1, -2) LIMIT 1)
                   IN ('.$id_currency_ctx.', -1, -2)
               AND mg.`id_group` IN ('.$group_sql.')'
        );

        $out[] = array(
            'label' => $this->t('PrestaShop will call this module at the payment step'),
            'ok'    => $survives,
            'hint'  => $survives
                ? $this->t('For your default country and currency, the checkout will ask this module for its button.')
                : $this->t('PrestaShop filters this module out before asking it for anything. One of the checks above is the reason: country, currency, group, shop or hook.'),
        );

        // 9. What actually happened last time a customer reached the payment
        //    step. This is the one that ends the guessing.
        $last = Configuration::get('GPS_LAST_HOOK');
        $out[] = array(
            'label'  => $this->t('Last checkout result'),
            'ok'     => $last ? (Tools::strpos($last, 'shown:') !== false) : false,
            // Always shown: this line is the diagnosis, not a warning.
            'always' => true,
            'hint'   => $last
                ? $last
                : $this->t('The payment step has not called this module even once yet. Load the checkout payment step, then reload this page.'),
        );

        return $out;
    }

    protected function renderForm()
    {
        $fields_form = array();

        $fields_form[0]['form'] = array(
            'legend' => array('title' => $this->l('Mode'), 'icon' => 'icon-cogs'),
            'input'  => array(
                array(
                    'type'    => 'switch',
                    'label'   => $this->l('Test mode'),
                    'name'    => 'GPS_TEST_MODE',
                    'desc'    => $this->l('On = Stripe test keys, no real money moves. Turn this off only when you are ready to take live payments.'),
                    'is_bool' => true,
                    'values'  => array(
                        array('id' => 'test_on', 'value' => 1, 'label' => $this->l('Yes')),
                        array('id' => 'test_off', 'value' => 0, 'label' => $this->l('No')),
                    ),
                ),
            ),
            'submit' => array('title' => $this->l('Save')),
        );

        $fields_form[1]['form'] = array(
            'legend' => array('title' => $this->l('Stripe API keys'), 'icon' => 'icon-key'),
            'description' => $this->l('Find these in your Stripe dashboard under Developers > API keys.'),
            'input'  => array(
                array(
                    'type'  => 'text',
                    'label' => $this->l('Test publishable key'),
                    'name'  => 'GPS_TEST_PUBLISHABLE',
                    'desc'  => $this->l('Starts with pk_test_'),
                ),
                array(
                    'type'  => 'text',
                    'label' => $this->l('Test secret key'),
                    'name'  => 'GPS_TEST_SECRET',
                    'desc'  => $this->l('Starts with sk_test_'),
                ),
                array(
                    'type'  => 'text',
                    'label' => $this->l('Live publishable key'),
                    'name'  => 'GPS_LIVE_PUBLISHABLE',
                    'desc'  => $this->l('Starts with pk_live_'),
                ),
                array(
                    'type'  => 'text',
                    'label' => $this->l('Live secret key'),
                    'name'  => 'GPS_LIVE_SECRET',
                    'desc'  => $this->l('Starts with sk_live_'),
                ),
                array(
                    'type'  => 'text',
                    'label' => $this->l('Webhook signing secret'),
                    'name'  => 'GPS_WEBHOOK_SECRET',
                    'desc'  => $this->l('Starts with whsec_. Create the endpoint in Stripe first, then paste the secret here.'),
                ),
            ),
            'submit' => array('title' => $this->l('Save')),
        );

        $fields_form[2]['form'] = array(
            'legend' => array('title' => $this->l('Checkout button'), 'icon' => 'icon-credit-card'),
            'input'  => array(
                array(
                    'type'  => 'text',
                    'label' => $this->l('Payment method title'),
                    'name'  => 'GPS_TITLE',
                    'lang'  => true,
                    'desc'  => $this->l('Shown above the button at checkout. Leave empty to use "Pay with Google Pay".'),
                ),
                array(
                    'type'    => 'select',
                    'label'   => $this->l('Button label'),
                    'name'    => 'GPS_BUTTON_TYPE',
                    'options' => array(
                        'query' => array(
                            array('id' => 'buy', 'name' => $this->l('Buy with Google Pay')),
                            array('id' => 'default', 'name' => $this->l('Google Pay')),
                            array('id' => 'donate', 'name' => $this->l('Donate with Google Pay')),
                        ),
                        'id'   => 'id',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type'    => 'select',
                    'label'   => $this->l('Button theme'),
                    'name'    => 'GPS_BUTTON_THEME',
                    'options' => array(
                        'query' => array(
                            array('id' => 'dark', 'name' => $this->l('Dark')),
                            array('id' => 'light', 'name' => $this->l('Light')),
                            array('id' => 'light-outline', 'name' => $this->l('Light with outline')),
                        ),
                        'id'   => 'id',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type'  => 'text',
                    'label' => $this->l('Merchant country code'),
                    'name'  => 'GPS_MERCHANT_COUNTRY',
                    'desc'  => $this->l('Two letter ISO code of the country your Stripe account is registered in, for example SI.'),
                ),
                array(
                    'type'    => 'select',
                    'label'   => $this->l('Capture'),
                    'name'    => 'GPS_CAPTURE_METHOD',
                    'desc'    => $this->l('Immediate charges the card right away. Authorise only holds the funds so you can capture later from the Stripe dashboard.'),
                    'options' => array(
                        'query' => array(
                            array('id' => 'automatic', 'name' => $this->l('Charge immediately')),
                            array('id' => 'manual', 'name' => $this->l('Authorise only')),
                        ),
                        'id'   => 'id',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type'    => 'switch',
                    'label'   => $this->l('Debug log'),
                    'name'    => 'GPS_DEBUG_LOG',
                    'desc'    => $this->l('Writes payment steps to the PrestaShop log (Advanced Parameters > Logs). Turn off once live.'),
                    'is_bool' => true,
                    'values'  => array(
                        array('id' => 'dbg_on', 'value' => 1, 'label' => $this->l('Yes')),
                        array('id' => 'dbg_off', 'value' => 0, 'label' => $this->l('No')),
                    ),
                ),
            ),
            'submit' => array('title' => $this->l('Save')),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit'.$this->name;
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        );

        return $helper->generateForm($fields_form);
    }

    protected function getConfigFieldsValues()
    {
        $languages = Language::getLanguages(false);
        $title = array();
        foreach ($languages as $lang) {
            $title[$lang['id_lang']] = Tools::getValue(
                'GPS_TITLE_'.$lang['id_lang'],
                Configuration::get('GPS_TITLE', $lang['id_lang'])
            );
        }

        return array(
            'GPS_TEST_MODE'        => Tools::getValue('GPS_TEST_MODE', Configuration::get('GPS_TEST_MODE')),
            'GPS_TEST_PUBLISHABLE' => Tools::getValue('GPS_TEST_PUBLISHABLE', Configuration::get('GPS_TEST_PUBLISHABLE')),
            'GPS_TEST_SECRET'      => Tools::getValue('GPS_TEST_SECRET', Configuration::get('GPS_TEST_SECRET')),
            'GPS_LIVE_PUBLISHABLE' => Tools::getValue('GPS_LIVE_PUBLISHABLE', Configuration::get('GPS_LIVE_PUBLISHABLE')),
            'GPS_LIVE_SECRET'      => Tools::getValue('GPS_LIVE_SECRET', Configuration::get('GPS_LIVE_SECRET')),
            'GPS_WEBHOOK_SECRET'   => Tools::getValue('GPS_WEBHOOK_SECRET', Configuration::get('GPS_WEBHOOK_SECRET')),
            'GPS_MERCHANT_COUNTRY' => Tools::getValue('GPS_MERCHANT_COUNTRY', Configuration::get('GPS_MERCHANT_COUNTRY')),
            'GPS_BUTTON_TYPE'      => Tools::getValue('GPS_BUTTON_TYPE', Configuration::get('GPS_BUTTON_TYPE')),
            'GPS_BUTTON_THEME'     => Tools::getValue('GPS_BUTTON_THEME', Configuration::get('GPS_BUTTON_THEME')),
            'GPS_CAPTURE_METHOD'   => Tools::getValue('GPS_CAPTURE_METHOD', Configuration::get('GPS_CAPTURE_METHOD')),
            'GPS_DEBUG_LOG'        => Tools::getValue('GPS_DEBUG_LOG', Configuration::get('GPS_DEBUG_LOG')),
            'GPS_TITLE'            => $title,
        );
    }

    /* ---------------------------------------------------------------------
     * Front office hooks
     * ------------------------------------------------------------------ */

    public function hookHeader($params)
    {
        if (!$this->active) {
            return;
        }

        $page = Tools::strtolower($this->context->controller->php_self);
        if (!in_array($page, array('order', 'order-opc'))) {
            return;
        }

        $this->context->controller->addCSS($this->_path.'views/css/googlepay.css', 'all');
        $this->context->controller->addJS($this->_path.'views/js/googlepay.js');
    }

    public function hookPayment($params)
    {
        // Every exit below records why, so the configuration page can say what
        // happened on the last real checkout instead of the merchant and I
        // trading screenshots. If the recorded time never moves, the hook was
        // never called at all and the cause is upstream, in PrestaShop's own
        // filtering — which is a different problem entirely.
        if (!$this->active) {
            return $this->noteHookState('skipped: the module is disabled');
        }

        if (!$this->isConfigured()) {
            return $this->noteHookState(
                'skipped: no Stripe '.($this->isTestMode() ? 'test' : 'live').' keys saved for the selected mode'
            );
        }

        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            return $this->noteHookState('skipped: no cart in the customer session');
        }

        if (!$this->checkCurrency($cart)) {
            $cart_currency = new Currency((int)$cart->id_currency);
            return $this->noteHookState(sprintf(
                'skipped: cart currency %s is not enabled for this module in Currency restrictions',
                Validate::isLoadedObject($cart_currency) ? $cart_currency->iso_code : (int)$cart->id_currency
            ));
        }

        // Google Pay is a card wallet: it cannot pay for a zero total, and a
        // cart still missing an address or carrier is not payable yet.
        $total = $this->getCartTotal($cart);
        if ($total <= 0) {
            return $this->noteHookState('skipped: cart total is '.(float)$total);
        }

        $this->noteHookState('shown: button block rendered, cart total '.(float)$total);

        $currency = new Currency((int)$cart->id_currency);
        $title = Configuration::get('GPS_TITLE', (int)$this->context->language->id);
        if (!$title) {
            $title = $this->t('Pay with Google Pay');
        }

        Media::addJsDef(array(
            'gpsConfig' => array(
                'publishableKey' => $this->getPublishableKey(),
                'country'        => $this->getMerchantCountry(),
                'currency'       => Tools::strtolower($currency->iso_code),
                'amount'         => $this->toStripeAmount($total, $currency->iso_code),
                'label'          => $this->getShopName(),
                'buttonType'     => Configuration::get('GPS_BUTTON_TYPE'),
                'buttonTheme'    => Configuration::get('GPS_BUTTON_THEME'),
                'intentUrl'      => $this->context->link->getModuleLink($this->name, 'ajax', array('action' => 'createIntent'), true),
                'validationUrl'  => $this->context->link->getModuleLink($this->name, 'validation', array(), true),
                'idCart'         => (int)$cart->id,
                'testMode'       => (bool)Configuration::get('GPS_TEST_MODE'),
                // t() not l(): these are written into the DOM with textContent,
                // so they must be plain text, not HTML-escaped entities.
                'i18n'           => array(
                    'generic'    => $this->t('The payment could not be completed. Please try again or choose another payment method.'),
                    'network'    => $this->t('We could not reach the payment server. Please check your connection and try again.'),
                    'processing' => $this->t('Processing your payment, please do not close this page...'),
                ),
            ),
        ));

        $this->context->smarty->assign(array(
            'gps_title'       => $title,
            'gps_test_mode'   => (bool)Configuration::get('GPS_TEST_MODE'),
            'gps_module_dir'  => $this->_path,
            'gps_error'       => $this->getReturnedError(),
        ));

        return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $order = isset($params['objOrder']) ? $params['objOrder'] : null;
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $row = $this->getPaymentRowByOrder((int)$order->id);

        $this->context->smarty->assign(array(
            'gps_order_reference' => $order->reference,
            'gps_payment'         => $row,
            'gps_total_paid'      => Tools::displayPrice($order->total_paid, (int)$order->id_currency),
            'gps_module_dir'      => $this->_path,
        ));

        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }

    /**
     * Shows the Stripe reference on the order page in the back office, so the
     * merchant can jump straight to the transaction in their Stripe dashboard.
     */
    public function hookDisplayAdminOrder($params)
    {
        if (!$this->active || !isset($params['id_order'])) {
            return;
        }

        $row = $this->getPaymentRowByOrder((int)$params['id_order']);
        if (!$row) {
            return;
        }

        $base = $row['live_mode'] ? 'https://dashboard.stripe.com/payments/' : 'https://dashboard.stripe.com/test/payments/';

        $this->context->smarty->assign(array(
            'gps_payment'       => $row,
            'gps_dashboard_url' => $base.$row['payment_intent_id'],
        ));

        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    /* ---------------------------------------------------------------------
     * Helpers shared with the front controllers
     * ------------------------------------------------------------------ */

    /**
     * Turns the gps_error code from a failed return trip into wording the
     * shopper can act on. Unknown codes yield nothing rather than a stray box.
     *
     * @return string
     */
    public function getReturnedError()
    {
        $code = (int)Tools::getValue('gps_error');
        if (!$code) {
            return '';
        }

        switch ($code) {
            case self::ERR_NO_PAYMENT:
                return $this->t('There was no payment to confirm. Please try again.');
            case self::ERR_NO_CUSTOMER:
                return $this->t('We could not identify your account. Please sign in again.');
            case self::ERR_UNREACHABLE:
                return $this->t('We could not confirm your payment with the provider. Please contact us before trying again so you are not charged twice.');
            case self::ERR_NOT_COMPLETED:
                return $this->t('Your payment was not completed. You have not been charged.');
            case self::ERR_IN_PROGRESS:
                return $this->t('Your payment went through and your order is being finalised. Please check your order history in a moment before paying again.');
            case self::ERR_ORDER_FAILED:
                return $this->t('Your payment was taken but we could not finalise the order. Please contact us and we will sort it out straight away.');
            default:
                return '';
        }
    }

    public function isConfigured()
    {
        return $this->getPublishableKey() !== '' && $this->getSecretKey() !== '';
    }

    public function isTestMode()
    {
        return (bool)Configuration::get('GPS_TEST_MODE');
    }

    public function getPublishableKey()
    {
        $key = $this->isTestMode()
            ? Configuration::get('GPS_TEST_PUBLISHABLE')
            : Configuration::get('GPS_LIVE_PUBLISHABLE');

        return is_string($key) ? trim($key) : '';
    }

    public function getSecretKey()
    {
        $key = $this->isTestMode()
            ? Configuration::get('GPS_TEST_SECRET')
            : Configuration::get('GPS_LIVE_SECRET');

        return is_string($key) ? trim($key) : '';
    }

    public function getMerchantCountry()
    {
        $iso = Configuration::get('GPS_MERCHANT_COUNTRY');
        if (!$iso) {
            $country = new Country((int)Configuration::get('PS_COUNTRY_DEFAULT'));
            $iso = Validate::isLoadedObject($country) ? $country->iso_code : 'US';
        }

        return Tools::strtoupper($iso);
    }

    public function getShopName()
    {
        $name = Configuration::get('PS_SHOP_NAME');

        return $name ? $name : 'Order';
    }

    /**
     * @return \Stripe\StripeClient
     */
    public function getStripeClient()
    {
        GooglePayStripeClientLoader::load();

        return new \Stripe\StripeClient(array(
            'api_key' => $this->getSecretKey(),
        ));
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency((int)$cart->id_currency);
        $currencies_module = $this->getCurrency((int)$cart->id_currency);

        if (!is_array($currencies_module)) {
            return false;
        }

        foreach ($currencies_module as $currency_module) {
            if ($currency_order->id == $currency_module['id_currency']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one number the whole payment hangs on. Always recomputed server side.
     */
    public function getCartTotal(Cart $cart)
    {
        return (float)Tools::ps_round((float)$cart->getOrderTotal(true, Cart::BOTH), 2);
    }

    /**
     * PrestaShop stores 12.34; Stripe wants 1234 (or 1234 unchanged for JPY etc).
     */
    public function toStripeAmount($amount, $currency_iso)
    {
        if (in_array(Tools::strtoupper($currency_iso), self::$zero_decimal_currencies)) {
            return (int)round((float)$amount);
        }

        return (int)round((float)$amount * 100);
    }

    public function fromStripeAmount($amount, $currency_iso)
    {
        if (in_array(Tools::strtoupper($currency_iso), self::$zero_decimal_currencies)) {
            return (float)$amount;
        }

        return (float)$amount / 100;
    }

    public function getPaymentRowByOrder($id_order)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'googlepaystripe_payment`
             WHERE `id_order` = '.(int)$id_order.' ORDER BY `id_googlepaystripe_payment` DESC'
        );
    }

    public function getPaymentRowByIntent($payment_intent_id)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'googlepaystripe_payment`
             WHERE `payment_intent_id` = "'.pSQL($payment_intent_id).'"'
        );
    }

    /**
     * Translated text for handing to a template.
     *
     * PrestaShop 1.6's Translate::getModuleTranslation() already runs
     * htmlspecialchars() on whatever it returns, so a template that escapes the
     * value again renders "&gt;" as literal text. Decoding here means the
     * template can keep escaping exactly once, which is the safe default.
     *
     * @param string $string
     * @return string
     */
    public function t($string)
    {
        return html_entity_decode($this->l($string), ENT_QUOTES, 'UTF-8');
    }

    public function log($message, $severity = 1)
    {
        if (!Configuration::get('GPS_DEBUG_LOG') && $severity < 3) {
            return;
        }

        PrestaShopLogger::addLog('[googlepaystripe] '.$message, $severity, null, 'GooglePayStripe', 0, true);
    }
}
