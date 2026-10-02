(function () {
  'use strict';

  function updateReason(form) {
    var selected = form.querySelector('input[name="attended_before"]:checked');
    var reasonWrap = form.querySelector('.hherm-support-registration__reason');
    var reason = reasonWrap ? reasonWrap.querySelector('textarea') : null;
    var show = !!selected && selected.value === 'no';

    if (!reasonWrap || !reason) return;
    reasonWrap.hidden = !show;
    reason.required = show;
    form.querySelectorAll('input[name="attended_before"]').forEach(function (input) {
      input.setAttribute('aria-expanded', show ? 'true' : 'false');
    });
    if (!show) reason.value = '';
  }

  function init(form) {
    if (form.getAttribute('data-hherm-support-ready') === 'true') return;
    form.setAttribute('data-hherm-support-ready', 'true');
    form.querySelectorAll('input[name="attended_before"]').forEach(function (input) {
      input.addEventListener('change', function () { updateReason(form); });
    });
    updateReason(form);

    if (!window.fetch || !window.FormData) return;
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var button = form.querySelector('button[type="submit"]');
      var originalButtonText = button ? button.textContent : 'Submit registration';
      var wrapper = form.closest('.hherm-support-registration');
      var existing = wrapper ? wrapper.querySelector('.hherm-support-registration__error') : null;
      var endpoint = form.getAttribute('action') || window.location.href;
      var data = new FormData(form);
      data.append('hherm_ajax', '1');
      if (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'Submitting…';
      }

      fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (response) {
          var message = response && response.data && response.data.message
            ? response.data.message
            : 'We could not submit your application. Please try again.';
          if (response && response.success) {
            if (existing) existing.remove();
            var result = response.data && response.data.result ? response.data.result : '';
            var heading = result.indexOf('interest') === 0 ? 'Expression of interest received' : 'Registration received';
            form.outerHTML = '<div class="hherm-support-registration-message is-success" role="status"><h3></h3><p></p></div>';
            var successHeading = wrapper.querySelector('.hherm-support-registration-message h3');
            var success = wrapper.querySelector('.hherm-support-registration-message p');
            if (successHeading) successHeading.textContent = heading;
            if (success) success.textContent = message;
            return;
          }
          if (!existing && wrapper) {
            existing = document.createElement('div');
            existing.className = 'hherm-support-registration__error';
            existing.setAttribute('role', 'alert');
            form.parentNode.insertBefore(existing, form);
          }
          if (existing) existing.textContent = message;
          if (button) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = originalButtonText;
          }
        })
        .catch(function () {
          if (!existing && wrapper) {
            existing = document.createElement('div');
            existing.className = 'hherm-support-registration__error';
            existing.setAttribute('role', 'alert');
            form.parentNode.insertBefore(existing, form);
          }
          if (existing) existing.textContent = 'We could not submit your application. Please check your connection and try again.';
          if (button) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = originalButtonText;
          }
        });
    });
  }

  document.querySelectorAll('[data-hherm-support-form]').forEach(init);
  if (window.MutationObserver) {
    new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          if (!node || node.nodeType !== 1) return;
          if (node.matches && node.matches('[data-hherm-support-form]')) init(node);
          if (node.querySelectorAll) node.querySelectorAll('[data-hherm-support-form]').forEach(init);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }
})();
