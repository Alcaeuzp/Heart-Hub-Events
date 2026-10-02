(function () {
	'use strict';

	function closeCard(card) {
		var button = card.querySelector('[data-hherm-event-trigger]');
		var popup = button ? card.querySelector('.hherm-calendar__popup') : null;
		if (!button || !popup) return;
		card.classList.remove('is-open');
		button.setAttribute('aria-expanded', 'false');
		popup.hidden = true;
	}

	function openCard(card) {
		var button = card.querySelector('[data-hherm-event-trigger]');
		var popup = button ? card.querySelector('.hherm-calendar__popup') : null;
		if (!button || !popup) return;
		card.classList.add('is-open');
		button.setAttribute('aria-expanded', 'true');
		popup.hidden = false;
	}

	// Height of any fixed or sticky site header covering the top of the viewport.
	function stickyOffset() {
		var bottom = 0;
		if ( ! document.elementsFromPoint ) return 0;
		[ window.innerWidth / 2, 12 ].forEach( function ( x ) {
			document.elementsFromPoint( x, 1 ).forEach( function ( element ) {
				for ( var node = element; node && node !== document.body && node !== document.documentElement; node = node.parentElement ) {
					var position = window.getComputedStyle( node ).position;
					if ( 'fixed' !== position && 'sticky' !== position ) continue;
					var rect = node.getBoundingClientRect();
					if ( rect.top <= 1 && rect.bottom > bottom && rect.height < window.innerHeight / 2 ) bottom = rect.bottom;
					break;
				}
			} );
		} );
		return Math.round( bottom ) + 16;
	}

	function applyScrollOffset(calendar) {
		if ( calendar ) calendar.style.setProperty( '--hherm-calendar-scroll-offset', stickyOffset() + 'px' );
	}

	function refreshCalendar(calendar, targetUrl, focusMonth) {
		if (!calendar || !calendar.dataset.ajaxUrl) return Promise.resolve();
		var requestId = (Number( calendar.dataset.requestId ) || 0) + 1;
		calendar.dataset.requestId = String( requestId );
		calendar.setAttribute( 'aria-busy', 'true' );
		var status = calendar.querySelector( '.hherm-calendar__status' );
		if ( status ) status.textContent = '';
		var url = new URL( targetUrl || window.location.href, window.location.href );
		var form = new URLSearchParams();
		form.set( 'action', 'hherm_calendar_month' );
		form.set( 'month', url.searchParams.get( 'hherm_calendar_month' ) || '' );
		form.set( 'base_url', calendar.dataset.baseUrl || window.location.href );
		form.set( 'title', calendar.dataset.heading || '' );
		form.set( 'show_heading', calendar.dataset.showHeading || '0' );
		form.set( 'filter', calendar.dataset.filter || 'all' );
		form.set( 'months_behind', calendar.dataset.monthsBehind || '' );

		return fetch( calendar.dataset.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: form.toString()
		} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					if ( ! response.ok || ! payload.success || ! payload.data || ! payload.data.html ) {
						throw new Error( 'Calendar update failed.' );
					}
					return payload;
				} );
			} )
			.then( function ( payload ) {
				if ( calendar.dataset.requestId !== String( requestId ) ) return;
				var parsed = new DOMParser().parseFromString( payload.data.html, 'text/html' );
				var updated = parsed.querySelector( '[data-hherm-calendar]' );
				if ( ! updated ) throw new Error( 'Calendar response was empty.' );
				if ( targetUrl ) {
					window.history.pushState( { hhermCalendarMonth: payload.data.month }, '', targetUrl );
					lastLocation = window.location.pathname + window.location.search;
				}
				calendar.replaceWith( updated );
				if ( focusMonth ) {
					var monthHeading = updated.querySelector( '.hherm-calendar__month' );
					if ( monthHeading ) {
						var offset = stickyOffset();
						monthHeading.focus( { preventScroll: true } );
						var top = monthHeading.getBoundingClientRect().top;
						// Keep the new month's heading visible below any sticky site header.
						if ( top < offset || top > window.innerHeight - 40 ) window.scrollBy( 0, top - offset );
					}
				}
			} )
			.catch( function () {
				if ( calendar.dataset.requestId !== String( requestId ) ) return;
				calendar.removeAttribute( 'aria-busy' );
				var errorStatus = calendar.querySelector( '.hherm-calendar__status' );
				if ( errorStatus ) errorStatus.textContent = 'The calendar could not be updated. Please try again.';
			} );
	}

	// Mobile day links jump to the agenda; set the sticky-header offset first so scroll-margin applies.
	document.addEventListener( 'click', function ( event ) {
		var dayLink = event.target.closest( '.hherm-calendar__day-link[href^="#"]' );
		if ( dayLink ) applyScrollOffset( dayLink.closest( '[data-hherm-calendar]' ) );
	}, true );

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '[data-hherm-calendar-nav]' );
		if ( ! link ) return;
		var calendar = link.closest( '[data-hherm-calendar]' );
		if ( ! calendar || ! calendar.dataset.ajaxUrl ) return;
		event.preventDefault();
		if ( 'true' === calendar.getAttribute( 'aria-busy' ) ) return;
		refreshCalendar( calendar, link.href, true );
	} );

	// Month changes alter the query string; mobile day links only change the #fragment.
	var lastLocation = window.location.pathname + window.location.search;
	window.addEventListener( 'popstate', function () {
		var current = window.location.pathname + window.location.search;
		if ( current === lastLocation ) return;
		lastLocation = current;
		document.querySelectorAll( '[data-hherm-calendar]' ).forEach( function ( calendar ) {
			refreshCalendar( calendar, null, false );
		} );
	} );

	document.addEventListener('mouseover', function (event) {
		var card = event.target.closest('[data-hherm-event-card]');
		if (card && card.closest('[data-popups="1"]')) openCard(card);
	});

	document.addEventListener('mouseout', function (event) {
		var card = event.target.closest('[data-hherm-event-card]');
		if (!card || !card.closest('[data-popups="1"]') || card.contains(event.relatedTarget)) return;
		if (!card.contains(document.activeElement) && !card.classList.contains('is-pinned')) closeCard(card);
	});

	document.addEventListener('focusin', function (event) {
		var card = event.target.closest('[data-hherm-event-card]');
		if (card && card.closest('[data-popups="1"]')) openCard(card);
	});

	document.addEventListener('focusout', function (event) {
		var card = event.target.closest('[data-hherm-event-card]');
		if (!card || card.contains(event.relatedTarget)) return;
		if (!card.classList.contains('is-pinned')) closeCard(card);
	});

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('[data-hherm-event-trigger]');
		if (trigger) {
			var card = trigger.closest('[data-hherm-event-card]');
			var is_open = 'true' === trigger.getAttribute('aria-expanded');
			document.querySelectorAll('[data-hherm-event-card].is-pinned').forEach(function (open_card) {
				if (open_card !== card) {
					open_card.classList.remove('is-pinned');
					closeCard(open_card);
				}
			});
			if (is_open && card.classList.contains('is-pinned')) {
				card.classList.remove('is-pinned');
				closeCard(card);
			} else {
				card.classList.add('is-pinned');
				openCard(card);
			}
			return;
		}

		if (!event.target.closest('[data-hherm-event-card]')) {
			document.querySelectorAll('[data-hherm-event-card].is-pinned').forEach(function (card) {
				card.classList.remove('is-pinned');
				closeCard(card);
			});
		}
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' !== event.key) return;
		document.querySelectorAll('[data-hherm-event-card].is-open').forEach(function (card) {
			card.classList.remove('is-pinned');
			closeCard(card);
		});
	});
}());
