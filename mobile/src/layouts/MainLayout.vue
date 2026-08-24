<template>
  <q-layout view="hHh lpR fFf" class="bg-dark text-white">
    <q-header elevated class="bg-secondary">
      <q-toolbar>
        <q-toolbar-title shrink class="text-weight-bold">
          AWG-GUI
        </q-toolbar-title>
        <q-space />
        <q-btn
          flat
          dense
          :label="locale === 'ru' ? 'EN' : 'RU'"
          @click="toggleLocale"
        />
      </q-toolbar>
      <q-tabs
        v-model="tab"
        dense
        align="left"
        class="text-grey-4"
        active-color="primary"
        indicator-color="primary"
        outside-arrows
        mobile-arrows
      >
        <q-route-tab name="faq" :label="t('nav.faq')" :to="{ name: 'faq' }" />
        <q-route-tab name="install" :label="t('nav.install')" :to="{ name: 'install' }" />
        <q-route-tab
          v-if="connection.hasPanel"
          name="panel"
          :label="t('nav.panel')"
          :to="{ name: 'panel' }"
        />
      </q-tabs>
    </q-header>

    <q-page-container>
      <router-view />
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { LOCALE_STORAGE_KEY } from '@/i18n'
import { useConnectionStore } from '@/stores/connection'

const route = useRoute()
const { t, locale } = useI18n()
const connection = useConnectionStore()

const tab = computed(() => {
  if (route.name === 'terminal') return 'install'
  return String(route.name || 'faq')
})

function toggleLocale () {
  const next = locale.value === 'ru' ? 'en' : 'ru'
  locale.value = next
  try {
    localStorage.setItem(LOCALE_STORAGE_KEY, next)
  } catch {
    // ignore
  }
}
</script>
