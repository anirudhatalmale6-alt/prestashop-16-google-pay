# Google Pay for PrestaShop 1.6 — deployment notes

Module folder name: `googlepaystripe`
Tested against: **PrestaShop 1.6.1.24 on PHP 7.2** (a real install, not a simulation)

---

## 1. What this module actually does

Google Pay on the web is **not a payment processor**. When a shopper taps the Google Pay
button, Google hands the page an *encrypted payment token*. Something else has to decrypt
that token and charge the card. That something is Stripe.

So the flow is:

```
Shopper taps Google Pay
        ↓
Google returns an encrypted token to the browser
        ↓
Stripe.js swaps it for a PaymentMethod id (card data never touches your server)
        ↓
Your server confirms a Stripe PaymentIntent using only that id
        ↓
PrestaShop creates the order
```

**Why this matters for PCI:** raw card data never reaches your hosting. Only opaque
identifiers such as `pi_...` and `pm_...` do. That keeps the shop in **PCI DSS SAQ A**,
the shortest self-assessment questionnaire. Two things preserve that, and both are
deliberate in this module — do not "optimise" them away:

- `js.stripe.com/v3` is loaded **directly from Stripe** in the payment template. It is
  never bundled, minified or combined into your theme's JavaScript. If you ever turn on
  "Smart cache for JavaScript" in PrestaShop, confirm afterwards that the Stripe script
  tag is still a separate `<script src="https://js.stripe.com/v3/">`.
- The module never asks for, receives, or stores a card number.

---

## 2. Requirements

| Requirement | Why |
|---|---|
| PrestaShop 1.6.x | Module targets the 1.6 hook API |
| PHP 5.6 – 7.4 | Stripe library v7 is pinned for this range |
| PHP extensions: `curl`, `json`, `mbstring` | Required by the Stripe library. Install refuses without them |
| **HTTPS on the whole site** | Google Pay will not load on http. This is non-negotiable |
| A Stripe account | Stripe is the gateway that actually takes the money |

The Stripe PHP library (v7.128.0) is **bundled inside the module** at `libs/stripe-php/`.
You do not need Composer on the server. It is pinned at the 7.x line on purpose: newer
Stripe releases assume PHP 8 in places, and this shop runs 7.2.

---

## 3. Installation

1. Upload the whole `googlepaystripe` folder to `/modules/` on the shop (FTP is fine).
2. Back office → **Modules and Services** → search "Google Pay" → **Install**.
3. Click **Configure**.

The configuration page opens with a **Status** panel at the top. Every row must be green
before you take a live payment. It checks SSL, the PHP extensions, whether keys are saved,
the webhook secret, and the currency restrictions.

---

## 4. Stripe keys

In your Stripe dashboard: **Developers → API keys**.

| Field in the module | Stripe value | Starts with |
|---|---|---|
| Test publishable key | Publishable key (test mode) | `pk_test_` |
| Test secret key | Secret key (test mode) | `sk_test_` |
| Live publishable key | Publishable key (live mode) | `pk_live_` |
| Live secret key | Secret key (live mode) | `sk_live_` |

The module validates the prefixes when you save, so a live key pasted into a test box is
rejected rather than quietly charging real money in a "test" checkout. Saving also makes a
live call to Stripe to confirm the secret key actually works, and tells you if it does not.

**Test mode switch:** while it is On, the module uses the test key pair and no real money
moves. You cannot switch it Off until both live keys are filled in.

### Do you need anything from the Google Pay & Wallet Console?

Almost certainly **no**. Because payments go through Stripe rather than a direct
integration, Stripe handles the Google merchant registration on your behalf. There is no
Google merchant ID to paste anywhere in this module.

The one thing worth doing in the Stripe dashboard is **Settings → Payment methods** and
confirming Google Pay is enabled for your account (it is on by default for most).

---

## 5. Webhook (strongly recommended)

The webhook is the safety net. If a shopper's browser dies between Google Pay saying
"paid" and PrestaShop writing the order — closed laptop, lost signal, crashed tab — Stripe
still tells your server, and the order is created anyway. Without it, that payment becomes
a charge with no order behind it.

1. Copy the webhook URL shown in the Status panel of the module. It looks like:

   ```
   https://your-shop.example/index.php?fc=module&module=googlepaystripe&controller=webhook
   ```

2. Stripe dashboard → **Developers → Webhooks → Add endpoint**, paste that URL.
3. Subscribe it to exactly these three events:
   - `payment_intent.succeeded`
   - `payment_intent.amount_capturable_updated`
   - `payment_intent.payment_failed`
