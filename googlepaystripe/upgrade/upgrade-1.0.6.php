<?php
/**
 * 1.0.6 — the module may no longer hide anything it did not draw itself.
 *
 * When the shopper's browser cannot do Google Pay the option withdraws itself
 * from the payment step. Until now it did that by hiding the grid column its
 * box sits in. On the stock theme that column holds nothing else, so it is
 * harmless; on a checkout that puts anything else in the same container it
 * takes that with it, and if what it takes is the terms checkbox the shop
 * stops being able to accept any order at all.
 *
 * The fix is entirely in views/js/googlepay.js — no settings change, so there
 * is nothing to migrate here. This file exists so PrestaShop records the new
 * version when the zip is re-uploaded.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_6($module)
{
    return true;
}
