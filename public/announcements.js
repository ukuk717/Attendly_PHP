(() => {
  const modal = document.querySelector('[data-announcement-modal]');
  if (!modal) {
    return;
  }

  const closeModal = () => {
    modal.classList.remove('is-active');
    modal.setAttribute('aria-hidden', 'true');
  };

  const closeButtons = modal.querySelectorAll('[data-modal-close]');
  closeButtons.forEach((button) => {
    button.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeModal();
    }
  });
})();
