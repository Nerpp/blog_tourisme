import './styles/app.css';
import './styles/comments.css';
import './styles/auth.css';
import './styles/home.css';
import './styles/navbar.css';
import './styles/public-detail.css';
import './styles/public-detail-gallery.css';
import './styles/public-route-map.css';
import './styles/related-articles.css';
import './styles/destination.css';
import './styles/article-index.css';
import './styles/article-show.css';
import './styles/profile.css';
import './js/studio-video-thumbnails.js';

import { initNavbar } from './js/navbar.js';
import { initPublicDetailGallery } from './js/public-detail-gallery.js';
import { initDestinationBrowser } from './js/destination-browser';
import { initPanoramaViewers } from './js/panorama-viewer.js';
import { initRelatedArticleModals } from './js/related-article-modal.js';
import { initArticleSearch } from './js/article-search.js';
import { initDestinationShowSearch } from './js/destination-show-search.js';
import { initProfileAvatarPreview } from './js/profile-avatar-preview.js';
import { initCommentReplies } from './js/comment-replies.js';
import { initCommentReplyForms } from './js/comment-reply-forms.js';

initNavbar();
initPublicDetailGallery();
initDestinationBrowser();
initPanoramaViewers();
initRelatedArticleModals();
initArticleSearch();
initDestinationShowSearch();
initProfileAvatarPreview();
initCommentReplies();
initCommentReplyForms();

if (document.querySelector('[data-public-hike-map]')) {
  import('./js/public-hike-map.js').then(({ initPublicHikeMaps }) => {
    initPublicHikeMaps();
  });
}

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
