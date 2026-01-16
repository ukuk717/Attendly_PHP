(() => {
  const form = document.querySelector('form[data-recaptcha-enabled="1"]');
  if (!form) {
    return;
  }
  const siteKey = form.getAttribute('data-recaptcha-site-key');
  if (!siteKey || typeof grecaptcha === 'undefined') {
    return;
  }

  let pending = false;
  form.addEventListener('submit', (event) => {
    if (pending) {
      return;
    }
    event.preventDefault();
    pending = true;

    grecaptcha.ready(() => {
      grecaptcha.execute(siteKey, { action: 'login' }).then((token) => {
        const input = form.querySelector('input[name="g-recaptcha-response"]');
        if (input) {
          input.value = token;
        }
        pending = false;
        form.submit();
      }).catch(() => {
        pending = false;
        form.submit();
      });
    });
  });
})();
