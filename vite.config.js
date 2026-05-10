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
      },
    },
  },
}));
