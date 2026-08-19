/**
 * Google Pay button for PrestaShop 1.6, driven by Stripe.js v3.
 *
 * Flow:
 *   1. ask the server for a PaymentIntent (the server decides the amount)
 *   2. ask the browser whether Google Pay is actually available
 *   3. reveal the button only if it is
 *   4. on tap, confirm the intent, handle 3-D Secure if the bank asks
 *   5. hand off to the server to create the order
 *
 * The amount is never sent from here — the server recomputes it from the cart.
 */
(function () {
	'use strict';

	var config = window.gpsConfig || null;
	var state = {
		clientSecret: null,
		intentId: null,
		stripe: null,
		mounted: false
	};

	function el(id) {
		return document.getElementById(id);
	}

	function showError(message) {
		var box = el('gps-error');
		if (!box) {
			return;
		}
		box.textContent = message;
		box.style.display = 'block';
		hideProcessing();
	}

	function clearError() {
		var box = el('gps-error');
		if (box) {
			box.style.display = 'none';
			box.textContent = '';
		}
	}

	function showProcessing() {
		var box = el('gps-processing');
		var text = el('gps-processing-text');
		if (text) {
			text.textContent = config.i18n.processing;
		}
		if (box) {
			box.style.display = 'block';
		}
	}

	function hideProcessing() {
		var box = el('gps-processing');
		if (box) {
			box.style.display = 'none';
		}
	}

	/**
	 * Waits for Stripe.js, which is loaded from js.stripe.com and may land
	 * after this file does.
	 */
	function whenStripeReady(callback) {
		var waited = 0;
		var poll = setInterval(function () {
			if (typeof window.Stripe === 'function') {
				clearInterval(poll);
				callback();
			} else if (waited > 10000) {
				clearInterval(poll);
				// Nothing to show the shopper here: the block is still hidden,
				// so they simply use another payment method.
				if (window.console && window.console.warn) {
					window.console.warn('[googlepaystripe] Stripe.js did not load — Google Pay button not shown.');
				}
			}
			waited += 200;
		}, 200);
	}

	function request(url, callback, errback) {
		var xhr = new XMLHttpRequest();
		xhr.open('POST', url, true);
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) {
				return;
			}
			var data = null;
			try {
				data = JSON.parse(xhr.responseText);
			} catch (e) {
				errback(config.i18n.network);
				return;
			}
			if (xhr.status >= 200 && xhr.status < 300 && data && !data.error) {
				callback(data);
			} else {
				errback((data && data.error) ? data.error : config.i18n.generic);
			}
		};
		xhr.onerror = function () {
			errback(config.i18n.network);
		};
		xhr.send();
	}

	function init() {
		if (!config || !config.publishableKey) {
			return;
		}
		if (!el('gps-block')) {
			return;
		}

		state.stripe = window.Stripe(config.publishableKey);

		request(config.intentUrl, function (data) {
			state.clientSecret = data.clientSecret;
			state.intentId = data.intentId;
			buildButton(data);
		}, function () {
			// Stay silent and hidden: the shopper still has every other
			// payment method available and nothing has gone wrong for them.
			if (window.console && window.console.warn) {
				window.console.warn('[googlepaystripe] Could not create a payment intent — Google Pay button not shown.');
			}
		});
	}

	function buildButton(data) {
		var paymentRequest = state.stripe.paymentRequest({
			country: config.country,
			currency: data.currency,
			total: {
				label: data.label,
				amount: data.amount
			},
			requestPayerName: true,
			requestPayerEmail: true,
			// Apple Pay and browser-saved cards are deliberately excluded:
			// this module is scoped to Google Pay only.
			disableWallets: ['applePay', 'browserCard', 'link']
		});

		var elements = state.stripe.elements();
		var prButton = elements.create('paymentRequestButton', {
			paymentRequest: paymentRequest,
			style: {
				paymentRequestButton: {
					type: config.buttonType || 'buy',
					theme: config.buttonTheme || 'dark',
					height: '48px'
				}
			}
		});

		paymentRequest.canMakePayment().then(function (result) {
			// result.googlePay is true only on a browser with a real Google Pay
			// setup. Anything else and we leave the block hidden.
			if (result && result.googlePay) {
				prButton.mount('#gps-button');
				el('gps-block').style.display = 'block';
				state.mounted = true;
			}
		}).catch(function () {
			/* leave hidden */
		});

		paymentRequest.on('paymentmethod', function (ev) {
			handlePaymentMethod(ev);
		});
	}

	function handlePaymentMethod(ev) {
		clearError();
		showProcessing();

		// handleActions:false so the Google Pay sheet can be dismissed cleanly
		// before we run any 3-D Secure step ourselves.
		state.stripe.confirmCardPayment(
			state.clientSecret,
			{ payment_method: ev.paymentMethod.id },
			{ handleActions: false }
		).then(function (result) {
			if (result.error) {
				ev.complete('fail');
				showError(result.error.message || config.i18n.generic);
				return;
			}

			ev.complete('success');

			if (result.paymentIntent.status === 'requires_action') {
				// The bank wants a 3-D Secure challenge. Stripe opens it here.
				state.stripe.confirmCardPayment(state.clientSecret).then(function (after) {
					if (after.error) {
						showError(after.error.message || config.i18n.generic);
						return;
					}
					finish(after.paymentIntent.id);
				});
				return;
			}

			finish(result.paymentIntent.id);
		}).catch(function () {
			ev.complete('fail');
			showError(config.i18n.generic);
		});
	}

	/**
	 * Hands control back to PrestaShop, which re-checks everything server side
	 * before the order is created.
	 */
	function finish(intentId) {
		var separator = config.validationUrl.indexOf('?') === -1 ? '?' : '&';
		window.location.href = config.validationUrl + separator +
			'payment_intent=' + encodeURIComponent(intentId);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			whenStripeReady(init);
		});
	} else {
		whenStripeReady(init);
	}
}());
