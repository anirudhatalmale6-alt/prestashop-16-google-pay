<?php
/**
 * 1.0.3 — support the advanced payment step.
 *
 * Shops running the Advanced EU Compliance module have PS_ADVANCED_PAYMENT_API
 * switched on. That payment step never calls the normal payment hook, so the
 * module has to be attached to advancedPaymentOptions as well. Modules are
 * upgraded in place by re-uploading the zip, which does not re-run install(),
 * so the hook is registered here instead.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module)
{
    // registerHook creates the hook row itself if the shop has never seen this
    // hook before, so this is safe on a shop without EU compliance installed.
    if (!$module->isRegisteredInHook('advancedPaymentOptions')) {
        $module->registerHook('advancedPaymentOptions');
    }

    return true;
}
