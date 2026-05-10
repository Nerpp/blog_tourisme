import '@mdi/font/css/materialdesignicons.css';
import 'vuetify/styles';
import './styles/app.css';

import { createApp } from 'vue';

import App from './vue/App.vue';
import { vuetify } from './vue/plugins/vuetify';

createApp(App)
  .use(vuetify)
  .mount('#app');
