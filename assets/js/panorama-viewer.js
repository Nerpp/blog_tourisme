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

export function initPanoramaViewers() {
  const init = () => {
    const pannellum = window.pannellum;
    const viewers = document.querySelectorAll('.js-panorama-viewer');
    const useMobilePanorama = window.matchMedia('(max-width: 768px)').matches;

    if (!pannellum || typeof pannellum.viewer !== 'function') {
      viewers.forEach((element) => markUnavailable(element));

      return;
    }

    viewers.forEach((element) => {
      const panoramaUrl = useMobilePanorama && element.dataset.panoramaMobileUrl
        ? element.dataset.panoramaMobileUrl
        : element.dataset.panoramaUrl;

      if (!panoramaUrl || element.dataset.panoramaInitialized === 'true') {
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

        if (viewer && typeof viewer.on === 'function') {
          viewer.on('load', () => {
            element.classList.remove('is-loading');
            element.classList.add('is-loaded');
          });

          viewer.on('error', () => {
            markUnavailable(element);
          });
        } else {
          element.classList.remove('is-loading');
          element.classList.add('is-loaded');
        }
      } catch (error) {
        markUnavailable(element);
      }
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
}
