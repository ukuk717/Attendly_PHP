document.addEventListener('DOMContentLoaded', () => {
  const headers = document.querySelectorAll('.app-header');
  headers.forEach((header, index) => {
    const toggle = header.querySelector('.menu-toggle');
    const nav = header.querySelector('.app-header__nav');
    if (!toggle || !nav) {
      return;
    }

    header.classList.add('app-header--has-toggle');

    if (!nav.id) {
      nav.id = `appHeaderNav-${index}`;
    }
    toggle.setAttribute('aria-controls', nav.id);
    toggle.setAttribute('aria-expanded', 'false');

    let backdrop = header.querySelector('.app-header__backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'app-header__backdrop';
      header.insertBefore(backdrop, nav);
    }

    const openMenu = () => {
      toggle.setAttribute('aria-expanded', 'true');
      nav.classList.add('is-open');
      backdrop.classList.add('is-active');
      document.body.classList.add('menu-open');
    };

    const closeMenu = () => {
      toggle.setAttribute('aria-expanded', 'false');
      nav.classList.remove('is-open');
      backdrop.classList.remove('is-active');
      document.body.classList.remove('menu-open');
    };

    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      if (expanded) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    backdrop.addEventListener('click', closeMenu);

    nav.querySelectorAll('a, button').forEach((element) => {
      element.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
          closeMenu();
        }
      });
    });

    window.addEventListener('resize', () => {
      if (window.innerWidth > 768) {
        closeMenu();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        closeMenu();
      }
    });
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const confirmTargets = document.querySelectorAll('form[data-confirm-message]');
  confirmTargets.forEach((form) => {
    form.addEventListener('submit', (event) => {
      const message = form.getAttribute('data-confirm-message');
      if (!message) {
        return;
      }
      if (!window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    });
  });

  const clickableConfirmTargets = Array.from(document.querySelectorAll('[data-confirm-message]')).filter((element) => element.tagName !== 'FORM');
  clickableConfirmTargets.forEach((element) => {
    element.addEventListener('click', (event) => {
      const message = element.getAttribute('data-confirm-message');
      if (!message) {
        return;
      }
      if (!window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
      }

      const confirmField = element.getAttribute('data-confirm-field');
      if (!confirmField) {
        return;
      }
      const confirmValue = element.getAttribute('data-confirm-value') || '1';
      const form = element.form || null;
      if (!form) {
        return;
      }
      const existing = form.querySelector(`input[type="hidden"][name="${confirmField}"]`);
      if (existing) {
        existing.value = confirmValue;
        return;
      }
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = confirmField;
      hidden.value = confirmValue;
      form.appendChild(hidden);
    });
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const copyButtons = document.querySelectorAll('[data-copy-text]');
  copyButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      const text = button.getAttribute('data-copy-text') || '';
      if (!text) {
        return;
      }

      const showToast = (message, variant = 'success') => {
        let container = document.querySelector('.toast-container');
        if (!container) {
          container = document.createElement('div');
          container.className = 'toast-container';
          document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `toast toast--${variant}`;
        toast.textContent = message;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        container.appendChild(toast);

        window.setTimeout(() => {
          toast.classList.add('toast--hide');
          window.setTimeout(() => toast.remove(), 250);
        }, 1400);
      };

      try {
        await navigator.clipboard.writeText(text);
        showToast('コピーしました。', 'success');
      } catch (error) {
        showToast('コピーに失敗しました。', 'error');
        window.prompt('コピーしてください:', text);
      }
    });
  });
});
