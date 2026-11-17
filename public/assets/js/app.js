(() => {
  const getCsrf = () => {
    const metaName = document.querySelector('meta[name="csrf-token-name"]');
    const metaHash = document.querySelector('meta[name="csrf-token"]');
    const field = document.querySelector('input[name^="csrf_"]');

    return {
      name: metaName?.content || field?.name || '',
      hash: metaHash?.content || field?.value || '',
    };
  };

  const syncCsrf = (name, hash) => {
    if (!name || !hash) {
      return;
    }

    const metaName = document.querySelector('meta[name="csrf-token-name"]');
    const metaHash = document.querySelector('meta[name="csrf-token"]');
    if (metaName) {
      metaName.content = name;
    }
    if (metaHash) {
      metaHash.content = hash;
    }

    document.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
      input.value = hash;
    });
  };

  const validators = {
    required: (value, _arg, label) => (value.trim() ? null : `${label} is required.`),
    min: (value, arg, label) =>
      value.trim().length >= Number(arg) ? null : `${label} must be at least ${arg} characters.`,
    max: (value, arg, label) =>
      value.trim().length <= Number(arg) ? null : `${label} must be at most ${arg} characters.`,
    email: (value, _arg, label) =>
      !value.trim() || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())
        ? null
        : `${label} must be a valid email.`,
    mobile: (value, _arg, label) =>
      !value.trim() || /^[0-9+\-\s]{8,20}$/.test(value.trim())
        ? null
        : `${label} must be 8–20 digits (you may use + - spaces).`,
    decimal: (value, _arg, label) =>
      !value.trim() || /^(?:\d+)(?:\.\d+)?$/.test(value.trim())
        ? null
        : `${label} must be a valid number.`,
    gt: (value, arg, label) => {
      if (!value.trim()) {
        return null;
      }
      const num = Number(value);
      return Number.isFinite(num) && num > Number(arg)
        ? null
        : `${label} must be greater than ${arg}.`;
    },
    match: (value, arg, label) => {
      const other = document.querySelector(arg);
      if (!other) {
        return null;
      }
      return value === other.value ? null : `${label} does not match.`;
    },
  };

  const parseRules = (ruleString) =>
    (ruleString || '')
      .split('|')
      .map((part) => part.trim())
      .filter(Boolean)
      .map((part) => {
        const [name, arg] = part.split(':');
        return { name, arg };
      });

  const getHint = (field) => {
    const wrap = field.closest('.mb-3, .mb-4, .form-group') || field.parentElement;
    return wrap?.querySelector('[data-validate-hint]') || null;
  };

  const setFieldState = (field, message, ok) => {
    const hint = getHint(field);
    field.classList.remove('is-valid', 'is-invalid');

    if (!field.value.trim() && !message) {
      if (hint && hint.dataset.defaultHint !== undefined) {
        hint.textContent = hint.dataset.defaultHint;
        hint.className = 'field-hint';
      } else if (hint && !hint.hasAttribute('data-sponsor-hint')) {
        hint.textContent = '';
        hint.className = 'field-hint';
      }
      return true;
    }

    if (message) {
      field.classList.add('is-invalid');
      if (hint) {
        hint.textContent = message;
        hint.className = 'field-hint is-invalid';
      }
      return false;
    }

    field.classList.add('is-valid');
    if (hint && !hint.hasAttribute('data-sponsor-hint')) {
      hint.textContent = 'Looks good.';
      hint.className = 'field-hint is-valid';
    }
    return ok !== false;
  };

  const validateField = (field, { force = false } = {}) => {
    const rules = parseRules(field.dataset.validate || '');
    if (rules.length === 0) {
      return true;
    }

    const label = field.dataset.label || field.name || 'This field';
    const value = field.value || '';

    if (!force && !value.trim() && !field.classList.contains('is-invalid')) {
      setFieldState(field, null, true);
      return true;
    }

    for (const rule of rules) {
      const fn = validators[rule.name];
      if (!fn) {
        continue;
      }
      const error = fn(value, rule.arg, label);
      if (error) {
        setFieldState(field, error, false);
        return false;
      }
    }

    setFieldState(field, null, true);
    return true;
  };

  const initLiveValidation = (form) => {
    const fields = Array.from(form.querySelectorAll('[data-validate]'));

    fields.forEach((field) => {
      const hint = getHint(field);
      if (hint && hint.dataset.defaultHint === undefined && hint.textContent.trim()) {
        hint.dataset.defaultHint = hint.textContent.trim();
      }

      field.addEventListener('input', () => validateField(field));
      field.addEventListener('blur', () => validateField(field, { force: true }));
    });

    // Keep confirm-password in sync when password changes.
    const password = form.querySelector('#password, [name="password"]');
    const confirm = form.querySelector('#password_confirm, [name="password_confirm"]');
    if (password && confirm) {
      password.addEventListener('input', () => {
        if (confirm.value.trim()) {
          validateField(confirm, { force: true });
        }
      });
    }

    form.addEventListener('submit', (event) => {
      let ok = true;
      fields.forEach((field) => {
        if (!validateField(field, { force: true })) {
          ok = false;
        }
      });

      if (!ok) {
        event.preventDefault();
        event.stopPropagation();
        const firstInvalid = form.querySelector('.is-invalid');
        firstInvalid?.focus();

        const button = form.querySelector('[type="submit"]');
        if (button) {
          button.disabled = false;
          if (button.dataset.originalText) {
            button.innerHTML = button.dataset.originalText;
          }
        }
      }
    });
  };

  document.querySelectorAll('form[data-live-validate]').forEach(initLiveValidation);

  const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
  const appShell = document.querySelector('[data-app-shell]');

  if (sidebarToggle && appShell) {
    sidebarToggle.addEventListener('click', () => {
      if (window.matchMedia('(max-width: 992px)').matches) {
        appShell.classList.toggle('sidebar-open');
      } else {
        appShell.classList.toggle('is-collapsed');
      }
    });
  }

  const sponsorInput = document.querySelector('[data-sponsor-id]');
  const sponsorName = document.querySelector('[data-sponsor-name]');
  const sponsorHint = document.querySelector('[data-sponsor-hint]');

  if (sponsorInput && sponsorName) {
    let timer = null;

    const validateSponsor = async () => {
      const value = sponsorInput.value.trim();
      if (!value) {
        sponsorName.value = '';
        sponsorInput.classList.remove('is-valid', 'is-invalid');
        if (sponsorHint) {
          sponsorHint.textContent = 'Enter a Sponsor ID to validate.';
          sponsorHint.className = 'field-hint';
        }
        return;
      }

      if (sponsorHint) {
        sponsorHint.innerHTML = '<span class="loading-spinner"></span> Validating sponsor...';
        sponsorHint.className = 'field-hint';
      }

      const csrf = getCsrf();
      const body = new URLSearchParams();
      body.set('sponsor_id', value);
      if (csrf.name && csrf.hash) {
        body.set(csrf.name, csrf.hash);
      }

      try {
        const response = await fetch(sponsorInput.dataset.lookupUrl, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body,
        });

        const data = await response.json();
        if (data.csrf_token_name && data.csrf_hash) {
          syncCsrf(data.csrf_token_name, data.csrf_hash);
        }

        if (data.valid) {
          sponsorName.value = data.name || '';
          sponsorInput.classList.remove('is-invalid');
          sponsorInput.classList.add('is-valid');
          if (sponsorHint) {
            sponsorHint.textContent = 'Sponsor verified.';
            sponsorHint.className = 'field-hint is-valid';
          }
        } else {
          sponsorName.value = '';
          sponsorInput.classList.remove('is-valid');
          sponsorInput.classList.add('is-invalid');
          if (sponsorHint) {
            sponsorHint.textContent = data.message || 'Sponsor validation failed.';
            sponsorHint.className = 'field-hint is-invalid';
          }
        }
      } catch (error) {
        sponsorName.value = '';
        sponsorInput.classList.remove('is-valid');
        sponsorInput.classList.add('is-invalid');
        if (sponsorHint) {
          sponsorHint.textContent = 'Unable to validate sponsor right now.';
          sponsorHint.className = 'field-hint is-invalid';
        }
      }
    };

    sponsorInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(validateSponsor, 450);
    });

    if (sponsorInput.value.trim()) {
      validateSponsor();
    }
  }

  document.querySelectorAll('form[data-loading]').forEach((form) => {
    form.addEventListener('submit', () => {
      const button = form.querySelector('[type="submit"]');
      if (button && !form.querySelector('.is-invalid')) {
        button.disabled = true;
        button.dataset.originalText = button.innerHTML;
        button.innerHTML = '<span class="loading-spinner"></span> Please wait...';
      }
    });
  });

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.getAttribute('data-copy-target') || '');
      const text = target?.textContent?.trim() || '';
      if (!text) {
        return;
      }
      try {
        await navigator.clipboard.writeText(text);
        const original = button.textContent;
        button.textContent = 'Copied';
        setTimeout(() => {
          button.textContent = original || 'Copy Link';
        }, 1500);
      } catch (_err) {
        button.textContent = 'Copy failed';
      }
    });
  });
})();
