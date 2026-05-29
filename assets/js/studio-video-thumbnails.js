const YOUTUBE_ID_PATTERN = /^[A-Za-z0-9_-]{6,20}$/;

export function initStudioVideoThumbnails() {
  document.querySelectorAll('[data-video-card]').forEach((card) => {
    if (card.dataset.videoThumbnailReady === 'true') {
      return;
    }

    const input = card.querySelector('[data-video-url-input]');
    const thumbnail = card.querySelector('[data-video-thumbnail]');

    if (!input || !thumbnail) {
      return;
    }

    card.dataset.videoThumbnailReady = 'true';

    const updateThumbnail = () => {
      const videoId = extractYouTubeId(input.value);

      thumbnail.replaceChildren(videoId ? createThumbnailImage(videoId) : createPlaceholder());
    };

    input.addEventListener('input', updateThumbnail);
    input.addEventListener('change', updateThumbnail);
  });
}

function createThumbnailImage(videoId) {
  const image = document.createElement('img');
  image.src = `https://img.youtube.com/vi/${videoId}/hqdefault.jpg`;
  image.alt = 'Miniature de la vidéo';

  return image;
}

function createPlaceholder() {
  const placeholder = document.createElement('div');
  placeholder.className = 'studio-video-card__thumbnail-placeholder';
  placeholder.textContent = 'Vidéo';

  return placeholder;
}

export function extractYouTubeId(url) {
  if (!url || url.trim() === '') {
    return null;
  }

  try {
    const parsed = new URL(url.trim());
    const hostname = parsed.hostname.toLowerCase().replace(/^www\./, '');

    if (hostname === 'youtu.be') {
      const [id] = parsed.pathname.split('/').filter(Boolean);

      return sanitizeVideoId(id);
    }

    if (!['youtube.com', 'youtube-nocookie.com'].includes(hostname)) {
      return null;
    }

    if (parsed.pathname === '/watch') {
      return sanitizeVideoId(parsed.searchParams.get('v'));
    }

    const parts = parsed.pathname.split('/').filter(Boolean);

    for (const key of ['shorts', 'embed', 'live']) {
      const index = parts.indexOf(key);
      if (index !== -1 && parts[index + 1]) {
        return sanitizeVideoId(parts[index + 1]);
      }
    }

    return null;
  } catch (error) {
    return null;
  }
}

function sanitizeVideoId(id) {
  if (!id) {
    return null;
  }

  const cleaned = id.trim();

  return YOUTUBE_ID_PATTERN.test(cleaned) ? cleaned : null;
}

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStudioVideoThumbnails);
  } else {
    initStudioVideoThumbnails();
  }
}
