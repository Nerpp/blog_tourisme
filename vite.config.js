import { fileURLToPath, URL } from 'node:url';

import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import vuetify from 'vite-plugin-vuetify';

export default defineConfig(({ command }) => ({
  base: command === 'serve' ? '/' : '/build/',
  plugins: [
    vue(),
    vuetify({ autoImport: true }),
  ],
  publicDir: false,
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./assets', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    cors: true,
    hmr: {
      host: 'localhost',
      protocol: 'ws',
    },
    watch: {
      usePolling: true,
    },
  },
  build: {
    outDir: 'public/build',
    manifest: 'manifest.json',
    emptyOutDir: true,
    assetsDir: 'assets',
    rollupOptions: {
      input: {
        app: fileURLToPath(new URL('./assets/app.js', import.meta.url)),
        home: fileURLToPath(new URL('./assets/entries/home.js', import.meta.url)),
        auth: fileURLToPath(new URL('./assets/entries/auth.js', import.meta.url)),
        comments: fileURLToPath(new URL('./assets/entries/comments.js', import.meta.url)),
        destination: fileURLToPath(new URL('./assets/entries/destination.js', import.meta.url)),
        articleIndex: fileURLToPath(new URL('./assets/entries/article-index.js', import.meta.url)),
        articleShow: fileURLToPath(new URL('./assets/entries/article-show.js', import.meta.url)),
        publicDetail: fileURLToPath(new URL('./assets/entries/public-detail.js', import.meta.url)),
        relatedArticles: fileURLToPath(new URL('./assets/entries/related-articles.js', import.meta.url)),
        profile: fileURLToPath(new URL('./assets/entries/profile.js', import.meta.url)),
        admin: fileURLToPath(new URL('./assets/js/admin.js', import.meta.url)),
        adminHighPrecisionGps: fileURLToPath(new URL('./assets/js/admin-high-precision-gps.js', import.meta.url)),
        adminPlaceGps: fileURLToPath(new URL('./assets/js/admin-place-gps.js', import.meta.url)),
        locationGeopointPicker: fileURLToPath(new URL('./assets/js/location-geopoint-picker.js', import.meta.url)),
        previsionDestinationMap: fileURLToPath(new URL('./assets/js/prevision-destination-map.js', import.meta.url)),
        studioVideoThumbnails: fileURLToPath(new URL('./assets/js/studio-video-thumbnails.js', import.meta.url)),
      },
    },
  },
}));
