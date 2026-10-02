(() => {
  'use strict';
  const app = document.getElementById('hherm-app');
  if (!app || !window.HHERM) return;
  const results = app.querySelector('.hherm-results');
  const message = app.querySelector('.hherm-message');
  const pagination = app.querySelector('.hherm-pagination');
  const resultCount = app.querySelector('[data-result-count]');
  const modal = app.querySelector('.hherm-modal');
  const dialog = modal.querySelector('[role="dialog"]');
  const content = modal.querySelector('.hherm-modal-content');
  const exportButton = document.querySelector('[data-export-applications]');
  let page = 1, lastFocus = null, events = new Map(), currentItems = [], selected = new Map(), timer, loadController = null, loadGeneration = 0, modalController = null, modalGeneration = 0, modalId = '';
  const esc = value => { const el = document.createElement('div'); el.textContent = value == null ? '' : String(value); return el.innerHTML.replaceAll('"', '&quot;').replaceAll("'", '&#39;'); };
  const request = async (url, options = {}) => { const response = await fetch(url, { credentials: 'same-origin', ...options, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': HHERM.nonce, ...(options.headers || {}) } }); const data = await response.json().catch(() => ({})); if (!response.ok) throw new Error(data.message || 'Something went wrong.'); return data; };
  const params = () => { const p = new URLSearchParams({ page, per_page: 20 }); app.querySelectorAll('[data-filter]').forEach(el => { if (el.value) p.set(el.dataset.filter, el.value); }); return p; };
  const statusLabel = status => status === 'interest' ? 'Expression of interest' : (status === 'no-show' ? 'No-show' : (status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown'));
  const dietaryLabel = value => value === 'yes' ? 'Yes' : (value === 'no' ? 'No' : 'Not recorded');
  const field = (label, value) => value !== undefined && value !== null && String(value) !== '' ? `<dt>${esc(label)}</dt><dd>${esc(value)}</dd>` : '';
	const moneyLabel = value => { const text = value == null ? '' : String(value).trim(); return text && /^\d+(?:\.\d+)?$/.test(text) ? `$${Number(text).toFixed(2)}` : text; };
	const attendanceLabel = (value, registrationStatus = '') => value === 'attended' ? 'Attended' : (value === 'partial' ? 'Partially attended' : (value === 'no-show' ? 'Did not attend' : (value === 'unknown' ? 'Not recorded' : (['declined','interest','waitlist','pending'].includes(registrationStatus) ? 'Not applicable' : 'Not recorded'))));
	function historySection(history, currentId, error = '') {
		if (error) return `<section class="hherm-customer-history"><h3>Customer event history</h3><p class="hherm-error" role="status">${esc(error)}</p></section>`;
		const rows = history.length ? history.map(item => {
			const amount = moneyLabel(item.payment_amount);
			const payment = amount ? `Amount ${amount}` : 'No amount recorded';
			const paymentNote = [item.payment_status ? statusLabel(item.payment_status) : 'Payment status not recorded', item.payment_method, !amount && item.event?.fee ? `Event fee ${item.event.fee}` : ''].filter(Boolean).join(' · ');
			const current = String(item.id) === String(currentId);
			return `<tr class="${current ? 'is-current' : ''}"><td><strong>${esc(item.event?.title || 'Event unavailable')}</strong><small>${esc(item.event?.date || '')}</small></td><td><span class="hherm-status hherm-status--${esc(item.registration_status)}">${esc(statusLabel(item.registration_status))}</span><small>${esc(item.registration_date || 'Date not recorded')} · Party ${esc(item.party_size || 1)}</small></td><td>${esc(attendanceLabel(item.attendance_status, item.registration_status))}${item.checked_in_at ? `<small>Checked in ${esc(item.checked_in_at)}</small>` : ''}</td><td>${esc(payment)}${paymentNote ? `<small>${esc(paymentNote)}</small>` : ''}</td><td>${current ? '<span class="hherm-current-record">Current</span>' : `<button type="button" class="button button-small" data-history-id="${esc(item.id)}">Open</button>`}</td></tr>`;
		}).join('') : '<tr><td colspan="5">No other event registrations were found for this email address.</td></tr>';
		return `<section class="hherm-customer-history"><div><h3>Customer event history</h3><p>Every registration for this email address, ordered from oldest to newest.</p></div><div class="hherm-history-table-wrap"><table><thead><tr><th>Event</th><th>Registration</th><th>Attendance</th><th>Payment</th><th><span class="screen-reader-text">Action</span></th></tr></thead><tbody>${rows}</tbody></table></div></section>`;
	}
  function setStats(stats) {
    const set = (key, value, note) => { const valueEl = app.querySelector(`[data-stat="${key}"]`); const noteEl = app.querySelector(`[data-stat-note="${key}"]`); if (valueEl) valueEl.textContent = value; if (noteEl && note) noteEl.textContent = note; };
    const count = (n, one, many) => `${n || 0} ${Number(n) === 1 ? one : many}`;
    set('pending', stats.pending || 0, `${count(stats.pending_places, 'place', 'places')} held`);
    set('waitlist', stats.waitlist || 0, `${count(stats.waitlist_places, 'place', 'places')} wanted`);
    set('approved_week', stats.approved_week || 0, Number(stats.approved_week) === 1 ? 'new approval this week' : 'new approvals this week');
    set('capacity', `${stats.capacity_percent || 0}%`, `across ${count(stats.open_events, 'open event', 'open events')}`);
  }
  function renderEventFilter() { const select = app.querySelector('[data-filter="event_id"]'); const current = select.value; select.innerHTML = '<option value="">All events</option>' + [...events].sort((a,b) => a[1].localeCompare(b[1])).map(([id,title]) => `<option value="${esc(id)}">${esc(title)}</option>`).join(''); select.value = current; }
  function renderRows(items) {
    const head = '<thead><tr><th class="hherm-check-cell"><input type="checkbox" data-select-all aria-label="Select all applications"></th><th>Applicant</th><th>Event</th><th class="hherm-number-cell">Party</th><th>Registered</th><th>Status</th><th><span class="screen-reader-text">Action</span></th></tr></thead>';
    const body = items.length ? items.map(row => `<tr><td class="hherm-check-cell"><input type="checkbox" data-select="${row.id}" aria-label="Select ${esc(row.name)}" ${selected.has(String(row.id)) ? 'checked' : ''}></td><td><strong>${esc(row.name || 'Unnamed applicant')}</strong><small>${esc(row.email)}</small></td><td><strong>${esc(row.event?.title || 'Event unavailable')}</strong><small>${esc(row.event?.date || '')}</small></td><td class="hherm-number-cell">${esc(row.attendees || '—')}</td><td>${esc(row.registration_date || '—')}</td><td><span class="hherm-status hherm-status--${esc(row.status)}">${esc(statusLabel(row.status))}</span></td><td class="hherm-table-action"><button type="button" class="hherm-review" data-id="${row.id}">Open</button></td></tr>`).join('') : '<tr class="hherm-empty-row"><td colspan="7">No event registrations match these filters.</td></tr>';
    return `<div class="hherm-table-wrap"><table aria-label="Event registration applications">${head}<tbody>${body}</tbody></table></div>`;
  }
  function updateSelection() {
    const bar = app.querySelector('[data-selection-bar]'); const count = selected.size; const places = [...selected.values()].reduce((sum, row) => sum + Number(row.attendees || 0), 0);
    if (bar) { bar.hidden = count < 2; bar.setAttribute('aria-hidden', count < 2 ? 'true' : 'false'); }
    const countEl = app.querySelector('[data-selected-count]'); const placesEl = app.querySelector('[data-selected-places]'); if (countEl) countEl.textContent = count; if (placesEl) placesEl.textContent = places;
    const all = app.querySelector('[data-select-all]'); if (all) { all.checked = currentItems.length > 0 && currentItems.every(row => selected.has(String(row.id))); all.indeterminate = currentItems.some(row => selected.has(String(row.id))) && !all.checked; }
  }
  async function load() {
    if (!results) {
      const response = await fetch(window.location.href, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Saved, but the event summary could not refresh. Please reload this page.');
      const documentCopy = new DOMParser().parseFromString(await response.text(), 'text/html');
      const updated = documentCopy.querySelector('[data-event-content]');
      if (!updated) throw new Error('Saved, but the event summary could not refresh. Please reload this page.');
      app.querySelector('[data-event-content]').replaceWith(updated);
      lastFocus = [...app.querySelectorAll('.hherm-review')].find(button => button.dataset.id === modalId) || lastFocus;
      return true;
    }
    const generation = ++loadGeneration;
    if (loadController) loadController.abort();
    loadController = new AbortController();
    results.innerHTML = '<div class="hherm-loading"><span>Loading applications…</span></div>'; message.textContent = ''; message.className = 'hherm-message'; resultCount.textContent = 'Loading…'; pagination.hidden = true;
    try {
      const data = await request(`${HHERM.root}?${params()}`, { signal: loadController.signal }); if (generation !== loadGeneration) return false; currentItems = data.items || []; (data.events || []).forEach(i => { if (i && i.id) events.set(String(i.id), i.title); }); renderEventFilter(); setStats(data.stats || {}); resultCount.textContent = `${data.total} ${data.total === 1 ? 'application' : 'applications'}`; results.innerHTML = renderRows(currentItems); const start = data.total ? ((page - 1) * 20) + 1 : 0; const end = Math.min(data.total, page * 20); pagination.innerHTML = `<span>Showing ${start}–${end} of ${data.total}</span><div>${page > 1 ? '<button type="button" data-page="prev">Previous</button>' : ''}${page < data.pages ? '<button type="button" data-page="next">Next</button>' : ''}</div>`; pagination.hidden = !data.total; updateSelection(); return true;
    } catch (e) { if (e.name === 'AbortError' || generation !== loadGeneration) return false; currentItems = []; selected.clear(); results.innerHTML = ''; resultCount.textContent = ''; pagination.hidden = true; updateSelection(); message.textContent = e.message; message.className = 'hherm-message hherm-error'; return false; }
  }
	async function open(id, options = {}) { const generation = ++modalGeneration; if (modalController) modalController.abort(); modalController = new AbortController(); modalId = String(id); lastFocus = document.activeElement; if (!options.deferModal) { content.innerHTML = '<p>Loading…</p>'; modal.hidden = false; document.body.classList.add('hherm-modal-open'); dialog.focus(); } try { const data = await request(`${HHERM.root}/${id}`, { signal: modalController.signal }); if (generation !== modalGeneration) return; content.innerHTML = detail(data.application, data.event, data.history || [], data.history_error || ''); if (options.deferModal) { modal.hidden = false; document.body.classList.add('hherm-modal-open'); dialog.focus(); } } catch(e) { if (e.name === 'AbortError' || generation !== modalGeneration) return; if (options.silentMissing && /not found/i.test(e.message)) { const url = new URL(window.location.href); url.searchParams.delete('registration_id'); window.history.replaceState({}, '', url); modalId = ''; return; } content.innerHTML = `<p class="hherm-error">${esc(e.message)}</p>`; if (options.deferModal) { modal.hidden = false; document.body.classList.add('hherm-modal-open'); dialog.focus(); } } }
	function detail(a, e, history = [], historyError = '') { const reviewable = ['pending','waitlist'].includes(a.registration_status); const capacity = Number(e?.capacity || 0), remaining = e?.remaining === null || e?.remaining === undefined ? null : Number(e.remaining), used = remaining === null ? 0 : Math.max(0, capacity - remaining), percent = capacity ? Math.min(100, used / capacity * 100) : 0; return `<div class="hherm-modal-header"><div><span class="hherm-status hherm-status--${esc(a.registration_status)}">${esc(statusLabel(a.registration_status))}</span><h2 id="hherm-modal-title">${esc(`${a.first_name || ''} ${a.last_name || ''}`.trim() || 'Unnamed applicant')}</h2><p>Registered ${esc(a.registration_date || '—')} · Record #${esc(a._ID)}</p></div></div><div class="hherm-detail-grid"><section><h3>Applicant</h3><dl>${field('Email',a.email)}${field('Phone',a.phone)}${field('Party size',a.number_of_attendees)}${field('Registered',a.registration_date)}${field('Attendance',attendanceLabel(a.attendance_status, a.registration_status))}${field('Amount paid',moneyLabel(a.payment_amount))}${field('Payment status',a.payment_status)}${field('Payment method',a.payment_method)}${field('Dietary requirements',dietaryLabel(a.dietaryrequirements))}${field('Dietary details',a.please_let_us_know)}${field('Reason',a.reason_for_attending)}${field('Source form',a.form_id || 'JetFormBuilder')}</dl></section><section class="hherm-capacity-panel"><h3>Event &amp; capacity</h3><strong>${esc(e?.title || 'Event unavailable')}</strong><p>${esc([e?.date,e?.venue].filter(Boolean).join(' · '))}</p>${e?.fee ? `<p><strong>Event fee:</strong> ${esc(e.fee)} <small>(this does not confirm payment)</small></p>` : ''}${a.registration_status === 'interest' ? '<p>This expression of interest does not reserve a place. The customer must register when registration opens.</p>' : remaining !== null ? `<div class="hherm-capacity-label"><span>${used} of ${capacity} places reserved</span><span>${Math.max(0, remaining)} remaining</span></div><div class="hherm-meter"><span style="width:${percent}%"></span></div><div class="hherm-capacity-note">Approving this application holds ${esc(a.number_of_attendees || 1)} of the remaining places. Declining returns them once.</div>` : ''}</section></div>${historySection(history, a._ID, historyError)}${editForm(a)}${reviewable ? `<form class="hherm-review-form" data-id="${esc(a._ID)}"><label>Reviewer notes / decline reason (optional)<textarea name="approval_notes" rows="2" placeholder="Recorded in the audit log with your name and the decision date."></textarea></label><p class="hherm-form-help">A branded decision email is sent or logged automatically. A blank decline reason uses a generic message.</p><div class="hherm-actions"><button type="button" class="button" data-close>Cancel</button><button type="submit" name="status" value="declined" class="hherm-decline">Decline</button><button type="submit" name="status" value="approved" class="hherm-approve">Approve</button></div><p class="hherm-form-message" aria-live="polite"></p></form>` : `<section class="hherm-decision"><h3>Decision</h3><dl>${field('Status',a.registration_status)}${field('Reviewer',a.reviewer_name)}${field('Review date',a.reviewed_date)}${field('Notes',a.approval_notes || a.decline_reason)}</dl></section>`}`; }
  function close() { ++modalGeneration; if (modalController) modalController.abort(); modalController = null; modalId = ''; modal.hidden = true; document.body.classList.remove('hherm-modal-open'); if (lastFocus) lastFocus.focus(); }
  function editForm(a) {
    const input = (name, label, type = 'text') => `<label>${label}<input name="${name}" type="${type}" value="${esc(a[name] || '')}"></label>`;
    return `<details class="hherm-applicant-editor"><summary>Edit applicant details</summary><form class="hherm-edit-form" data-id="${esc(a._ID)}"><div class="hherm-edit-grid">${input('first_name', 'First name')}${input('last_name', 'Last name')}${input('email', 'Email', 'email')}${input('phone', 'Phone', 'tel')}${input('organisation', 'Organisation')}<label>Party size<input name="number_of_attendees" type="number" min="1" max="1000" step="1" value="${esc(Math.max(1, Number(a.number_of_attendees) || 1))}" ${a.checked_in_at ? 'readonly' : ''}></label><label>Dietary requirements<select name="dietaryrequirements"><option value="" ${!a.dietaryrequirements ? 'selected' : ''}>Not recorded</option><option value="yes" ${a.dietaryrequirements === 'yes' ? 'selected' : ''}>Yes</option><option value="no" ${a.dietaryrequirements === 'no' ? 'selected' : ''}>No</option></select></label></div><label>Dietary requirement details<textarea name="please_let_us_know" rows="3" maxlength="1000">${esc(a.please_let_us_know || '')}</textarea></label><label>Reason for attending<textarea name="reason_for_attending" rows="3">${esc(a.reason_for_attending || '')}</textarea></label><p class="hherm-form-help">Party size is at least one. Save changes before approving or declining.${a.checked_in_at ? ' Party size is locked after check-in.' : ''}</p><button type="submit" class="button button-primary">Save applicant details</button><p class="hherm-form-message" aria-live="polite"></p></form></details>`;
  }
  app.addEventListener('submit', async e => {
    const form = e.target.closest('.hherm-edit-form');
    if (!form) return;
    e.preventDefault();
    const generation = modalGeneration;
    const buttons = [...content.querySelectorAll('button')];
    buttons.forEach(button => { button.disabled = true; });
    const formMessage = form.querySelector('.hherm-form-message');
    try {
      const fields = Object.fromEntries(new FormData(form));
      fields.number_of_attendees = Math.max(1, Number(fields.number_of_attendees) || 1);
      const data = await request(`${HHERM.root}/${form.dataset.id}/details`, { method: 'POST', body: JSON.stringify(fields) });
      if (generation === modalGeneration) {
		content.innerHTML = detail(data.application, data.event, data.history || [], data.history_error || '');
        const editor = content.querySelector('.hherm-applicant-editor');
        editor.open = true;
        editor.querySelector('.hherm-form-message').textContent = 'Applicant details saved.';
      }
      selected.delete(form.dataset.id);
      await load();
    } catch (error) { formMessage.textContent = error.message; }
    finally { buttons.forEach(button => { button.disabled = false; }); }
  });
  // Exports every application matching the current filters, not just the page on screen.
  async function exportCurrent() {
    if (!exportButton || exportButton.disabled) return;
    const label = exportButton.textContent; exportButton.disabled = true; exportButton.textContent = 'Preparing CSV…';
    if (message) message.textContent = '';
    try {
      const rows = [], seen = new Set(), filters = params(); let exportPage = 1, pages = 1, total = null;
      // Snapshot filters and refuse a partial or changing dataset instead of downloading a misleading CSV.
      const maxRows = 100000;
      do {
        const p = new URLSearchParams(filters); p.set('page', exportPage); p.set('per_page', 50); p.set('include_stats', 'false');
        const data = await request(`${HHERM.root}?${p}`);
        const currentTotal = Number(data.total), currentPages = Number(data.pages);
        if (!Number.isSafeInteger(currentTotal) || currentTotal < 0 || !Number.isSafeInteger(currentPages) || currentPages !== Math.ceil(currentTotal / 50) || !Array.isArray(data.items)) throw new Error('The export response was incomplete. Please try again.');
        if (currentTotal > maxRows) throw new Error(`This export exceeds ${maxRows.toLocaleString()} registrations. Narrow the date or event filters and export each group separately.`);
        if (total !== null && total !== currentTotal) throw new Error('Registrations changed during export. Please try again to get a complete file.');
        total = currentTotal; pages = currentPages;
        for (const row of data.items) {
          const id = String(row.id || '');
          if (!id || seen.has(id)) throw new Error('Registrations changed during export. Please try again to get a complete file.');
          seen.add(id); rows.push(row);
        }
        const expected = Math.min(50, Math.max(0, total - (exportPage - 1) * 50));
        if (data.items.length !== expected) throw new Error('The export response was incomplete. No partial file was downloaded. Please try again.');
        exportPage++;
      } while (exportPage <= pages);
      if (rows.length !== total) throw new Error('The export response was incomplete. No partial file was downloaded. Please try again.');
      if (!rows.length) { message.textContent = 'There are no applications to export for these filters.'; return; }
      const csvCell = value => { let text = String(value ?? ''); if (/^[\t\r\n ]*[=+\-@]/.test(text)) text = "'" + text; return `"${text.replaceAll('"','""')}"`; };
      const csv = [['Applicant','Email','Event','Party','Registered','Status'], ...rows.map(row => [row.name,row.email,row.event?.title || '',row.attendees,row.registration_date,row.status])].map(row => row.map(csvCell).join(',')).join('\r\n');
      const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'})); link.download = 'heart-hub-registrations.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    } catch (e) { message.textContent = e.message || 'The CSV export failed. Please try again.'; }
    finally { exportButton.disabled = false; exportButton.textContent = label; }
  }
	app.addEventListener('click', e => { const review = e.target.closest('[data-id].hherm-review'); if (review) open(review.dataset.id); const historyRecord = e.target.closest('[data-history-id]'); if (historyRecord) open(historyRecord.dataset.historyId); if (e.target.closest('[data-close]')) close(); const p = e.target.closest('[data-page]'); if (p) { page += p.dataset.page === 'next' ? 1 : -1; selected.clear(); load(); } const quick = e.target.closest('[data-quick]'); if (quick) { app.querySelector('[data-filter="status"]').value = quick.dataset.quick; page = 1; selected.clear(); app.querySelectorAll('[data-quick]').forEach(button => button.classList.toggle('is-active', button === quick)); load(); } if (e.target.closest('[data-reset]')) { app.querySelectorAll('[data-filter]').forEach(el => { el.value = el.dataset.filter === 'order' ? 'desc' : ''; }); page = 1; selected.clear(); load(); } if (e.target.closest('[data-clear-selection]')) { selected.clear(); results.innerHTML = renderRows(currentItems); updateSelection(); } const bulk = e.target.closest('[data-bulk]'); if (bulk) bulkReview(bulk.dataset.bulk); });
  app.addEventListener('change', e => { if (e.target.matches('[data-filter]')) { page = 1; selected.clear(); load(); } if (e.target.matches('[data-select-all]')) { currentItems.forEach(row => e.target.checked ? selected.set(String(row.id), row) : selected.delete(String(row.id))); results.innerHTML = renderRows(currentItems); updateSelection(); } if (e.target.matches('[data-select]')) { const row = currentItems.find(item => String(item.id) === e.target.dataset.select); if (e.target.checked && row) selected.set(String(row.id), row); else selected.delete(e.target.dataset.select); updateSelection(); } });
  app.addEventListener('input', e => { if (e.target.matches('[data-filter="search"]')) { clearTimeout(timer); selected.clear(); timer = setTimeout(() => { page = 1; load(); }, 300); } });
  app.addEventListener('submit', async e => { const form = e.target.closest('.hherm-review-form'); if (!form) return; e.preventDefault(); const button = e.submitter; if (!button || !window.confirm(`Mark this application as ${button.value}?`)) return; const reviewedId = String(form.dataset.id); const reviewedGeneration = modalGeneration; const formMessage = form.querySelector('.hherm-form-message'); const actionButtons = [...content.querySelectorAll('button')]; actionButtons.forEach(action => { action.disabled = true; }); try { const data = await request(`${HHERM.root}/${form.dataset.id}/review`, { method: 'POST', body: JSON.stringify({ status: button.value, approval_notes: form.approval_notes.value, decline_reason: form.approval_notes.value }) }); formMessage.textContent = `Application ${data.application.registration_status}. Email: ${data.email.status}.`; selected.delete(form.dataset.id); const loaded = await load(); if (loaded) setTimeout(() => { if (modalId === reviewedId && modalGeneration === reviewedGeneration) close(); }, 900); } catch(err) { formMessage.textContent = err.message; actionButtons.forEach(action => { action.disabled = false; }); } });
  async function bulkReview(status) { const ids = [...selected.keys()]; if (ids.length < 2) return; const note = status === 'declined' ? window.prompt('Decline reason (optional — leave blank to use the generic reason):') : ''; if (status === 'declined' && note === null) return; if (!window.confirm(`${status === 'approved' ? 'Approve' : 'Decline'} ${ids.length} selected applications?`)) return; try { const data = await request(`${HHERM.root}/bulk`, { method: 'POST', body: JSON.stringify({ ids, status, notes: note || '' }) }); const resultText = `${data.succeeded.length} application(s) updated${data.failed.length ? `; ${data.failed.length} could not be updated.` : '.'}`; const resultClass = data.failed.length ? 'hherm-message hherm-error' : 'hherm-message hherm-success'; selected.clear(); const loaded = await load(); if (loaded) { message.textContent = resultText; message.className = resultClass; } } catch (error) { message.textContent = error.message; message.className = 'hherm-message hherm-error'; } }
  document.addEventListener('keydown', e => { if (!modal.hidden && e.key === 'Escape') close(); if (!modal.hidden && e.key === 'Tab') { const focusable = [...dialog.querySelectorAll('button,a,input,select,textarea,[tabindex]:not([tabindex="-1"])')].filter(el => !el.disabled); if (!focusable.length) return; const first=focusable[0], last=focusable[focusable.length-1]; if (e.shiftKey && document.activeElement===first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement===last) { e.preventDefault(); first.focus(); } } });
  if (exportButton) exportButton.addEventListener('click', exportCurrent);
  if (results) load().then(loaded => { if (loaded && HHERM.registrationId) open(HHERM.registrationId, { deferModal: true, silentMissing: true }); });
})();
