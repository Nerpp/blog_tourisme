import '../styles/public-detail.css';
import '../styles/public-detail-gallery.css';
import '../styles/public-route-map.css';

import { initPublicDetailGallery } from '../js/public-detail-gallery.js';

initPublicDetailGallery();

let panoramaModulePromise = null;
let hikeMapModulePromise = null;

const loadPanoramaModule = () => {
  if (!panoramaModulePromise) {
    panoramaModulePromise = import('../js/panorama-viewer.js').catch(() => {
      panoramaModulePromise = null;

      return null;
    });
  }

  return panoramaModulePromise;
};

const loadHikeMapModule = () => {
  if (!hikeMapModulePromise) {
    hikeMapModulePromise = import('../js/public-hike-map.js')
      .then((module) => {
        module.initPublicHikeMaps();

        return module;
      })
      .catch(() => {
        hikeMapModulePromise = null;

        return null;
      });
  }

  return hikeMapModulePromise;
};

document.addEventListener('public-detail:panorama-activate', async (event) => {
  if (!(event.target instanceof Element) || !event.target.matches('.js-panorama-viewer')) {
    return;
  }

  const module = await loadPanoramaModule();

  module?.initPanoramaViewer(event.target);
});

const mapSections = document.querySelectorAll('.public-route-map');

if (mapSections.length > 0) {
  if ('IntersectionObserver' in window) {
    const mapObserver = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) {
        return;
      }

      mapObserver.disconnect();
      loadHikeMapModule();
    }, {
      rootMargin: '400px 0px',
      threshold: 0.01,
    });

    mapSections.forEach((section) => mapObserver.observe(section));
  } else {
    loadHikeMapModule();
  }

  document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) {
      return;
    }

    const trigger = event.target.closest('[data-hike-map-focus], [data-hike-map-load]');
    if (!(trigger instanceof HTMLAnchorElement)) {
      return;
    }

    event.preventDefault();
    const module = await loadHikeMapModule();
    if (!module) {
      return;
    }

    if (trigger.matches('[data-hike-map-focus]')) {
      module.focusPublicHikeMapPoint(trigger);

      return;
    }

    const targetSelector = trigger.getAttribute('href');
    if (targetSelector?.startsWith('#')) {
      document.querySelector(targetSelector)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
}
