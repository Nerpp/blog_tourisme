import { aliases, mdi } from 'vuetify/iconsets/mdi';
import { createVuetify } from 'vuetify';

export const vuetify = createVuetify({
  icons: {
    defaultSet: 'mdi',
    aliases,
    sets: {
      mdi,
    },
  },
  theme: {
    defaultTheme: 'tourismLight',
    themes: {
      tourismLight: {
        dark: false,
        colors: {
          background: '#f7f8f4',
          surface: '#ffffff',
          primary: '#176b65',
          secondary: '#a15c38',
          accent: '#315d9c',
          error: '#b3261e',
          info: '#315d9c',
          success: '#2f7d4f',
          warning: '#a15c38',
        },
      },
    },
  },
});
