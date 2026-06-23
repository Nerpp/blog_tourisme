import '../styles/public-detail.css';
import '../styles/public-detail-gallery.css';
import '../styles/public-route-map.css';

import { initPublicDetailGallery } from '../js/public-detail-gallery.js';

initPublicDetailGallery();

if (document.querySelector('.js-panorama-viewer')) {
  import('../js/panorama-viewer.js').then(({ initPanoramaViewers }) => {
    initPanoramaViewers();
  });
}

if (document.querySelector('[data-public-hike-map]')) {
  import('../js/public-hike-map.js').then(({ initPublicHikeMaps }) => {
    initPublicHikeMaps();
  });
}