4. Stripe shows a **signing secret** starting with `whsec_`. Paste it into the module's
   "Webhook signing secret" field and save.

Without the signing secret the endpoint **refuses every request**, on purpose — otherwise
anyone who found the URL could fake a payment.

Do this twice: once for your test-mode endpoint and once for live. They have different
signing secrets.

---

## 6. Countries and currencies — the trap that hides the button

This one caught me during testing and it will catch you after any change, so it is worth
reading twice.

PrestaShop restricts payment modules **per country** and **per currency**. When a payment
module is installed, PrestaShop whitelists only the countries that were *active at that
moment*. Activate a new country later and the Google Pay button silently disappears for
customers in it — no error, no warning, it is simply not rendered.

To check or fix:

- **Modules and Services → Payment** tab.
- Under **Currency restrictions**, tick every currency you sell in for "Google Pay (via Stripe)".
- Under **Country restrictions**, tick every country you ship to.

If the button is missing at checkout and everything else looks right, this is the first
place to look.

### Devices

`module_shop.enable_device` is a bitmask — 1 desktop, 2 tablet, 4 mobile — ANDed against
the shopper's device. Clear the mobile bit and the module is invisible **on phones only**,
which reads as a browser fault rather than a setting. Google Pay is mostly used on phones,
so this one matters. The status panel checks all three.

---

## 6b. The two payment steps — the trap that hid the button on the live shop

PrestaShop 1.6.1 ships **two different payment steps**, and they call different hooks.

| Setting | Template | Hook executed |
|---|---|---|
| `PS_ADVANCED_PAYMENT_API = 0` | `order-payment-classic.tpl` | `displayPayment` |
| `PS_ADVANCED_PAYMENT_API = 1` | `order-payment-advanced.tpl` | `advancedPaymentOptions` |

With the advanced step on, `displayPayment` is **never executed at all**. A module that
implements only `hookPayment` is therefore invisible, silently — every country, group,
currency and hook check can pass while the module is never asked anything.

You can recognise the advanced step on sight: payment methods drawn as boxes with logos
side by side, the delivery and invoice addresses repeated below them, and a single confirm
button (usually "Order With Obligation To Pay") instead of each method being its own link.

**It is normally switched on by the Advanced EU Compliance module**, which EU shops need for
the legally required wording and terms checkbox. Do not turn it off to make a payment module
appear — that removes compliance wording from a live checkout.

This module implements **both** hooks (since 1.0.3), so it works either way and keeps working
if the setting is ever changed.

Worth knowing: stock PrestaShop's own `bankwire` and `cheque` modules do **not** implement
the advanced hook. On a clean 1.6.1.24 with the setting on and no EU compliance module
installed, the payment step shows *"Unable to find any available payment option for your
cart"*. That is a PrestaShop limitation, not a fault in your shop.

### How the module behaves in the advanced step

- It supplies a payment option box with the Google Pay logo, like any other method.
- That step keeps each option's form hidden and submits it from the page's confirm button.
  A wallet cannot work that way — the sheet must open from a tap on Google's own button —
  so the module opens its own container. No theme file is modified.
- If the shopper selects Google Pay and presses the page's confirm button, the module says
  to tap the Google Pay button instead of letting the theme show a bare failure alert.
- The terms checkbox is enforced before the wallet opens.
- If the browser cannot do Google Pay, the whole option box is removed, so nobody is offered
  a payment method that does nothing.

---

## 7. Testing

### Sandbox (test mode)

1. Test mode On, test keys saved.
2. Open the shop **in Chrome, signed into a Google account, over https**.
3. Add a product, go to checkout, reach the payment step.
4. The Google Pay button appears only if the browser can actually pay. On a browser
   without Google Pay it is hidden and the shopper sees your other payment methods
   normally — that is intended behaviour, not a fault.
5. In Stripe test mode, Google Pay presents a test card. Complete the payment.
6. Check: order appears under **Orders**, status **Payment accepted**, and the order page
   shows a "Google Pay / Stripe transaction" panel with the payment intent, charge id,
   card brand, last 4, and a direct link into the Stripe dashboard.

### Things worth deliberately testing

| Test | Expected |
|---|---|
| Cancel the Google Pay sheet | No order, no charge, shopper stays on the payment step |
| Pay, then reload the confirmation page | Still one order — not two |
| A 3-D Secure test card | Stripe opens the challenge, then the order completes |
| Browser with no Google Pay | Button hidden, other payment methods unaffected |
| Change the cart in a second tab, then pay | Order is created but parked in **Payment error** with a note, so you can check it before shipping |

### Going live

