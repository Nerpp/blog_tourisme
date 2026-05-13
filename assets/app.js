import './styles/app.css';
import './styles/home.css';

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
