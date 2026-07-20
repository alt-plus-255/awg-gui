<template>
  <q-layout view="hHh lpR fFf" class="surface-bg">
    <q-header elevated class="bg-secondary app-header">
      <q-toolbar class="q-px-md">
        <q-btn
          flat
          dense
          round
          icon="menu"
          class="lt-md q-mr-sm"
          aria-label="Меню"
          @click="drawerOpen = !drawerOpen"
        />

        <div class="row items-center q-gutter-sm q-mr-lg no-wrap">
          <img src="/icons/logo.png" alt="AWG-GUI" width="28" height="28" class="app-logo" />
          <div>
            <div class="text-h6 text-weight-bold" style="letter-spacing: 0.02em; line-height: 1.15;">AWG-GUI</div>
            <div class="text-caption text-grey-5 gt-xs">{{ settingsStore.displayEndpoint || '—' }}</div>
          </div>
        </div>

        <q-tabs
          dense
          align="left"
          active-color="primary"
          indicator-color="primary"
          class="text-muted-theme gt-sm desktop-nav-tabs"
          inline-label
        >
          <q-route-tab name="dashboard" label="Дашборд" :to="{ name: 'dashboard' }" exact />
          <q-route-tab name="configs" label="Конфиги и пиры" :to="{ name: 'configs' }" />

          <q-btn-dropdown
            flat
            dense
            no-caps
            stretch
            :ripple="false"
            :label="'Резолвер'"
            :class="['resolver-menu-btn', resolverMenuActive ? 'resolver-menu-btn--active text-primary' : 'text-muted-theme']"
            content-class="resolver-dropdown surface-panel"
            menu-anchor="bottom middle"
            menu-self="top middle"
          >
            <q-list class="resolver-dropdown-list">
              <q-item
                clickable
                v-close-popup
                :active="route.name === 'resolver'"
                class="resolver-dropdown-item"
                active-class="resolver-dropdown-item--active"
                @click="router.push({ name: 'resolver' })"
              >
                <q-item-section avatar>
                  <q-icon name="alt_route" size="18px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label>Резолвер</q-item-label>
                  <q-item-label caption class="text-grey-5">Точечный обход</q-item-label>
                </q-item-section>
              </q-item>

              <q-separator />

              <q-item
                clickable
                v-close-popup
                :active="route.name === 'resolver-connections'"
                class="resolver-dropdown-item"
                active-class="resolver-dropdown-item--active"
                @click="router.push({ name: 'resolver-connections' })"
              >
                <q-item-section avatar>
                  <q-icon name="cable" size="18px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label>Подключения</q-item-label>
                  <q-item-label caption class="text-grey-5">Точки VPN / прокси</q-item-label>
                </q-item-section>
              </q-item>

              <q-separator />

              <q-item
                clickable
                v-close-popup
                :active="route.name === 'diagnostics'"
                class="resolver-dropdown-item"
                active-class="resolver-dropdown-item--active"
                @click="router.push({ name: 'diagnostics' })"
              >
                <q-item-section avatar>
                  <q-icon name="troubleshoot" size="18px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label>Диагностика</q-item-label>
                  <q-item-label caption class="text-grey-5">Проверка резолвера</q-item-label>
                </q-item-section>
              </q-item>

              <q-item
                clickable
                v-close-popup
                :active="route.name === 'resolver-settings'"
                class="resolver-dropdown-item"
                active-class="resolver-dropdown-item--active"
                @click="router.push({ name: 'resolver-settings' })"
              >
                <q-item-section avatar>
                  <q-icon name="tune" size="18px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label>Настройки</q-item-label>
                  <q-item-label caption class="text-grey-5">Списки и sync</q-item-label>
                </q-item-section>
              </q-item>
            </q-list>
          </q-btn-dropdown>

          <q-route-tab name="settings" label="Настройки" :to="{ name: 'settings' }" />
        </q-tabs>

        <q-space />

        <q-select
          v-if="showLiveInterval"
          v-model="liveInterval"
          :options="intervalOptions"
          label="Интервал"
          emit-value
          map-options
          dense
          borderless
          class="q-mr-sm live-interval-select"
          @update:model-value="onLiveIntervalChange"
        >
          <q-tooltip>Интервал Live Fetch</q-tooltip>
        </q-select>

        <q-badge
          v-if="liveStatus"
          :color="liveStatus.color"
          class="q-mr-sm live-status-badge"
          :outline="liveStatus.outline"
        >
          {{ liveStatus.label }}
          <q-tooltip>{{ liveStatus.hint }}</q-tooltip>
        </q-badge>

        <ThemeSwitcher class="q-mr-sm" :compact="$q.screen.lt.md" />
        <div class="q-mr-md text-caption text-grey-5 gt-sm">{{ auth.user?.username }}</div>
        <q-btn flat dense icon="logout" label="Выход" class="gt-sm" @click="onLogout" />
      </q-toolbar>
    </q-header>

    <q-drawer
      v-model="drawerOpen"
      bordered
      overlay
      behavior="mobile"
      :width="280"
      class="surface-panel mobile-nav-drawer"
    >
      <q-list padding>
        <q-item-label header class="text-grey-5">Навигация</q-item-label>

        <q-item
          v-for="item in mainNavItems"
          :key="item.name"
          clickable
          v-ripple
          :active="route.name === item.name"
          active-class="mobile-nav-item--active"
          @click="go(item.to)"
        >
          <q-item-section avatar>
            <q-icon :name="item.icon" />
          </q-item-section>
          <q-item-section>{{ item.label }}</q-item-section>
        </q-item>

        <q-separator class="q-my-sm" />
        <q-item-label header class="text-grey-5">Резолвер</q-item-label>

        <q-item
          v-for="item in resolverNavItems"
          :key="item.name"
          clickable
          v-ripple
          :active="route.name === item.name"
          active-class="mobile-nav-item--active"
          @click="go(item.to)"
        >
          <q-item-section avatar>
            <q-icon :name="item.icon" />
          </q-item-section>
          <q-item-section>
            <q-item-label>{{ item.label }}</q-item-label>
            <q-item-label caption class="text-grey-5">{{ item.caption }}</q-item-label>
          </q-item-section>
        </q-item>

        <q-separator class="q-my-sm" />

        <q-item clickable v-ripple @click="onLogout">
          <q-item-section avatar>
            <q-icon name="logout" />
          </q-item-section>
          <q-item-section>Выход{{ auth.user?.username ? ` (${auth.user.username})` : '' }}</q-item-section>
        </q-item>
      </q-list>
    </q-drawer>

    <q-page-container>
      <template v-if="auth.user">
        <router-view />
        <SystemBootOverlay />
      </template>
      <div v-else class="auth-boot flex flex-center">
        <q-spinner color="primary" size="48px" />
      </div>
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useSystemStore } from '@/stores/system'
import { useAppBootstrap } from '@/composables/useAppBootstrap'
import {
  getLiveIntervalOptions,
  setLiveInterval,
  useLiveStatsState
} from '@/composables/useLiveStats'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'
import SystemBootOverlay from '@/components/SystemBootOverlay.vue'

