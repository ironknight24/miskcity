/**
 * @file
 * Password visibility toggle for user registration (floating password fields).
 */

function attachPasswordToggle() {
  document.addEventListener('click', handlePasswordToggle);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', attachPasswordToggle);
} else {
  attachPasswordToggle();
}

function handlePasswordToggle(event) {
  const button = event.target.closest(
    'button[aria-label="Toggle password visibility"]',
  );
  if (!button) {
    return;
  }

  const wrapper = button.closest('.relative');
  if (!wrapper) {
    return;
  }

  const input = wrapper.querySelector(
    'input[type="password"], input[type="text"]',
  );
  if (!input) {
    return;
  }

  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';

  const showIcon = button.querySelector('.icon-show');
  const hideIcon = button.querySelector('.icon-hide');
  if (!showIcon || !hideIcon) {
    return;
  }

  showIcon.classList.toggle('hidden', !isPassword);
  hideIcon.classList.toggle('hidden', isPassword);
}


(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    const newPasswordInput     = document.getElementById('edit-password');
    const confirmPasswordInput = document.getElementById('edit-confirm-password');
    const newTooltip           = document.getElementById('password-tooltip-edit-password');
    const confirmTooltip       = document.getElementById('password-tooltip-edit-confirm-password');

    // Scope querySelector to each tooltip to avoid duplicate ID conflicts
    function getRuleEl(tooltip, id) {
      return tooltip ? tooltip.querySelector('#' + id) : null;
    }

    const ruleChecks = [
      { id: 'rule-length-confirm',    test: (v) => v.length >= 8 },
      { id: 'rule-uppercase-confirm', test: (v) => /[A-Z]/.test(v) },
      { id: 'rule-lowercase-confirm', test: (v) => /[a-z]/.test(v) },
      { id: 'rule-number-confirm',    test: (v) => /[0-9]/.test(v) },
      { id: 'rule-special-confirm',   test: (v) => /[@!$#%^&*]/.test(v) },
    ];

    function validateRules(tooltip, value, includeMatch) {
      ruleChecks.forEach(({ id, test }) => {
        const el = getRuleEl(tooltip, id);
        if (!el) return;
        el.classList.toggle('text-green-600', test(value));
        el.classList.toggle('text-red-600',  !test(value));
      });

      if (includeMatch) {
        const matchEl = getRuleEl(tooltip, 'passmatch');
        if (matchEl) {
          const matched = value !== '' && value === newPasswordInput.value;
          matchEl.classList.toggle('text-green-600', matched);
          matchEl.classList.toggle('text-red-600',  !matched);
        }
      }
    }

    function showTooltip(tooltip) {
      if (!tooltip) return;
      tooltip.classList.remove('invisible', 'opacity-0');
      tooltip.classList.add('visible', 'opacity-100');
    }

    function hideTooltip(tooltip) {
      if (!tooltip) return;
      tooltip.classList.add('invisible', 'opacity-0');
      tooltip.classList.remove('visible', 'opacity-100');
    }

    // --- Password field ---
    if (newPasswordInput && newTooltip) {
      newPasswordInput.addEventListener('focus', () => showTooltip(newTooltip));
      newPasswordInput.addEventListener('blur',  () => hideTooltip(newTooltip));
      newPasswordInput.addEventListener('input', () => {
        validateRules(newTooltip, newPasswordInput.value, false);

        // Re-check confirm tooltip live if user edits the password field after
        if (confirmPasswordInput && confirmPasswordInput.value) {
          validateRules(confirmTooltip, confirmPasswordInput.value, true);
        }
      });
    }

    // --- Confirm password field ---
    if (confirmPasswordInput && confirmTooltip) {
      confirmPasswordInput.addEventListener('focus', () => showTooltip(confirmTooltip));
      confirmPasswordInput.addEventListener('blur',  () => hideTooltip(confirmTooltip));
      confirmPasswordInput.addEventListener('input', () => {
        validateRules(confirmTooltip, confirmPasswordInput.value, true);
      });
    }

  });

})();