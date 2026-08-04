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
          :aria-label="t('common.menu')"
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
          class="gt-sm desktop-nav-tabs"
          inline-label
        >
          <q-route-tab name="dashboard" :label="t('nav.dashboard')" :to="{ name: 'dashboard' }" exact />
          <q-route-tab name="configs" :label="t('nav.configs')" :to="{ name: 'configs' }" />

          <q-btn-dropdown
            flat
            dense
            no-caps
            stretch
            :ripple="false"
            :label="t('nav.resolver')"
            :class="['resolver-menu-btn', resolverMenuActive ? 'resolver-menu-btn--active text-primary' : '']"
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
                  <q-item-label>{{ t('nav.resolver') }}</q-item-label>
                  <q-item-label caption class="text-grey-5">{{ t('nav.resolverCaption') }}</q-item-label>
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
                  <q-item-label>{{ t('nav.connections') }}</q-item-label>
                  <q-item-label caption class="text-grey-5">{{ t('nav.connectionsCaption') }}</q-item-label>
                </q-item-section>
              </q-item>

              <q-item
                clickable
                v-close-popup
                :active="route.name === 'resolver-speed-test'"
                class="resolver-dropdown-item"
                active-class="resolver-dropdown-item--active"
                @click="router.push({ name: 'resolver-speed-test' })"
              >
                <q-item-section avatar>
                  <q-icon name="speed" size="18px" />
                </q-item-section>
                <q-item-section>
                  <q-item-label>{{ t('nav.speedTest') }}</q-item-label>
                  <q-item-label caption class="text-grey-5">{{ t('nav.speedTestCaption') }}</q-item-label>
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
                  <q-item-label>{{ t('nav.diagnostics') }}</q-item-label>
                  <q-item-label caption class="text-grey-5">{{ t('nav.diagnosticsCaption') }}</q-item-label>
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
                  <q-item-label>{{ t('nav.resolverSettings') }}</q-item-label>
                  <q-item-label caption class="text-grey-5">{{ t('nav.resolverSettingsCaption') }}</q-item-label>
                </q-item-section>
              </q-item>
            </q-list>
          </q-btn-dropdown>

          <q-route-tab name="settings" :label="t('nav.settings')" :to="{ name: 'settings' }" />
        </q-tabs>

        <q-space />

        <q-btn
          v-if="showHeaderUpdate"
          flat
          dense
          no-caps
          color="warning"
          class="update-available-btn q-mr-sm"
          :to="{ name: 'settings', query: { tab: 'update' } }"
        >
          <span class="update-available-btn__label gt-xs">{{ t('nav.updateAvailable') }}</span>
          <span class="mono update-available-btn__ver">{{ projectUpdate.current_version || '—' }}</span>
          <q-icon name="arrow_forward" size="18px" class="q-mx-xs" />
          <span class="mono text-weight-bold update-available-btn__ver">{{ projectUpdate.latest_version }}</span>
          <q-tooltip>{{ t('nav.updateAvailableTooltip') }}</q-tooltip>
        </q-btn>

        <q-select
          v-if="showLiveInterval"
          v-model="liveInterval"
          :options="intervalOptions"
          :label="t('nav.liveInterval')"
          emit-value
          map-options
          dense
          borderless
          class="q-mr-sm live-interval-select"
          @update:model-value="onLiveIntervalChange"
        >
          <q-tooltip>{{ t('nav.liveIntervalTooltip') }}</q-tooltip>
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

        <LanguageSwitcher
          v-if="uiChrome.prefs.showLanguage"
          class="q-mr-sm"
          :compact="$q.screen.lt.md"
        />
        <ThemeSwitcher
          v-if="uiChrome.prefs.showTheme"
          class="q-mr-sm"
          :compact="$q.screen.lt.md"
        />
        <q-btn
          v-if="canInstallPwa && uiChrome.prefs.showInstallApp"
          flat
          dense
          no-caps
          icon="install_mobile"
          :label="t('nav.installApp')"
          class="q-mr-sm gt-xs"
          @click="installPwa"
        >
          <q-tooltip>{{ t('nav.installAppTooltip') }}</q-tooltip>
        </q-btn>
        <q-btn
          v-if="canInstallPwa && uiChrome.prefs.showInstallApp"
          flat
          dense
          round
          icon="install_mobile"
          class="q-mr-sm lt-sm"
          :aria-label="t('nav.installApp')"
          @click="installPwa"
        >
          <q-tooltip>{{ t('nav.installAppTooltip') }}</q-tooltip>
        </q-btn>
        <div
          v-if="uiChrome.prefs.showUsername && auth.user?.username"
          class="q-mr-md text-caption text-grey-5 gt-sm"
        >{{ auth.user.username }}</div>
        <q-btn
          v-if="uiChrome.prefs.showLogout"
          flat
          dense
          icon="logout"
          :label="t('nav.logout')"
          class="gt-sm"
          @click="onLogout"
        />
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
        <q-item-label header class="text-grey-5">{{ t('nav.navigation') }}</q-item-label>

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
        <q-item-label header class="text-grey-5">{{ t('nav.resolver') }}</q-item-label>

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

        <q-separator
          v-if="(canInstallPwa && uiChrome.prefs.showInstallApp) || uiChrome.prefs.showLogout || uiChrome.prefs.showUsername"
          class="q-my-sm"
        />

        <q-item
          v-if="canInstallPwa && uiChrome.prefs.showInstallApp"
          clickable
          v-ripple
          @click="onInstallPwaFromDrawer"
        >
          <q-item-section avatar>
            <q-icon name="install_mobile" />
          </q-item-section>
          <q-item-section>
            <q-item-label>{{ t('nav.installApp') }}</q-item-label>
            <q-item-label caption class="text-grey-5">{{ t('nav.installAppTooltip') }}</q-item-label>
          </q-item-section>
        </q-item>

        <q-item
          v-if="uiChrome.prefs.showUsername && auth.user?.username && !uiChrome.prefs.showLogout"
          dense
        >
          <q-item-section avatar>
            <q-icon name="person" />
          </q-item-section>
          <q-item-section class="text-grey-5">
            {{ auth.user.username }}
          </q-item-section>
        </q-item>

        <q-item
          v-if="uiChrome.prefs.showLogout"
          clickable
          v-ripple
          @click="onLogout"
        >
          <q-item-section avatar>
            <q-icon name="logout" />
          </q-item-section>
          <q-item-section>
            {{ uiChrome.prefs.showUsername && auth.user?.username
              ? t('nav.logoutWithUser', { username: auth.user.username })
              : t('nav.logout') }}
          </q-item-section>
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
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useProjectUpdateStore } from '@/stores/projectUpdate'
import { useSystemStore } from '@/stores/system'
import { useUiChromeStore } from '@/stores/uiChrome'
import { useAppBootstrap } from '@/composables/useAppBootstrap'
import { usePwaInstall } from '@/composables/usePwaInstall'
import {
  getLiveIntervalOptions,
  setLiveInterval,
  useLiveStatsState
} from '@/composables/useLiveStats'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import SystemBootOverlay from '@/components/SystemBootOverlay.vue'