const auth = useAuthStore()
const settingsStore = useSettingsStore()
const systemStore = useSystemStore()
const liveState = useLiveStatsState()
const router = useRouter()
const route = useRoute()

useAppBootstrap()

const drawerOpen = ref(false)
const intervalOptions = getLiveIntervalOptions()
const liveInterval = ref(liveState.intervalMs)

const showLiveInterval = computed(() =>
  liveState.active && liveState.transport === 'http' && !liveState.failed
)

watch(() => liveState.intervalMs, (ms) => {
  if (liveInterval.value !== ms) liveInterval.value = ms
})

function onLiveIntervalChange (ms) {
  setLiveInterval(ms)
}

const liveStatus = computed(() => {
  if (liveState.failed) {
    return {
      label: 'Live ошибка',
      color: 'negative',
      outline: false,
      hint: 'Обновление статистики остановлено после ошибок'
    }
  }
  if (!liveState.active) return null
  if (liveState.transport === 'ws') {
    return {
      label: 'Live WS',
      color: 'positive',
      outline: false,
      hint: 'Статистика обновляется через WebSocket'
    }
  }
  if (liveState.transport === 'http') {
    return {
      label: 'Live Fetch',
      color: 'warning',
      outline: false,
      hint: 'WebSocket недоступен, используется HTTP polling'
    }
  }
  return {
    label: 'Live…',
    color: 'grey-6',
    outline: true,
    hint: 'Подключение к каналу обновлений'
  }
})

