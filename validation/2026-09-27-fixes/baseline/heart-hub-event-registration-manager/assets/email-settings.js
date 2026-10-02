(() => {
  'use strict';

  const scrollKey = 'hherm-email-template-scroll';
  const tabs = document.querySelector('.hherm-email-tabs');

  if (tabs) {
    try {
      const savedScroll = window.sessionStorage.getItem(scrollKey);
      window.sessionStorage.removeItem(scrollKey);
      if (savedScroll !== null && Number.isFinite(Number(savedScroll))) {
        const restoreScroll = () => window.requestAnimationFrame(() => window.scrollTo(0, Number(savedScroll)));
        if (document.readyState === 'complete') restoreScroll();
        else window.addEventListener('load', restoreScroll, { once: true });
      }
    } catch (error) {
      // The tab URLs retain an anchor fallback when session storage is unavailable.
    }
  }

  document.addEventListener('click', event => {
    const tab = event.target.closest('.hherm-email-tabs a');
    if (tab && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
      try {
        window.sessionStorage.setItem(scrollKey, String(window.scrollY));
      } catch (error) {
        // The destination URL still returns to the email-template anchor.
      }
    }

    const button = event.target.closest('[data-hherm-media]');
    if (!button || !window.wp || !wp.media) return;
    event.preventDefault();
    const target = document.getElementById(button.dataset.target);
    if (!target) return;
    const frame = wp.media({ title: 'Choose image', button: { text: 'Use this image' }, multiple: false, library: { type: 'image' } });
    frame.on('select', () => {
      const attachment = frame.state().get('selection').first().toJSON();
      if (attachment && attachment.url) target.value = attachment.url;
    });
    frame.open();
  });
})();
