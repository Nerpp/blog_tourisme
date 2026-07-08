export function initRegisterAvatarPreview() {
  const init = () => {
    const input = document.querySelector('[data-avatar-input]');
    const preview = document.querySelector('[data-avatar-preview]');
    const image = document.querySelector('[data-avatar-preview-image]');

    if (!input || !preview || !image) {
      return;
    }

    input.addEventListener('change', () => {
      const file = input.files && input.files[0] ? input.files[0] : null;
      if (!file || !file.type.startsWith('image/')) {
        image.hidden = true;
        image.removeAttribute('src');
        preview.classList.remove('has-image');
        return;
      }

      image.src = URL.createObjectURL(file);
      image.hidden = false;
      preview.classList.add('has-image');
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}
