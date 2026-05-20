export function initRelatedArticleModals() {
  const modals = document.querySelectorAll('.js-related-article-modal');

  modals.forEach((modal) => {
    const closeButtons = modal.querySelectorAll('.js-related-article-close');
    let previousFocus = null;

    const open = (trigger) => {
      previousFocus = trigger;
      modal.hidden = false;
      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('has-related-article-modal');

      const closeButton = modal.querySelector('.js-related-article-close');
      if (closeButton) {
        closeButton.focus();
      }
    };

    const close = () => {
      modal.hidden = true;
      modal.setAttribute('hidden', '');
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('has-related-article-modal');

      if (previousFocus && typeof previousFocus.focus === 'function') {
        previousFocus.focus();
      }
    };

    closeButtons.forEach((button) => {
      button.addEventListener('click', close);
    });

    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        close();
      }
    });

    modal.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        close();
      }
    });

    const openButtons = document.querySelectorAll(`[data-related-article-target="#${modal.id}"]`);
    openButtons.forEach((button) => {
      button.addEventListener('click', () => open(button));
    });
  });
}
