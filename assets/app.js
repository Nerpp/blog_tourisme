import './styles/app.css';
import './styles/home.css';
import './styles/navbar.css';
import './styles/public-detail.css';
import './styles/destination.css';
import './styles/article-index.css';
import './styles/article-show.css';

import { initNavbar } from './js/navbar.js';
import { initPublicDetailGallery } from './js/public-detail-gallery.js';
import { initDestinationBrowser } from './js/destination-browser';
import { initPanoramaViewers } from './js/panorama-viewer.js';
import { initRelatedArticleModals } from './js/related-article-modal.js';
import { initArticleSearch } from './js/article-search.js';
import { initDestinationShowSearch } from './js/destination-show-search.js';

initNavbar();
initPublicDetailGallery();
initDestinationBrowser();
initPanoramaViewers();
initRelatedArticleModals();
initArticleSearch();
initDestinationShowSearch();
const vueRoot = document.querySelector('#app');

if (vueRoot) {
  Promise.all([
    import('@mdi/font/css/materialdesignicons.css'),
    import('vuetify/styles'),
    import('vue'),
    import('./vue/App.vue'),
    import('./vue/plugins/vuetify'),
  ]).then(([, , { createApp }, { default: App }, { vuetify }]) => {
    createApp(App)
      .use(vuetify)
      .mount(vueRoot);
  });
}