const mainNavItems = [
  { name: 'dashboard', label: 'Дашборд', icon: 'dashboard', to: { name: 'dashboard' } },
  { name: 'configs', label: 'Конфиги и пиры', icon: 'vpn_key', to: { name: 'configs' } },
  { name: 'settings', label: 'Настройки', icon: 'settings', to: { name: 'settings' } }
]

const resolverNavItems = [
  {
    name: 'resolver',
    label: 'Резолвер',
    caption: 'Точечный обход',
    icon: 'alt_route',
    to: { name: 'resolver' }
  },
  {
    name: 'resolver-connections',
    label: 'Подключения',
    caption: 'Точки VPN / прокси',
    icon: 'cable',
    to: { name: 'resolver-connections' }
  },
  {
    name: 'diagnostics',
    label: 'Диагностика',
    caption: 'Проверка резолвера',
    icon: 'troubleshoot',
    to: { name: 'diagnostics' }
  },
  {
    name: 'resolver-settings',
    label: 'Настройки',
    caption: 'Списки и sync',
    icon: 'tune',
    to: { name: 'resolver-settings' }
  }
]

const resolverMenuActive = computed(() =>
  ['resolver', 'resolver-connections', 'diagnostics', 'resolver-settings'].includes(route.name)
)

function go (to) {
  drawerOpen.value = false
  router.push(to)
}

watch(() => route.fullPath, () => {
  drawerOpen.value = false
})

async function onLogout () {
  drawerOpen.value = false
  systemStore.stopBlockedPoll()
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<style scoped>
.app-logo {
  border-radius: var(--surface-radius);
  display: block;
  flex-shrink: 0;
}
.live-status-badge {
  flex-shrink: 0;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.live-interval-select {
  flex-shrink: 0;
  min-width: 96px;
  max-width: 120px;
}
.resolver-menu-btn {
  margin: 0 2px;
  padding: 0 12px;
  min-height: 36px;
  font-size: 14px;
  font-weight: 500;
  letter-spacing: 0.089em;
  text-transform: uppercase;
}
.resolver-menu-btn--active {
  box-shadow: inset 0 -2px 0 0 var(--q-primary);
}
.auth-boot {
  min-height: calc(100vh - 50px);
}
.mobile-nav-item--active {
  background: var(--surface-highlight-bg) !important;
  color: var(--surface-highlight) !important;
}
</style>

<!-- Menu is teleported outside scoped root — global styles for dropdown panel -->
<style>
.resolver-dropdown.q-menu {
  background: var(--surface-panel) !important;
  color: var(--surface-text) !important;
  border: 1px solid var(--surface-border);
  border-radius: var(--surface-radius);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
  overflow: hidden;
  min-width: 220px;
}
.resolver-dropdown-list {
  background: var(--surface-panel);
  padding: 4px 0;
}
.resolver-dropdown-item {
  color: var(--surface-text-soft) !important;
  min-height: 48px;
}
.resolver-dropdown-item .q-item__label {
  color: var(--surface-text);
  font-weight: 500;
}
.resolver-dropdown-item .q-item__label--caption {
  color: var(--surface-text-muted) !important;
}
.resolver-dropdown-item .q-icon {
  color: var(--surface-text-muted);
}
.resolver-dropdown-item:hover {
  background: var(--surface-hover) !important;
}
.resolver-dropdown-item--active,
.resolver-dropdown-item.q-item--active {
  background: var(--surface-highlight-bg) !important;
  color: var(--surface-highlight) !important;
}
.resolver-dropdown-item--active .q-item__label,
.resolver-dropdown-item.q-item--active .q-item__label {
  color: var(--surface-highlight) !important;
}
.resolver-dropdown-item--active .q-icon,
.resolver-dropdown-item.q-item--active .q-icon {
  color: var(--surface-highlight) !important;
}

.mobile-nav-drawer {
  background: var(--surface-panel) !important;
  border-color: var(--surface-border) !important;
}

/* CRT rem bump makes dropdown labels larger than main nav tabs — pin to menu size */
html[data-theme='crt'] .resolver-dropdown-item .q-item__label {
  font-size: 14px;
}
html[data-theme='crt'] .resolver-dropdown-item .q-item__label--caption {
  font-size: 12px;
}
html[data-theme='crt'] .mobile-nav-drawer .q-item__label {
  font-size: 14px;
}
html[data-theme='crt'] .mobile-nav-drawer .q-item__label--caption {
  font-size: 12px;
}
</style>