const { t } = useI18n()
const auth = useAuthStore()
const settingsStore = useSettingsStore()
const projectUpdate = useProjectUpdateStore()
const systemStore = useSystemStore()
const uiChrome = useUiChromeStore()
const liveState = useLiveStatsState()
const router = useRouter()
const route = useRoute()
const { canInstall: canInstallPwa, install: installPwa } = usePwaInstall()

useAppBootstrap()

const drawerOpen = ref(false)
const intervalOptions = computed(() => getLiveIntervalOptions(t))
const liveInterval = ref(liveState.intervalMs)

const showLiveInterval = computed(() =>
  liveState.active && liveState.transport === 'http' && !liveState.failed
)

const showHeaderUpdate = computed(() =>
  projectUpdate.update_available
  && !projectUpdate.running
  && !!projectUpdate.latest_version
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
      label: t('nav.liveError'),
      color: 'negative',
      outline: false,
      hint: t('nav.liveErrorHint')
    }
  }
  if (!liveState.active) return null
  if (liveState.transport === 'ws') {
    return {
      label: t('nav.liveWs'),
      color: 'positive',
      outline: false,
      hint: t('nav.liveWsHint')
    }
  }
  if (liveState.transport === 'http') {
    return {
      label: t('nav.liveFetch'),
      color: 'warning',
      outline: false,
      hint: t('nav.liveFetchHint')
    }
  }
  return {
    label: t('nav.liveConnecting'),
    color: 'grey-6',
    outline: true,
    hint: t('nav.liveConnectingHint')
  }
})

const mainNavItems = computed(() => [
  { name: 'dashboard', label: t('nav.dashboard'), icon: 'dashboard', to: { name: 'dashboard' } },
  { name: 'configs', label: t('nav.configs'), icon: 'vpn_key', to: { name: 'configs' } },
  { name: 'settings', label: t('nav.settings'), icon: 'settings', to: { name: 'settings' } }
])

const resolverNavItems = computed(() => [
  {
    name: 'resolver',
    label: t('nav.resolver'),
    caption: t('nav.resolverCaption'),
    icon: 'alt_route',
    to: { name: 'resolver' }
  },
  {
    name: 'resolver-connections',
    label: t('nav.connections'),
    caption: t('nav.connectionsCaption'),
    icon: 'cable',
    to: { name: 'resolver-connections' }
  },
  {
    name: 'resolver-speed-test',
    label: t('nav.speedTest'),
    caption: t('nav.speedTestCaption'),
    icon: 'speed',
    to: { name: 'resolver-speed-test' }
  },
  {
    name: 'diagnostics',
    label: t('nav.diagnostics'),
    caption: t('nav.diagnosticsCaption'),
    icon: 'troubleshoot',
    to: { name: 'diagnostics' }
  },
  {
    name: 'resolver-settings',
    label: t('nav.resolverSettings'),
    caption: t('nav.resolverSettingsCaption'),
    icon: 'tune',
    to: { name: 'resolver-settings' }
  }
])

const resolverMenuActive = computed(() =>
  ['resolver', 'resolver-connections', 'resolver-speed-test', 'diagnostics', 'resolver-settings'].includes(route.name)
)

function go (to) {
  drawerOpen.value = false
  router.push(to)
}

watch(() => route.fullPath, () => {
  drawerOpen.value = false
})

async function onInstallPwaFromDrawer () {
  drawerOpen.value = false
  await installPwa()
}

async function onLogout () {
  drawerOpen.value = false
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
.update-available-btn {
  flex-shrink: 0;
  border: 1px solid rgba(var(--q-warning-rgb), 0.45);
  border-radius: var(--surface-radius);
  padding: 4px 10px;
  max-width: min(100%, 320px);
}
.update-available-btn__label {
  margin-right: 6px;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.update-available-btn__ver {
  font-size: 12px;
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
