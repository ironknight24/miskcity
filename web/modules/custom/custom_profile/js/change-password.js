document.addEventListener('DOMContentLoaded', () => {
  initPasswordToggle();
  initValidators();
});

/* --------------------------------
 * Password visibility toggle
 * No data attributes required
 * -------------------------------- */
function initPasswordToggle() {
  document.addEventListener('click', handlePasswordToggle);
}

function handlePasswordToggle(event) {
  const button = event.target.closest('button');
  if (!button) return;

  const wrapper = button.closest('.relative');
  if (!wrapper) return;

  const input = wrapper.querySelector('input[type="password"], input[type="text"]');
  if (!input) return;

  togglePassword(input, button);
}

function togglePassword(input, button) {
  const isPassword = input.type === 'password';
  input.type = isPassword ? 'text' : 'password';

  toggleIcons(button, isPassword);
}

function toggleIcons(button, showText) {
  const showIcon = button.querySelector('.icon-show');
  const hideIcon = button.querySelector('.icon-hide');

  if (!showIcon || !hideIcon) return;

  showIcon.classList.toggle('hidden', !showText);
  hideIcon.classList.toggle('hidden', showText);
}

/* --------------------------------
 * Password validation logic
 * -------------------------------- */
function initValidators() {
  const newPasswordInput = document.getElementById('edit-new-password');

  createValidator({
    inputId: 'edit-new-password',
    tooltipId: 'password-tooltip-new',
    rules: getBaseRules('new'),
  });

  createValidator({
    inputId: 'edit-confirm-password',
    tooltipId: 'password-tooltip-confirm',
    matchWith: newPasswordInput,
    rules: {
      ...getBaseRules('confirm'),
      match: {
        el: document.getElementById('passmatch'),
        check: value => value === newPasswordInput.value,
      },
    },
  });
}

function createValidator(config) {
  const input = document.getElementById(config.inputId);
  const tooltip = document.getElementById(config.tooltipId);

  if (!input || !tooltip) return;

  const validate = () => runRules(input.value, config.rules);

  bindTooltipEvents(input, tooltip);
  input.addEventListener('input', validate);

  if (config.matchWith) {
    config.matchWith.addEventListener('input', validate);
  }
}

function runRules(value, rules) {
  for (const rule of Object.values(rules)) {
    applyRule(rule, value);
  }
}

function applyRule(rule, value) {
  if (!rule.el) return;

  const valid = rule.check(value);
  rule.el.classList.toggle('text-green-600', valid);
  rule.el.classList.toggle('text-red-600', !valid);
}

function bindTooltipEvents(input, tooltip) {
  input.addEventListener('focusin', () => showTooltip(tooltip));
  input.addEventListener('focusout', () => hideTooltip(tooltip));
}

function showTooltip(tooltip) {
  tooltip.classList.remove('invisible', 'opacity-0');
  tooltip.classList.add('visible', 'opacity-100');
}

function hideTooltip(tooltip) {
  tooltip.classList.add('invisible', 'opacity-0');
  tooltip.classList.remove('visible', 'opacity-100');
}

/* --------------------------------
 * Rule definitions (reusable)
 * -------------------------------- */
function getBaseRules(suffix) {
  return {
    length: {
      el: document.getElementById(`rule-length-${suffix}`),
      check: value => value.length >= 8,
    },
    uppercase: {
      el: document.getElementById(`rule-uppercase-${suffix}`),
      check: value => /[A-Z]/.test(value),
    },
    lowercase: {
      el: document.getElementById(`rule-lowercase-${suffix}`),
      check: value => /[a-z]/.test(value),
    },
    number: {
      el: document.getElementById(`rule-number-${suffix}`),
      check: value => /\d/.test(value),
    },
    special: {
      el: document.getElementById(`rule-special-${suffix}`),
      check: value => /[!@#$%^&*]/.test(value),
    },
  };
}