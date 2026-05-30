function syncReplyPanel(panel, focusTextarea = false) {
  const toggle = panel.querySelector('[data-comment-reply-toggle]');
  const form = panel.querySelector('[data-comment-reply-form]');
  const textarea = form ? form.querySelector('textarea') : null;
  const isOpen = panel.open;

  if (toggle) {
    toggle.hidden = isOpen;
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  }

  if (isOpen && focusTextarea && textarea) {
    textarea.focus({ preventScroll: true });
  }
}

export function initCommentReplyForms() {
  document.querySelectorAll('[data-comment-reply-panel]').forEach((panel) => {
    const form = panel.querySelector('[data-comment-reply-form]');
    const cancelButton = panel.querySelector('[data-comment-reply-cancel]');

    panel.addEventListener('toggle', () => {
      syncReplyPanel(panel, panel.open);
    });

    if (cancelButton) {
      cancelButton.addEventListener('click', (event) => {
        event.preventDefault();

        if (form) {
          form.reset();
        }

        panel.open = false;
        syncReplyPanel(panel);
      });
    }

    syncReplyPanel(panel);
  });
}
