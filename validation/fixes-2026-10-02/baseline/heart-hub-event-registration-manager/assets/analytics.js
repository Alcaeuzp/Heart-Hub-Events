(function () {
	'use strict';

	document.querySelectorAll('[data-export-analytics]').forEach(function (button) {
		button.addEventListener('click', function () {
			var rows = [['Event', 'Attendance rate', 'Registrations', 'Average rating']];
			document.querySelectorAll('.hherm-analytics-row').forEach(function (row) {
				rows.push([row.dataset.event || '', (row.dataset.rate || '0') + '%', row.dataset.registrations || '0', row.dataset.average || '']);
			});
			var csv = rows.map(function (row) { return row.map(function (value) { var text = String(value); if (/^[\t\r\n ]*[=+\-@]/.test(text)) { text = "'" + text; } return '"' + text.replace(/"/g, '""') + '"'; }).join(','); }).join('\r\n');
			var link = document.createElement('a');
			link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
			link.download = 'heart-hub-analytics.csv';
			link.click();
			URL.revokeObjectURL(link.href);
		});
	});
}());
