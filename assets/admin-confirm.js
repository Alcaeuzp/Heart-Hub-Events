(function () {
	'use strict';

	// Destructive links and buttons carry data-hherm-confirm="Question?" instead of inline onclick handlers.
	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-hherm-confirm]');
		if (!trigger) return;
		if (!window.confirm(trigger.getAttribute('data-hherm-confirm'))) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true);
}());
