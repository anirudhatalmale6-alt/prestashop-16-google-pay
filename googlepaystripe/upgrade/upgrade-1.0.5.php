<?php
/**
 * 1.0.5 — one webhook signing secret per mode.
 *
 * Stripe issues a different signing secret for the test and live endpoints.
 * Before this version the module stored a single one, so switching to live
 * without swapping it left the endpoint rejecting every live event with a 400
 * that never surfaces anywhere in the shop.
 *
 * Modules are upgraded in place by re-uploading the zip, which does not re-run
 * install(), so the new settings are created here.
 *
 * The existing secret is deliberately NOT copied into either new field. It was
 * entered for whichever mode happened to be active at the time, and guessing
 * wrong would make a mismatched secret look deliberate and silence the warning
 * that is meant to catch exactly that. It stays where it is as a fallback, and
 * the status panel asks the merchant which mode it belongs to.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module)
{
    foreach (array('GPS_TEST_WEBHOOK_SECRET', 'GPS_LIVE_WEBHOOK_SECRET') as $key) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, '');
        }
    }

    return true;
}
