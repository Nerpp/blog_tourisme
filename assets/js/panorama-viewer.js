import 'pannellum/build/pannellum.css';
import 'pannellum';

const unavailableMessage = 'La vue 360° n’est pas disponible sur cet appareil, mais vous pouvez consulter l’image en aperçu.';

function markUnavailable(element, message = unavailableMessage) {
  element.classList.remove('is-loading');
  element.classList.add('is-unavailable');

  const fallbackMessage = element.querySelector('[data-panorama-fallback-message]');
  if (fallbackMessage) {
    fallbackMessage.textContent = message;
  }
}

function refreshViewer(element) {
  requestAnimationFrame(() => {
    const viewer = element.publicDetailPanoramaViewer;

    if (viewer && typeof viewer.resize === 'function') {
      viewer.resize();
    }

    window.dispatchEvent(new Event('resize'));
  });
}

function isHiddenGalleryViewer(element) {
  const modal = element.closest('.js-gallery-modal');

  return modal && (modal.hidden || modal.getAttribute('aria-hidden') === 'true');
}

function panoramaUrlForElement(element) {
  const useMobilePanorama = window.matchMedia('(max-width: 768px)').matches;

  return useMobilePanorama && element.dataset.panoramaMobileUrl
    ? element.dataset.panoramaMobileUrl
    : element.dataset.panoramaUrl;
}

export function initPanoramaViewer(element) {
  const pannellum = window.pannellum;

  if (!pannellum || typeof pannellum.viewer !== 'function') {
    markUnavailable(element);

    return;
  }

  if (element.dataset.panoramaInitialized === 'true') {
    refreshViewer(element);

    return;
  }

  const panoramaUrl = panoramaUrlForElement(element);

  if (!panoramaUrl) {
    return;
  }

  element.dataset.panoramaInitialized = 'true';
  element.classList.add('is-loading');

  try {
    const viewer = pannellum.viewer(element, {
      type: 'equirectangular',
      panorama: panoramaUrl,
      autoLoad: true,
      compass: true,
      showZoomCtrl: true,
      mouseZoom: true,
      keyboardZoom: true,
      draggable: true,
    });

    element.publicDetailPanoramaViewer = viewer;

    if (viewer && typeof viewer.on === 'function') {
      viewer.on('load', () => {
        element.classList.remove('is-loading');
        element.classList.add('is-loaded');
        refreshViewer(element);
      });

      viewer.on('error', () => {
        markUnavailable(element);
      });
    } else {
      element.classList.remove('is-loading');
      element.classList.add('is-loaded');
      refreshViewer(element);
    }
  } catch (error) {
    markUnavailable(element);
  }
}

export function initPanoramaViewers() {
  const init = () => {
    const viewers = document.querySelectorAll('.js-panorama-viewer');

    viewers.forEach((element) => {
      if (isHiddenGalleryViewer(element)) {
        return;
      }

      initPanoramaViewer(element);
    });

  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}
