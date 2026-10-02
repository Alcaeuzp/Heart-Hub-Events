(function () {
	'use strict';

	document.querySelectorAll('[data-checkin-event]').forEach(function (select) {
		select.addEventListener('change', function () {
			var form = document.getElementById('hherm-checkin-page-selector');
			var input = form && form.querySelector('[data-checkin-event-submit]');
			if (input) input.value = select.value;
			if (form) form.submit();
		});
	});

	document.querySelectorAll('[data-checkin-live]').forEach(function (panel) {
		if (!window.HHERM_CHECKIN || !panel.dataset.eventId) return;
		var total = panel.querySelector('[data-checkin-total]');
		var progress = panel.querySelector('[data-checkin-progress]');
		var feed = panel.querySelector('[data-checkin-feed]');
		var load = function () {
			var url = window.HHERM_CHECKIN.root.replace(/\/$/, '') + '/check-ins?event_id=' + encodeURIComponent(panel.dataset.eventId);
			fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.HHERM_CHECKIN.nonce } })
				.then(function (response) { if (!response.ok) throw new Error('Unable to load live check-ins.'); return response.json(); })
				.then(function (data) {
					var checked = Number(data.checked_in || 0), expected = Number(data.expected || 0);
					if (total) total.textContent = checked + ' of ' + expected + ' people';
					if (progress) progress.style.width = (expected ? Math.min(100, checked / expected * 100) : 0) + '%';
					if (feed) feed.innerHTML = data.feed && data.feed.length ? data.feed.map(function (item) { return '<div class="hherm-live-feed__row"><strong>' + escapeHtml(item.name || 'Attendee') + '</strong><span>Party of ' + escapeHtml(item.party) + '</span><time>' + escapeHtml(item.time) + '</time></div>'; }).join('') : '<p class="hherm-muted">No check-ins yet.</p>';
				})
				.catch(function () { if (total && total.textContent === 'Loading…') total.textContent = 'Live data unavailable'; });
		};
		load();
		window.setInterval(load, 20000);
	});

	function escapeHtml(value) {
		var el = document.createElement('div');
		el.textContent = value == null ? '' : String(value);
		return el.innerHTML;
	}
}());
