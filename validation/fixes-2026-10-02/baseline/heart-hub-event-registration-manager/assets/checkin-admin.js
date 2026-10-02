(function () {
	'use strict';

	document.querySelectorAll('[data-checkin-qr]').forEach(function (element) {
		if (!element.dataset.url || typeof window.QRCode === 'undefined') return;
		new window.QRCode(element, {
			text: element.dataset.url,
			width: 175,
			height: 175,
			colorDark: '#16384a',
			colorLight: '#ffffff',
			correctLevel: window.QRCode.CorrectLevel.M
		});
	});

	document.querySelectorAll('[data-copy-checkin]').forEach(function (button) {
		button.addEventListener('click', function () {
			var input = button.parentElement.querySelector('input');
			if (!input) return;
			var complete = function () {
				button.textContent = 'Copied';
				window.setTimeout(function () { button.textContent = 'Copy link'; }, 1600);
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(input.value).then(complete);
			} else {
				input.select();
				document.execCommand('copy');
				complete();
			}
		});
	});
}());
