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
		mounted: false,
		// Set when the block was rendered inside PrestaShop's advanced payment
		// step, which presents each method as a box with a collapsed form.
		optionForm: null,
		optionBox: null
	};

	function el(id) {
		return document.getElementById(id);
	}

	function hasClass(node, name) {
		return node.className !== undefined &&
			(' ' + node.className + ' ').indexOf(' ' + name + ' ') !== -1;
	}

	function ancestorWithClass(node, name) {
		while (node && node !== document) {
			if (hasClass(node, name)) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	/**
	 * The advanced payment step wraps every option's markup in a
	 * .payment_option_form that the theme keeps at display:none, opened only by
	 * submitting the page's own confirm button. A wallet cannot work that way —
	 * the sheet has to be opened by a tap on Google's own button — so the block
	 * is revealed here instead. Nothing in the theme is modified.
	 */
	function adoptAdvancedLayout() {
		var block = el('gps-block');
		if (!block) {
			return;
		}

		var form = ancestorWithClass(block.parentNode, 'payment_option_form');
		if (!form) {
			return;
		}

		state.optionForm = form;
		form.style.display = 'block';

		// The option's clickable box is the sibling just before the form.
		var sibling = form.previousSibling;
		while (sibling && sibling.nodeType !== 1) {
			sibling = sibling.previousSibling;
		}
		if (sibling && hasClass(sibling, 'payment_module')) {
			state.optionBox = sibling;
		}
	}

	/** True when the shop asks for terms and the shopper has not accepted them. */
	function termsPending() {
		var boxes = ['cgv', 'revocation_vp_terms_agreed'];
		for (var i = 0; i < boxes.length; i++) {
			var box = el(boxes[i]);
			if (box && box.type === 'checkbox' && !box.checked) {
				return true;
			}
		}
		return false;
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
				adoptAdvancedLayout();
				hideOption();
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

	/** True when the node, or anything above it, is hidden from the shopper. */
	function isVisible(node) {
		while (node && node.nodeType === 1) {
			var style = window.getComputedStyle ? window.getComputedStyle(node) : null;
			if (style && (style.display === 'none' || style.visibility === 'hidden')) {
				return false;
			}
			node = node.parentNode;
		}
		return true;
	}

	/**
	 * Whether this module may hide `node`.
	 *
	 * Everything on the payment step except this module's own block was put
	 * there by PrestaShop, the theme, or another module. Hiding any of it can
	 * take away something the shopper needs in order to buy at all — the terms
	 * checkbox, the confirm button, somebody else's payment method — and a
	 * checkout that cannot be completed is far worse than a Google Pay button
	 * that is not offered. So nothing is hidden without checking first.
	 */
	function safeToHide(node) {
		if (!node || node.nodeType !== 1) {
			return false;
		}

		// Controls the order cannot be placed without.
		var required = ['cgv', 'revocation_vp_terms_agreed', 'confirmOrder'];
		for (var i = 0; i < required.length; i++) {
			var control = el(required[i]);
			if (!control) {
				continue;
			}
			if (node === control || (node.contains && node.contains(control))) {
				return false;
			}
		}

		// Another payment method's markup.
		if (node.querySelectorAll) {
			var forms = node.querySelectorAll('.payment_option_form');
			for (i = 0; i < forms.length; i++) {
				if (forms[i] !== state.optionForm) {
					return false;
				}
			}
			var boxes = node.querySelectorAll('p.payment_module');
			for (i = 0; i < boxes.length; i++) {
				if (boxes[i] !== state.optionBox) {
					return false;
				}
			}
		}

		return true;
	}

	/** True when every element inside `node` was drawn for this option. */
	function holdsOnlyThisOption(node) {
		if (!node || node.nodeType !== 1 || !node.children || !node.children.length) {
			return false;
		}
		for (var i = 0; i < node.children.length; i++) {
			var child = node.children[i];
			if (child !== state.optionBox && child !== state.optionForm) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Withdraws the Google Pay option from the advanced layout.
	 *
	 * PrestaShop draws the option box server side, before anyone knows whether
	 * this browser can pay. When it turns out it cannot, leaving the box there
	 * would offer a payment method that does nothing when tapped.
	 *
	 * Only the two elements PrestaShop drew for THIS option are hidden. An
	 * earlier version hid their parent column instead, which is fine on the
	 * stock theme, where that column holds nothing else — and silently fatal on
	 * any theme that puts something a shopper needs in the same container.
	 * The empty column is left behind on purpose: a gap in the grid is a
	 * cosmetic problem, and it is the only cost that is bounded.
	 */
	function hideOption() {
		if (!state.optionForm) {
			return;
		}

		if (safeToHide(state.optionForm)) {
			state.optionForm.style.display = 'none';
		}

		if (state.optionBox && safeToHide(state.optionBox)) {
			state.optionBox.style.display = 'none';

			// The shopper may already have picked this option before Stripe
			// came back. Left selected, the theme would keep treating a method
			// that is no longer on the page as their choice, and the confirm
			// guard below would go on intercepting the button forever.
			releaseSelection();
		}

		var column = state.optionForm.parentNode;
		if (holdsOnlyThisOption(column) && safeToHide(column)) {
			column.style.display = 'none';
		}
	}

	/** Hands the theme's payment selection back, if this option held it. */
	function releaseSelection() {
		if (!state.optionBox || !hasClass(state.optionBox, 'payment_selected')) {
			return;
		}
		state.optionBox.className = (' ' + state.optionBox.className + ' ')
			.replace(' payment_selected ', ' ')
			.replace(/^\s+|\s+$/g, '');

		// The theme tracks the selection in its own handler object, which is
		// not reachable from here. Clearing the class is what its own code
		// tests, so a later click on another method still behaves normally.
	}

	/**
	 * The advanced step expects each option to hand it a form, which its own
	 * confirm button submits. This one deliberately has none: the wallet must be
	 * opened by a tap on Google's button. Left alone the theme would answer that
	 * click with a bare "could not submit" alert, so the click is caught first
	 * and turned into an instruction that makes sense.
	 *
	 * Bound on document in the capture phase so it runs before the theme's own
	 * handler on the button, whatever order the scripts happen to load in.
	 */
	function guardConfirmButton() {
		if (!state.optionBox) {
			return;
		}

		document.addEventListener('click', function (ev) {
			// A different button, or an option that is not ours — let the theme
			// handle the click exactly as it normally would.
			var button = ancestorWithId(ev.target, 'confirmOrder');
			if (!button || !hasClass(state.optionBox, 'payment_selected')) {
				return;
			}

			// The block is gone: this browser turned out not to support Google
			// Pay, or the button never mounted. Swallowing the click here would
			// leave the shopper pressing a confirm button that does nothing and
			// says nothing, because the message would be written into a hidden
			// element. Stand aside and let the theme speak.
			if (!state.mounted || !isVisible(el('gps-block'))) {
				return;
			}

			ev.preventDefault();
			ev.stopPropagation();

			showError(termsPending() ? config.i18n.terms : config.i18n.tapButton);

			var target = el('gps-button');
			if (target && target.scrollIntoView) {
				target.scrollIntoView({ block: 'center' });
			}
		}, true);
	}

	function ancestorWithId(node, id) {
		while (node && node !== document) {
			if (node.id === id) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function init() {
		if (!config || !config.publishableKey) {
			return;
		}
		if (!el('gps-block')) {
			return;
		}

		adoptAdvancedLayout();
		guardConfirmButton();

		state.stripe = window.Stripe(config.publishableKey);

		request(config.intentUrl, function (data) {
			state.clientSecret = data.clientSecret;
			state.intentId = data.intentId;
			buildButton(data);
		}, function () {
			// Stay silent and hidden: the shopper still has every other
			// payment method available and nothing has gone wrong for them.
			hideOption();
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

		// The shop's terms have to be accepted before the wallet opens. Stripe
		// lets the click be cancelled, but only synchronously — nothing may be
		// awaited in here or the browser treats the gesture as spent.
		prButton.on('click', function (ev) {
			if (termsPending()) {
				ev.preventDefault();
				showError(config.i18n.terms);
			} else {
				clearError();
			}
		});

		paymentRequest.canMakePayment().then(function (result) {
			// result.googlePay is true only on a browser with a real Google Pay
			// setup. Anything else and we leave the block hidden.
			if (result && result.googlePay) {
				prButton.mount('#gps-button');
				el('gps-block').style.display = 'block';
				state.mounted = true;
			} else {
				hideOption();
			}
		}).catch(function () {
			hideOption();
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
