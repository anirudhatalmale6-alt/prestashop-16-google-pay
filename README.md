# Google Pay for PrestaShop 1.6

A payment module that adds a Google Pay button to the existing PrestaShop 1.6 checkout,
processed through Stripe.

**Built for PrestaShop 1.6.1.x on PHP 7.2.**
Verified against a real PrestaShop **1.6.1.24 / PHP 7.2** install, not a simulation.

---

## Why Stripe is involved

Google Pay for Web does not move money. It returns an *encrypted payment token*, and an
underlying payment service provider has to decrypt it and charge the card. This module
uses Stripe for that, which means:

- card data never touches the shop's server → the shop stays in **PCI DSS SAQ A**
- no Google Pay & Wallet Console merchant ID is needed — Stripe owns that registration
- the same integration also gives the shop ordinary card payments

---

## Install

Copy `googlepaystripe/` into the shop's `/modules/` folder, then install it from
**Modules and Services** in the back office.

Full setup, key configuration, webhook setup, testing checklist and troubleshooting:
**[googlepaystripe/DEPLOYMENT.md](googlepaystripe/DEPLOYMENT.md)**

---

## What it does

- Google Pay button on the payment step, beside the shop's existing methods
- Button is shown **only** when the shopper's browser can actually pay with Google Pay,
  so nobody sees a button that cannot work
- Server-side amount calculation — the browser never says what to charge
- 3-D Secure handled when the bank asks for it
- Full transaction record in the back office: payment intent, charge id, card brand,
  last 4, wallet type, plus a direct link into the Stripe dashboard
- Stripe webhook as a safety net, so a payment still becomes an order even if the
  shopper's browser dies mid-checkout
- Test/live mode switch with key-prefix validation and a live API key check on save

## What it does not touch

- No PrestaShop core file is modified
- No theme file is modified — the button is injected through the standard `payment` hook
- Survives theme changes and 1.6.x updates

---

## Layout

```
googlepaystripe/
├── googlepaystripe.php              main module class, hooks, config screen
├── DEPLOYMENT.md                    setup and operations guide
├── controllers/front/
│   ├── ajax.php                     creates/refreshes the Stripe PaymentIntent
│   ├── validation.php               browser return path → creates the order
│   └── webhook.php                  Stripe server-to-server safety net
├── libs/
│   ├── StripeClientLoader.php       loads the bundled library, avoids clashes
│   ├── PaymentProcessor.php         shared order creation + duplicate protection
│   └── stripe-php/                  Stripe PHP SDK v7.128.0 (pinned for PHP 7.2)
└── views/
    ├── js/googlepay.js              Stripe.js payment request button
    ├── css/googlepay.css
    └── templates/hook/              checkout block, confirmation, back office panels
```

---

## Notes

- The Stripe library is pinned to the **7.x** line because the shop runs PHP 7.2; current
  Stripe releases assume PHP 8 in places.
- `js.stripe.com/v3` is loaded directly from Stripe and must never be bundled into the
  theme's JavaScript — that is what keeps the shop in SAQ A.
- After installing, check **Country restrictions** on the Payment tab. PrestaShop only
  whitelists countries that were active when the module was installed, and the button
  silently disappears for any country not on that list.
