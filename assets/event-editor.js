(() => {
  'use strict';
  const addressInput = document.querySelector('[data-hherm-address-autocomplete]');
  const addressStatus = document.querySelector('[data-address-status]');
  const container = document.querySelector('[data-expectations]');
  const template = document.querySelector('[data-expectation-template]');
  const addButton = document.querySelector('[data-add-expectation]');
  const count = document.querySelector('[data-expectation-count]');
	const form = document.querySelector('.hherm-event-form');
	const errorNotice = document.querySelector('[data-event-error]');
	const dateStatus = document.querySelector('[data-date-status]');
	const confirmedDateFields = [...document.querySelectorAll('[data-confirmed-date-field]')];
	const registrationType = document.querySelector('[data-registration-type]');
	const registrationControls = document.querySelector('[data-registration-controls]');
	const registrationEnabled = document.querySelector('input[name="registration_enabled"]');
	const interestContactSwitch = document.querySelector('[data-interest-contact-switch]');
	const registrationLockMessage = document.querySelector('[data-registration-lock-message]');
	const websiteRegistrationFields = document.querySelector('[data-website-registration-fields]');
	const externalRegistrationField = document.querySelector('[data-external-registration-field]');
	const showExpectations = document.querySelector('input[name="show_what_to_expect"]');
	const expectationFields = document.querySelector('[data-what-to-expect-fields]');
	const raffle = document.querySelector('input[name="raffle"]');
	const raffleCostField = document.querySelector('[data-raffle-cost-field]');
	const externalFeeToggle = document.querySelector('input[name="external_fee_enabled"]');
	const internalFeeFields = document.querySelector('[data-internal-fee-fields]');
	const externalFeeFields = document.querySelector('[data-external-fee-fields]');
	const featuredImageId = document.querySelector('input[name="featured_image_id"]');
	const featuredImagePreview = document.querySelector('[data-featured-image-preview]');
	const selectFeaturedImage = document.querySelector('[data-select-featured-image]');
	const removeFeaturedImage = document.querySelector('[data-remove-featured-image]');
	const eventCategories = document.querySelector('[data-event-categories]');

	const setConditionalState = (wrapper, visible) => {
		if (!wrapper) return;
		wrapper.hidden = !visible;
		wrapper.querySelectorAll('input,select,textarea,button').forEach(field => { field.disabled = !visible; });
	};
	const setSwitchGroupState = (wrapper, enabled) => {
		if (!wrapper) return;
		wrapper.classList.toggle('is-disabled', !enabled);
		wrapper.querySelectorAll('input[type="checkbox"]').forEach(field => {
			if (!enabled) field.checked = false;
			field.disabled = !enabled;
		});
	};
	let registrationStateInitialised = false;
	let registrationWasAvailable = false;
	const syncRegistrationState = () => {
		if (!registrationType) return;
		const isWebsiteRegistration = registrationType.value === 'website_registration';
		const isConfirmed = !dateStatus || dateStatus.value === 'confirmed';
		const registrationAvailable = isWebsiteRegistration && isConfirmed;
		const interestContactAvailable = isWebsiteRegistration && !isConfirmed;
		setConditionalState(websiteRegistrationFields, isWebsiteRegistration);
		setConditionalState(externalRegistrationField, registrationType.value === 'external_registration');
		setSwitchGroupState(registrationControls, registrationAvailable);
		if (registrationEnabled && registrationStateInitialised && registrationAvailable && !registrationWasAvailable) registrationEnabled.checked = true;
		setSwitchGroupState(interestContactSwitch, interestContactAvailable);
		if (registrationLockMessage) registrationLockMessage.hidden = !interestContactAvailable;
		registrationWasAvailable = registrationAvailable;
		registrationStateInitialised = true;
	};
	const syncDateStatus = () => {
		if (!dateStatus) return;
		const isConfirmed = dateStatus.value === 'confirmed';
		confirmedDateFields.forEach(wrapper => {
			wrapper.hidden = !isConfirmed;
			const input = wrapper.querySelector('input');
			if (input) {
				input.disabled = !isConfirmed;
				input.required = false;
			}
		});
	};
	if (dateStatus) dateStatus.addEventListener('change', () => { syncDateStatus(); syncRegistrationState(); });
	if (registrationType) registrationType.addEventListener('change', syncRegistrationState);
	syncDateStatus();
	syncRegistrationState();

	if (showExpectations && expectationFields) {
		const syncExpectations = () => {
			expectationFields.hidden = !showExpectations.checked;
			expectationFields.querySelectorAll('[data-expectation-row] input,[data-expectation-row] textarea').forEach(field => {
				field.required = showExpectations.checked;
			});
		};
		showExpectations.addEventListener('change', syncExpectations);
		syncExpectations();
	}

	if (raffle && raffleCostField) {
		const syncRaffle = () => {
			raffleCostField.hidden = !raffle.checked;
			const input = raffleCostField.querySelector('input');
			if (input) input.disabled = !raffle.checked;
		};
		raffle.addEventListener('change', syncRaffle);
		syncRaffle();
	}

	if (externalFeeToggle) {
		const syncExternalFee = () => {
			setConditionalState(internalFeeFields, !externalFeeToggle.checked);
			setConditionalState(externalFeeFields, externalFeeToggle.checked);
		};
		externalFeeToggle.addEventListener('change', syncExternalFee);
		syncExternalFee();
	}

	if (featuredImageId && featuredImagePreview && selectFeaturedImage && removeFeaturedImage && window.wp?.media) {
		let mediaFrame;
		const clearFeaturedImage = () => {
			featuredImageId.value = '';
			featuredImagePreview.replaceChildren();
			removeFeaturedImage.hidden = true;
		};
		selectFeaturedImage.addEventListener('click', () => {
			if (!mediaFrame) {
				mediaFrame = window.wp.media({
					title: 'Select featured image',
					button: { text: 'Use featured image' },
					library: { type: 'image' },
					multiple: false,
				});
				mediaFrame.on('select', () => {
					const attachment = mediaFrame.state().get('selection').first().toJSON();
					if (!attachment?.id) return;
					featuredImageId.value = String(attachment.id);
					const imageUrl = attachment.sizes?.medium?.url || attachment.sizes?.thumbnail?.url || attachment.url;
					featuredImagePreview.replaceChildren();
					if (imageUrl) {
						const image = document.createElement('img');
						image.src = imageUrl;
						image.alt = attachment.alt || '';
						featuredImagePreview.appendChild(image);
					}
					removeFeaturedImage.hidden = false;
				});
			}
			mediaFrame.open();
		});
		removeFeaturedImage.addEventListener('click', clearFeaturedImage);
	}

  const eventType = document.querySelector('[data-event-type]');
  const fundraising = document.querySelector('[data-fundraising-fields]');
  const syncCategories = () => {
    if (!eventCategories || eventCategories.dataset.newEvent !== '1') return;
    const isFundraising = eventType ? eventType.value === 'fundraising' : eventCategories.dataset.fundraisingSelected === '1';
    eventCategories.hidden = isFundraising;
    eventCategories.querySelectorAll('input,select,textarea').forEach(field => { field.disabled = isFundraising; });
  };
  if (eventType && fundraising) {
    const syncType = () => {
      const visible = eventType.value === 'fundraising' || (eventType.value === 'keep' && eventType.dataset.keepFundraising === '1');
      fundraising.hidden = !visible;
      fundraising.querySelector('fieldset').disabled = !visible;
      syncCategories();
    };
    eventType.addEventListener('change', syncType);
    syncType();
  } else {
    syncCategories();
  }
  showSubmittedError();
  initialiseAddressAutocomplete();
  if (!container || !template || !addButton) return;
  const max = Number(container.dataset.max || 6);

  function rows() {
    return [...container.querySelectorAll('[data-expectation-row]')];
  }

  function refresh() {
    const current = rows();
    current.forEach((row, index) => {
      const number = row.querySelector('[data-row-number]');
      if (number) number.textContent = String(index + 1);
      row.querySelectorAll('[name]').forEach(field => {
        field.name = field.name.replace(/what_to_expect\[[^\]]+\]/, `what_to_expect[${index}]`);
      });
    });
    addButton.disabled = current.length >= max;
    if (count) count.textContent = `${current.length} of ${max}`;
  }

  addButton.addEventListener('click', () => {
    const current = rows();
    if (current.length >= max) return;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(current.length)).trim();
    const row = wrapper.firstElementChild;
    if (!row) return;
    container.appendChild(row);
    refresh();
    const firstInput = row.querySelector('input');
    if (firstInput) firstInput.focus();
  });

  container.addEventListener('click', event => {
    const remove = event.target.closest('[data-remove-expectation]');
    if (!remove) return;
    remove.closest('[data-expectation-row]')?.remove();
    refresh();
  });

  refresh();

  function initialiseAddressAutocomplete() {
    if (!addressInput) return;
    if (!window.HHERM_EVENT_EDITOR?.addressAutocomplete) {
      if (addressStatus) addressStatus.textContent = 'Enter the complete postal address; it will be checked when you save.';
      return;
    }
    let attempts = 0;
    const attach = () => {
      if (window.google?.maps?.places?.Autocomplete) {
        const autocomplete = new window.google.maps.places.Autocomplete(addressInput, { fields: ['formatted_address', 'geometry'], types: ['address'] });
        autocomplete.addListener('place_changed', () => {
          const place = autocomplete.getPlace();
          if (!place?.geometry) {
            if (addressStatus) addressStatus.textContent = 'Choose a complete address from the Google suggestions.';
            return;
          }
          if (place.formatted_address) addressInput.value = place.formatted_address;
          if (addressStatus) addressStatus.textContent = 'Address selected and ready to validate.';
        });
        return true;
      }
      return false;
    };
    const timer = window.setInterval(() => {
      attempts += 1;
      if (attach() || attempts >= 40) {
        window.clearInterval(timer);
        if (attempts >= 40 && !window.google?.maps?.places?.Autocomplete && addressStatus) addressStatus.textContent = 'Enter the complete postal address; it will be checked when you save.';
      }
    }, 250);
    addressInput.addEventListener('input', () => {
      if (addressStatus) addressStatus.textContent = 'Choose a validated address from the suggestions, if available.';
    });
  }

	function showSubmittedError() {
		if (!form || !errorNotice?.dataset.errorField) return;
		const fieldName = errorNotice.dataset.errorField;
		let target = null;
		if (fieldName === 'what_to_expect') {
			target = expectationFields;
			if (target) target.hidden = false;
		} else {
			target = form.querySelector(`[name="${fieldName}"]`);
		}
		if (!target) return;
		const area = target.closest('label') || target;
		let focusTarget = target.matches('input,select,textarea,button') ? target : target.querySelector('input,select,textarea,button');
		if (fieldName === 'what_to_expect') {
			focusTarget = [...target.querySelectorAll('input,textarea')].find(field => !field.value.trim()) || focusTarget;
		}
		area.classList.add('hherm-field-error');
		if (focusTarget) {
			focusTarget.setAttribute('aria-invalid', 'true');
			const describedBy = new Set((focusTarget.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
			describedBy.add(errorNotice.id);
			focusTarget.setAttribute('aria-describedby', [...describedBy].join(' '));
		}
		const message = document.createElement('span');
		message.className = 'hherm-field-error-message';
		message.textContent = errorNotice.textContent.trim();
		area.appendChild(message);
		window.requestAnimationFrame(() => {
			area.scrollIntoView({ behavior: 'smooth', block: 'center' });
			if (focusTarget && !focusTarget.disabled && focusTarget.type !== 'hidden') focusTarget.focus({ preventScroll: true });
		});
	}
})();
