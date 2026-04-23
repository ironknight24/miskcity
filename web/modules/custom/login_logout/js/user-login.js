/**
 * @file
 * Password visibility toggle for user login (floating password fields).
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
