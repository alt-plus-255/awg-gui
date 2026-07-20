<template>
  <q-btn-dropdown
    flat
    dense
    no-caps
    :ripple="false"
    icon="palette"
    :label="compact ? undefined : currentStyleLabel"
    :dropdown-icon="compact ? undefined : 'arrow_drop_down'"
    content-class="theme-switcher-menu surface-panel"
    menu-anchor="bottom right"
    menu-self="top right"
    :title="currentStyleLabel"
  >
    <q-list dense class="theme-switcher-list">
      <q-item-label header class="text-soft-theme">Тема</q-item-label>
      <q-item
        v-for="opt in theme.colorModeOptionList"
        :key="opt.value"
        clickable
        v-close-popup="opt.value !== 'auto'"
        :active="theme.colorMode === opt.value"
        active-class="theme-switcher-item--active"
        @click="theme.setColorMode(opt.value)"
      >
        <q-item-section>{{ opt.label }}</q-item-section>
        <q-item-section side v-if="theme.colorMode === opt.value">
          <q-icon name="check" color="primary" size="18px" />
        </q-item-section>
      </q-item>

      <template v-if="theme.colorMode === 'auto'">
        <q-item class="theme-switcher-hours">
          <q-item-section>
            <div class="row q-col-gutter-xs">
              <div class="col-6">
                <q-input
                  :model-value="theme.autoHours.from"
                  type="time"
                  dense
                  filled
                  label="Светлая с"
                  @update:model-value="onAutoFrom"
                />
              </div>
              <div class="col-6">
                <q-input
                  :model-value="theme.autoHours.to"
                  type="time"
                  dense
                  filled
                  label="до"
                  @update:model-value="onAutoTo"
                />
              </div>
            </div>
          </q-item-section>
        </q-item>
      </template>

      <q-separator class="q-my-xs" />

      <q-item-label header class="text-soft-theme">Стиль</q-item-label>
      <q-item
        v-for="opt in theme.styleOptions"
        :key="opt.value"
        clickable
        v-close-popup
        :active="theme.styleId === opt.value"
        active-class="theme-switcher-item--active"
        @click="theme.setStyle(opt.value)"
      >
        <q-item-section>{{ opt.label }}</q-item-section>
        <q-item-section side v-if="theme.styleId === opt.value">
          <q-icon name="check" color="primary" size="18px" />
        </q-item-section>
      </q-item>
    </q-list>
  </q-btn-dropdown>
</template>

<script setup>
import { computed } from 'vue'
import { useThemeStore } from '@/stores/theme'

defineProps({
  compact: { type: Boolean, default: false }
})

const theme = useThemeStore()
const currentStyleLabel = computed(() => theme.current.label)

function onAutoFrom (value) {
  theme.setAutoHours({ ...theme.autoHours, from: value })
}

function onAutoTo (value) {
  theme.setAutoHours({ ...theme.autoHours, to: value })
}
</script>

<style>
.theme-switcher-menu.q-menu {
  background: var(--surface-panel) !important;
  color: var(--surface-text) !important;
  border: 1px solid var(--surface-border);
  border-radius: var(--surface-radius);
  overflow: hidden;
  min-width: 220px;
  font-size: 14px;
}
.theme-switcher-list {
  background: var(--surface-panel);
  padding: 4px 0;
  font-size: 14px;
}
.theme-switcher-list .q-item {
  min-height: 36px;
  font-size: 14px;
}
.theme-switcher-list .q-item__label {
  font-size: 14px;
  line-height: 1.43;
}
.theme-switcher-list .q-item-label {
  font-size: 12px;
  padding: 4px 16px 2px;
}
.theme-switcher-item--active {
  background: var(--surface-highlight-bg) !important;
  color: var(--surface-highlight) !important;
}
.theme-switcher-hours {
  padding-top: 0;
  padding-bottom: 8px;
}
</style>
