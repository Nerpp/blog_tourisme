import './styles/app.css';
import './styles/home.css';
import './styles/navbar.css';
import './styles/public-detail.css';

import { initNavbar } from './js/navbar.js';
import { initPublicDetailGallery } from './js/public-detail-gallery.js';

initNavbar();
initPublicDetailGallery();
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