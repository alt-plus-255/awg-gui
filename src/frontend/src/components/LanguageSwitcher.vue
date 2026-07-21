<template>
  <q-btn-dropdown
    flat
    dense
    no-caps
    :ripple="false"
    icon="translate"
    :label="compact ? undefined : locale.currentLabel"
    :dropdown-icon="compact ? undefined : 'arrow_drop_down'"
    content-class="theme-switcher-menu surface-panel"
    menu-anchor="bottom right"
    menu-self="top right"
    :title="t('theme.language')"
  >
    <q-list dense class="theme-switcher-list">
      <q-item-label header class="text-soft-theme">{{ t('theme.language') }}</q-item-label>
      <q-item
        v-for="opt in locale.localeOptions"
        :key="opt.value"
        clickable
        v-close-popup
        :active="locale.locale === opt.value"
        active-class="theme-switcher-item--active"
        @click="locale.setLocale(opt.value)"
      >
        <q-item-section>{{ opt.label }}</q-item-section>
        <q-item-section side v-if="locale.locale === opt.value">
          <q-icon name="check" color="primary" size="18px" />
        </q-item-section>
      </q-item>
    </q-list>
  </q-btn-dropdown>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { useLocaleStore } from '@/stores/locale'

defineProps({
  compact: { type: Boolean, default: false }
})

const { t } = useI18n()
const locale = useLocaleStore()
</script>
