(function () {
	'use strict';

	var form = document.querySelector('.hherm-calendar-form');
	if (!form) return;

	var frequency = form.querySelector('[data-hherm-recurrence]');
	var oneOff = form.querySelector('[data-hherm-one-off]');
	var repeated = form.querySelector('[data-hherm-repeat-fields]');
	var startDate = form.querySelector('[name="repeat_start_date"]');
	var repeatStart = form.querySelector('[data-hherm-repeat-start]');
	var weekly = form.querySelector('[data-hherm-weekdays]');
	var monthDay = form.querySelector('[data-hherm-month-day]');
	var customDates = form.querySelector('[data-hherm-custom-dates]');
	var dateList = form.querySelector('[data-hherm-date-list]');
	var slotList = form.querySelector('[data-hherm-slot-list]');
	var startOnce = form.querySelector('[name="start_date"]');

	function addDate(value) {
		if (dateList.querySelectorAll('.hherm-calendar-date-row').length >= 100) return;
		var row = document.createElement('div');
		row.className = 'hherm-calendar-date-row';
		var input = document.createElement('input');
		input.type = 'date';
		input.name = 'custom_dates[]';
		input.setAttribute('aria-label', 'Date');
		input.value = value || '';
		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'button-link-delete';
		remove.setAttribute('data-hherm-remove-date', '');
		remove.textContent = 'Remove';
		row.appendChild(input);
		row.appendChild(remove);
		dateList.appendChild(row);
		refreshRequirements();
	}

	function addSlot(start, end) {
		if (slotList.querySelectorAll('.hherm-calendar-time-row').length >= 12) return;
		var index = parseInt(slotList.dataset.nextIndex || '0', 10);
		slotList.dataset.nextIndex = String(index + 1);
		var row = document.createElement('div');
		row.className = 'hherm-calendar-time-row';
		row.innerHTML = '<label><span>Starts</span><input type="time" name="time_slots[' + index + '][start]"></label><label><span>Ends</span><input type="time" name="time_slots[' + index + '][end]"></label><button class="button-link-delete" type="button" data-hherm-remove-slot>Remove</button>';
		row.querySelector('input[name$="[start]"]').value = start || '';
		row.querySelector('input[name$="[end]"]').value = end || '';
		slotList.appendChild(row);
		refreshRequirements();
	}

	function refreshRequirements() {
		var mode = frequency.value;
		var repeating = 'none' !== mode;
		oneOff.hidden = repeating;
		repeated.hidden = !repeating;
		startOnce.required = !repeating;
		startDate.required = repeating && 'dates' !== mode;
		startDate.disabled = 'dates' === mode;
		repeatStart.hidden = 'dates' === mode;
		weekly.hidden = 'weekly' !== mode;
		monthDay.hidden = 'monthly' !== mode;
		customDates.hidden = 'dates' !== mode;
		var weeklyBoxes = weekly.querySelectorAll('input[type="checkbox"]');
		weeklyBoxes.forEach(function (checkbox) { checkbox.required = false; });
		var dateInputs = dateList.querySelectorAll('input[type="date"]');
		if ('dates' === mode && !dateInputs.length) {
			addDate('');
			dateInputs = dateList.querySelectorAll('input[type="date"]');
		}
		dateInputs.forEach(function (input, index) { input.required = 'dates' === mode && 0 === index; });
		slotList.querySelectorAll('input[name$="[start]"]').forEach(function (input, index) { input.required = repeating && 0 === index; });
	}

	frequency.addEventListener('change', refreshRequirements);
	form.addEventListener('change', function (event) {
		if (event.target.matches('[data-hherm-weekdays] input[type="checkbox"]')) refreshRequirements();
	});
	form.addEventListener('click', function (event) {
		if (event.target.closest('[data-hherm-add-date]')) addDate('');
		if (event.target.closest('[data-hherm-add-slot]')) addSlot('', '');
		if (event.target.closest('[data-hherm-remove-date]')) {
			var dateRows = dateList.querySelectorAll('.hherm-calendar-date-row');
			if (dateRows.length > 1) event.target.closest('.hherm-calendar-date-row').remove();
			else dateRows[0].querySelector('input').value = '';
			refreshRequirements();
		}
		if (event.target.closest('[data-hherm-remove-slot]')) {
			var slotRows = slotList.querySelectorAll('.hherm-calendar-time-row');
			if (slotRows.length > 1) event.target.closest('.hherm-calendar-time-row').remove();
			else slotRows[0].querySelectorAll('input').forEach(function (input) { input.value = ''; });
			refreshRequirements();
		}
	});

	slotList.dataset.nextIndex = String(slotList.querySelectorAll('.hherm-calendar-time-row').length);
	refreshRequirements();
}());
