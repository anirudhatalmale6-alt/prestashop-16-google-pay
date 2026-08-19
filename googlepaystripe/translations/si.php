<?php
/**
 * Slovenian translation for the Google Pay (via Stripe) module.
 *
 * Keys follow the PrestaShop 1.6 format:
 *   <{module}prestashop>SOURCE_md5(english string)
 * where SOURCE is the module name for PHP strings and the template
 * basename for strings inside .tpl files.
 *
 * Back office configuration labels are intentionally left in English —
 * only text a customer can actually see is translated here.
 */

global $_MODULE;
$_MODULE = array();

// TEST MODE
$_MODULE['<{googlepaystripe}prestashop>payment_88299e7c23ef1a0571459f5b51c047e2'] = 'TESTNI NAČIN';

// Pay in a couple of taps with the card saved in your Google account. You will be asked to confirm before anything is charged.
$_MODULE['<{googlepaystripe}prestashop>payment_1009ac6070c980234ddd350e806353c4'] = 'Plačajte z nekaj dotiki s kartico, shranjeno v vašem Google računu. Pred bremenitvijo boste plačilo še potrdili.';

// Payment confirmed
$_MODULE['<{googlepaystripe}prestashop>payment_return_385bd916f56ffa447cd78984f983c93c'] = 'Plačilo potrjeno';

// Thank you. Your payment with Google Pay was accepted.
$_MODULE['<{googlepaystripe}prestashop>payment_return_16b38b02805e7aeb4b664a872b2546a0'] = 'Hvala. Vaše plačilo z Google Pay je bilo sprejeto.';

// Order reference:
$_MODULE['<{googlepaystripe}prestashop>payment_return_b913dabf84a2fbeb711e6873210228b7'] = 'Referenca naročila:';

// Amount paid:
$_MODULE['<{googlepaystripe}prestashop>payment_return_662acb14acff56ef92e014caf16dd6c6'] = 'Plačani znesek:';

// Paid with:
$_MODULE['<{googlepaystripe}prestashop>payment_return_ec4c1f22ba00b851c273154355972832'] = 'Plačano z:';

// A confirmation email is on its way. If you have any questions about your order, please get in touch and quote the reference above.
$_MODULE['<{googlepaystripe}prestashop>payment_return_fe05a2e25c392498cbd2f15eea2e7413'] = 'Potrditveno e-sporočilo je na poti. Če imate glede naročila kakršno koli vprašanje, nas kontaktirajte in navedite zgornjo referenco.';

// Pay with Google Pay
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_ad7608ada46c853f7d02a30cddde3c8a'] = 'Plačilo z Google Pay';

// There was no payment to confirm. Please try again.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_fc46cfa26c94b4251e1683b92d775cb7'] = 'Ni bilo plačila za potrditev. Poskusite znova.';

// We could not identify your account. Please sign in again.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_b24a55ab5b5bb7fb79c6778ef38d669e'] = 'Vašega računa nismo mogli prepoznati. Prosimo, prijavite se znova.';

// We could not confirm your payment with the provider. Please contact us before trying again so you are not charged twice.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_71ff960ef4ce275b1ab9d5e1d8abbfbd'] = 'Plačila pri ponudniku nismo mogli potrditi. Prosimo, kontaktirajte nas, preden poskusite znova, da ne pride do dvojne bremenitve.';

// Your payment was not completed. You have not been charged.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_102fdc006e929c5999963a31fccbbaa0'] = 'Vaše plačilo ni bilo dokončano. Vaša kartica ni bila bremenjena.';

// Your payment went through and your order is being finalised. Please check your order history in a moment before paying again.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_9b94aaabec168dd0cc83ab7c8e784fdb'] = 'Vaše plačilo je bilo uspešno in naročilo se zaključuje. Prosimo, čez trenutek preverite zgodovino naročil, preden plačate znova.';

// Your payment was taken but we could not finalise the order. Please contact us and we will sort it out straight away.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_43d90881c85c983e6c4f228738fd8e74'] = 'Plačilo je bilo izvedeno, vendar naročila nismo mogli zaključiti. Prosimo, kontaktirajte nas in takoj bomo uredili.';

// The payment could not be completed. Please try again or choose another payment method.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_73e3c529fd744ca697f83043473ae254'] = 'Plačila ni bilo mogoče dokončati. Poskusite znova ali izberite drug način plačila.';

// We could not reach the payment server. Please check your connection and try again.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_10aa977da6fcbbed3ef3c2c657bffb8b'] = 'Do plačilnega strežnika ni bilo mogoče dostopati. Preverite povezavo in poskusite znova.';

// Processing your payment, please do not close this page...
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_ac25ad52fa94617376957932d0c1b477'] = 'Obdelava plačila, prosimo, ne zapirajte te strani ...';

// Google Pay (via Stripe)
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_23adcd86889d978336f3ed25fa4540f5'] = 'Google Pay (prek Stripe)';

// Adds a Google Pay button to your checkout. Payments are processed by Stripe, so card data never reaches your server.
$_MODULE['<{googlepaystripe}prestashop>googlepaystripe_fdb96e193327f25bf8a698db7a534f73'] = 'Doda gumb Google Pay v vašo blagajno. Plačila obdeluje Stripe, zato podatki o kartici nikoli ne pridejo na vaš strežnik.';
