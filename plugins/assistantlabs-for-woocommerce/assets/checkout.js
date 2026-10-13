/**
 * AssistantLabs for WooCommerce — classic checkout cart capture.
 *
 * When the shopper fills in their email or phone (and name), post them to
 * this site's wc-ajax endpoint so the cart can be recovered. The checkout
 * block does not load this file; it reaches the server through the Store API.
 */
(function () {
	'use strict';

	var cfg = window.assistantlabsWcCheckout;
	var form = document.querySelector('form.checkout');
	if (!cfg || !form || !window.fetch || !window.FormData) {
		return;
	}

	var fields = ['billing_email', 'billing_phone', 'billing_first_name', 'billing_last_name'];
	var consentFields = cfg.consentFields || [];
	var last = '';
	var timer = null;

	function value(name) {
		var el = form.querySelector('[name="' + name + '"]');
		return el && typeof el.value === 'string' ? el.value.trim() : '';
	}

	function consent() {
		for (var i = 0; i < consentFields.length; i++) {
			var el = form.querySelector('input[type="checkbox"][name="' + consentFields[i] + '"]');
			if (el) {
				return el.checked ? '1' : '0';
			}
		}
		return '';
	}

	function send() {
		if (!value('billing_email') && !value('billing_phone')) {
			return;
		}
		var body = new FormData();
		var key = '';
		body.append('security', cfg.nonce);
		for (var i = 0; i < fields.length; i++) {
			body.append(fields[i], value(fields[i]));
			key += value(fields[i]) + '\n';
		}
		var c = consent();
		if (c) {
			body.append('consent', c);
		}
		key += c;
		if (key === last) {
			return;
		}
		last = key;
		window.fetch(cfg.url, { method: 'POST', body: body, credentials: 'same-origin' }).catch(function () {});
	}

	function later() {
		clearTimeout(timer);
		timer = setTimeout(send, 500);
	}

	form.addEventListener('change', function (event) {
		var name = event.target && event.target.name;
		if (fields.indexOf(name) !== -1 || consentFields.indexOf(name) !== -1) {
			later();
		}
	});
	// Browser autofill fills fields without a change event on some browsers.
	setTimeout(send, 1500);
})();