1. Fill in the live keys.
2. Create the **live** webhook endpoint in Stripe and paste its signing secret.
3. Turn **Test mode** Off and save.
4. Make one real purchase with a real card, for a small amount, and refund it from the
   Stripe dashboard afterwards.

---

## 8. Where things are recorded

Every payment attempt is written to a table `PREFIX_googlepaystripe_payment`, holding the
payment intent id, charge id, status, amount, currency, card brand, last 4, card funding
type, wallet type, live/test flag, and the linked cart and order.

This table is deliberately **not dropped when you uninstall the module** — it is the audit
trail for orders that have already been placed. Drop it by hand only if you are certain
you no longer need the history.

Card numbers are never stored. Only the brand and last four digits, which is what Stripe
returns for display purposes.

---

## 9. Upgrades and theme changes

- **No PrestaShop core file is modified.** Everything lives inside `/modules/googlepaystripe/`.
- **No theme file is modified.** The button is injected through PrestaShop's own payment
  hooks — `payment` for the classic step, `advancedPaymentOptions` for the advanced one — so
  it survives a theme change. If you switch to a theme that heavily rewrites the checkout,
  re-check the payment step visually — the styling may want a tweak, but nothing will break.
- **Upgrading by re-uploading the zip does not re-run `install()`.** Anything that has to
  happen on an existing install belongs in `upgrade/upgrade-X.Y.Z.php`. That is how 1.0.3
  attaches the advanced hook to shops that were already running an earlier version, with no
  reinstall and without losing keys or restrictions.
- Upgrading within 1.6.x is safe. Moving to PrestaShop 1.7 or 8 is **not** — those use a
  completely different payment API and would need the module rewritten.
- If you upgrade PHP past 7.4, the bundled Stripe library must be swapped for a newer
  major version at the same time.

To reproduce this setup on a staging site: copy the `/modules/googlepaystripe/` folder
across, install it from the back office, and enter the **test** keys. Never point a
staging site at live keys.

---

## 10. Troubleshooting

| Symptom | Cause to check first |
|---|---|
| Button never appears | Site not fully https; or country/currency/device restriction (section 6); or the advanced payment step with the module not attached to `advancedPaymentOptions` (section 6b); or the browser genuinely has no Google Pay |
| Every status check green, still no button | Read **Last checkout result** at the bottom of the status panel. A fresh "shown" means the shop did its job and the cause is in the browser. A fresh "skipped" names the reason. A timestamp that never moves means the payment step never called the module at all — look at section 6b first |
| Button appears, payment fails immediately | Wrong or revoked Stripe key. Re-save the config — it verifies the key and reports the error |
| Payment taken, no order | Webhook not configured. Set it up (section 5); Stripe will retry and the order will be created |
| Order stuck in "Payment error" | Cart total changed between quote and charge. Compare the Stripe amount against the order and decide before shipping |
| "The payment provider refused the request" in the browser console | The server could not reach Stripe. Check outbound HTTPS is not blocked by the host firewall |

Turn on **Debug log** in the module configuration to write each payment step to
**Advanced Parameters → Logs**. Turn it back off once live.

---

## 11. Language

Everything a customer can see is translated into Slovenian:

- `translations/si.php` — for shops whose language ISO code is `si`
- `translations/sl.php` — identical file, for shops using the standard `sl` code

Both are shipped so the translation works whichever code the shop uses. PrestaShop picks
the file matching the active language's ISO code and ignores the other.

Back office labels (the configuration screen) are deliberately left in English. Only the
merchant sees those, and only during setup.

To change any wording, edit the Slovenian string on the right-hand side of the `=` in
`translations/si.php` **and** `translations/sl.php`. Do not touch the long key on the left
— it is an MD5 of the English source string and PrestaShop uses it to find the translation.

Strings a customer can see:

| English | Slovenian |
|---|---|
| Pay with Google Pay | Plačilo z Google Pay |
| TEST MODE | TESTNI NAČIN |
| Pay in a couple of taps with the card saved in your Google account… | Plačajte z nekaj dotiki s kartico, shranjeno v vašem Google računu… |
| Payment confirmed | Plačilo potrjeno |
| Thank you. Your payment with Google Pay was accepted. | Hvala. Vaše plačilo z Google Pay je bilo sprejeto. |
| Order reference: | Referenca naročila: |
| Amount paid: | Plačani znesek: |
| Paid with: | Plačano z: |

---

## 12. What is not included

- Refunds from the PrestaShop back office. Refund from the Stripe dashboard; it is one
  click there and the money returns to the shopper's card either way.
- Apple Pay. The module explicitly disables it so the scope stays exactly Google Pay.
- Recurring or subscription payments.
