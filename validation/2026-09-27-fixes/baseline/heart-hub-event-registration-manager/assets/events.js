(() => {
  const interactive = 'a,button,input,select,textarea,label,summary';
  document.addEventListener('click', event => {
    const row = event.target.closest('[data-event-url]');
    if (!row || event.target.closest(interactive) || window.getSelection().toString()) return;
    if (event.ctrlKey || event.metaKey) window.open(row.dataset.eventUrl, '_blank', 'noopener');
    else window.location.assign(row.dataset.eventUrl);
  });
  document.addEventListener('keydown', event => {
    if (!event.target.matches('[data-event-url]') || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    window.location.assign(event.target.dataset.eventUrl);
  });
})();
