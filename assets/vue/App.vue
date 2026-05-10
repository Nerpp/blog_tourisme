<template>
  <v-app>
    <v-main>
      <section class="home-shell">
        <div class="home-copy">
          <p class="eyebrow">Symfony + Vite + Vue 3 + Vuetify</p>
          <h1>Blog Tourisme</h1>
          <p class="intro">
            Une base locale propre pour développer le back Symfony, compiler les assets avec Vite et afficher les emails dans Mailpit.
          </p>
          <div class="actions">
            <v-btn color="primary" prepend-icon="mdi-compass-outline" variant="flat">
              Explorer
            </v-btn>
            <v-btn color="secondary" prepend-icon="mdi-email-outline" variant="tonal">
              Tester Mailpit
            </v-btn>
          </div>
        </div>

        <v-card class="status-panel" elevation="0">
          <v-card-title>Services locaux</v-card-title>
          <v-list density="comfortable">
            <v-list-item prepend-icon="mdi-language-php" title="Symfony" subtitle="http://localhost:8080" />
            <v-list-item prepend-icon="mdi-database" title="MySQL" subtitle="Service Docker: mysql" />
            <v-list-item prepend-icon="mdi-vuejs" title="Vite dev server" subtitle="http://localhost:5173" />
            <v-list-item prepend-icon="mdi-email-fast-outline" title="Mailpit" subtitle="http://localhost:8025" />
          </v-list>
        </v-card>
      </section>
    </v-main>
  </v-app>
</template>
