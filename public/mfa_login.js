document.addEventListener('DOMContentLoaded', () => {
  const resendButton = document.querySelector('[data-resend-button]');
  const countdownEl = document.getElementById('emailResendCountdown');
  if (!resendButton || !countdownEl) {
    return;
  }

  const rawSeconds = countdownEl.getAttribute('data-countdown-seconds');
  let remaining = Number.parseInt(rawSeconds ?? '', 10);
  const isLocked = resendButton.getAttribute('data-locked') === 'true';
  if (!Number.isFinite(remaining) || remaining <= 0) {
    return;
  }

  resendButton.disabled = true;

  const updateCountdown = () => {
    if (remaining <= 0) {
      countdownEl.textContent = '';
      if (!isLocked) {
        resendButton.disabled = false;
      }
      window.clearInterval(timerId);
      return;
    }
    countdownEl.textContent = `(${remaining}秒後)`;
    remaining -= 1;
  };

  updateCountdown();
  const timerId = window.setInterval(updateCountdown, 1000);
});
