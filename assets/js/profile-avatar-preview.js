export function initProfileAvatarPreview() {
  const input = document.querySelector('[data-avatar-preview-input]');
  const previewImages = document.querySelectorAll('[data-avatar-preview-image]');
  const initials = document.querySelectorAll('[data-avatar-preview-initials]');
  const message = document.querySelector('[data-avatar-preview-message]');

  if (!input || previewImages.length === 0) {
    return;
  }

  if (input.dataset.avatarPreviewReady === 'true') {
    return;
  }

  input.dataset.avatarPreviewReady = 'true';

  let currentObjectUrl = null;

  const originalImages = Array.from(previewImages).map((image) => ({
    image,
    src: image.getAttribute('src') || '',
    hidden: image.classList.contains('is-hidden'),
  }));

  const originalInitials = Array.from(initials).map((initial) => ({
    initial,
    hidden: initial.classList.contains('is-hidden'),
  }));

  function revokeCurrentObjectUrl() {
    if (currentObjectUrl !== null) {
      URL.revokeObjectURL(currentObjectUrl);
      currentObjectUrl = null;
    }
  }

  function restoreInitialState() {
    revokeCurrentObjectUrl();

    originalImages.forEach(({ image, src, hidden }) => {
      image.setAttribute('src', src);
      image.classList.toggle('is-hidden', hidden);
    });

    originalInitials.forEach(({ initial, hidden }) => {
      initial.classList.toggle('is-hidden', hidden);
    });

    if (message) {
      message.classList.add('is-hidden');
    }
  }

  input.addEventListener('change', () => {
    const file = input.files && input.files.length > 0 ? input.files[0] : null;

    if (!file) {
      restoreInitialState();
      return;
    }

    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

    if (!allowedTypes.includes(file.type)) {
      restoreInitialState();
      return;
    }

    revokeCurrentObjectUrl();

    currentObjectUrl = URL.createObjectURL(file);

    previewImages.forEach((image) => {
      image.src = currentObjectUrl;
      image.classList.remove('is-hidden');
    });

    initials.forEach((initial) => {
      initial.classList.add('is-hidden');
    });

    if (message) {
      message.classList.remove('is-hidden');
    }
  });

  window.addEventListener('beforeunload', revokeCurrentObjectUrl);
}