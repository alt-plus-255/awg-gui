<template>
  <q-page padding>
    <div class="page-wrap diagnostics-page">
      <div class="row items-start q-mb-md">
        <div class="col">
          <div class="row items-center no-wrap">
            <div class="col">
              <div class="text-h5">{{ t('diagnostics.title') }}</div>
            </div>
            <div class="col-auto lt-md">
              <q-btn
                flat
                dense
                color="primary"
                icon="refresh"
                :label="t('diagnostics.refreshStatus')"
                :loading="store.loadingStatus"
                @click="refreshStatus"
              />
            </div>
          </div>
          <div class="text-body2 text-muted-theme q-mt-xs">
            {{ t('diagnostics.description') }}
          </div>
        </div>
        <div class="col-auto gt-sm">
          <q-btn
            flat
            color="primary"
            icon="refresh"
            :label="t('diagnostics.refreshStatus')"
            :loading="store.loadingStatus"
            @click="refreshStatus"
          />
        </div>
      </div>

      <div class="row q-col-gutter-md">
        <!-- Left: run + results -->
        <div class="col-12 col-md-7">
          <q-card class="q-pa-md q-mb-md status-card" flat bordered>
            <div class="text-subtitle2 q-mb-sm">{{ t('diagnostics.configsToCheck') }}</div>
            <div class="row items-center q-gutter-xs q-mb-sm">
              <q-btn dense flat size="sm" color="primary" :label="t('diagnostics.all')" @click="store.selectAllConfigs()" />
              <q-btn dense flat size="sm" color="primary" :label="t('diagnostics.reset')" @click="store.clearConfigSelection()" />
            </div>
            <div class="row q-gutter-xs config-chips">
              <button
                v-for="c in store.configs"
                :key="c.id"
                type="button"
                class="config-chip"
                :class="{ 'config-chip--active': store.selectedConfigIds.includes(c.id) }"
                @click="store.toggleConfig(c.id)"
              >
                <q-icon
                  :name="c.type === 'virtual_network' ? 'hub' : 'dns'"
                  size="14px"
                  class="q-mr-xs"
                />
                <span>{{ c.name }}</span>
                <span class="config-chip__iface mono">{{ c.iface }}</span>
              </button>
              <div v-if="!store.configs.length" class="text-caption text-muted-theme">
                {{ t('diagnostics.noConfigs') }}
              </div>
            </div>

            <q-btn
              class="q-mt-md full-width"
              color="warning"
              icon="troubleshoot"
              :label="t('diagnostics.runDiagnostics')"
              :loading="store.running"
              :disable="!store.selectedConfigIds.length"
              @click="run"
            />
          </q-card>

          <div v-if="store.running" class="q-mb-md flex flex-center q-pa-lg running-banner status-card">
            <q-spinner color="warning" size="40px" />
            <span class="q-ml-md text-muted-theme">{{ t('diagnostics.running') }}</span>
          </div>

          <template v-if="store.result && !store.running">
            <div class="row items-center q-mb-sm">
              <div class="text-subtitle1 col">{{ t('diagnostics.results') }}</div>
              <q-badge :color="resultBadgeColor" class="q-pa-sm result-badge">
                {{ resultBadgeLabel }}
              </q-badge>
            </div>

            <q-card
              v-for="g in store.groups"
              :key="g.id"
              class="q-pa-md q-mb-md status-card check-group"
              flat
              bordered
              :class="`check-group--${g.status}`"
            >
              <div class="row items-center q-mb-sm">
                <q-icon
                  :name="groupIcon(g.status)"
                  :color="groupColor(g.status)"
                  size="20px"
                  class="q-mr-sm"
                />
                <div class="text-subtitle2 col">{{ g.title }}</div>
                <q-badge :color="groupColor(g.status)" outline class="status-pill">
                  {{ groupStatusLabel(g.status) }}
                </q-badge>
              </div>
              <div
                v-for="c in g.checks || []"
                :key="c.id"
                class="row items-start q-mb-xs check-row"
              >
                <q-icon
                  :name="c.ok ? 'check_circle' : 'error'"
                  :color="c.ok ? 'positive' : 'negative'"
                  size="18px"
                  class="q-mr-sm"
                />
                <div class="col">
                  <div class="text-body2">{{ c.label }}</div>
                  <div class="text-caption text-muted-theme mono">{{ c.detail }}</div>
                </div>
              </div>
            </q-card>

            <q-card v-if="store.hints.length" class="q-pa-md q-mb-md status-card hints-card" flat bordered>
              <div class="text-subtitle2 q-mb-sm">{{ t('diagnostics.hints') }}</div>
              <ul class="q-my-none text-caption text-soft-theme hints-list">
                <li v-for="(h, i) in store.hints" :key="i">{{ h }}</li>
              </ul>
            </q-card>
          </template>

          <q-card
            v-else-if="!store.running"
            class="q-pa-md status-card text-muted-theme"
            flat
            bordered
          >
            {{ t('diagnostics.runHint') }}
          </q-card>
        </div>

        <!-- Right: status + actions -->
        <div class="col-12 col-md-5">
          <q-card class="q-pa-md q-mb-md status-card" flat bordered>
            <div class="row items-center q-mb-sm">
              <div class="text-subtitle2 col">{{ t('diagnostics.servicesStatus') }}</div>
              <q-badge
                v-if="store.status"
                :color="store.status.ok ? 'positive' : 'negative'"
                class="q-pa-sm result-badge"
              >
                {{ store.status.ok ? t('diagnostics.ok') : t('diagnostics.problems') }}
              </q-badge>
              <q-spinner v-if="store.loadingStatus && !store.status" size="18px" color="primary" />
            </div>

            <div
              v-for="c in store.containers"
              :key="c.name"
              class="row items-center q-mb-xs check-row"
            >
              <q-icon
                :name="c.ok ? 'check_circle' : 'cancel'"
                :color="c.ok ? 'positive' : 'negative'"
                size="18px"
                class="q-mr-sm"
              />
              <div class="col">
                <div class="text-body2">{{ c.label }}</div>
                <div class="text-caption text-muted-theme mono">{{ c.name }} · {{ c.detail }}</div>
              </div>
            </div>

            <q-separator class="q-my-sm diag-sep" />

            <div v-if="store.singbox" class="row items-center q-mb-xs check-row">
              <q-icon
                :name="store.singbox.ok ? 'check_circle' : 'cancel'"
                :color="store.singbox.ok ? 'positive' : 'negative'"
                size="18px"
                class="q-mr-sm"
              />
              <div class="col">
                <div class="text-body2">sing-box</div>
                <div class="text-caption text-muted-theme mono">{{ store.singbox.detail }}</div>
              </div>
            </div>

            <template v-if="store.ifaces.length">
              <q-separator class="q-my-sm diag-sep" />
              <div class="text-caption text-muted-theme q-mb-xs">{{ t('diagnostics.awgInterfaces') }}</div>
              <div
                v-for="iface in store.ifaces"
                :key="iface.iface"
                class="row items-center q-mb-xs check-row"
              >
                <q-icon
                  :name="iface.ok ? 'check_circle' : 'cancel'"
                  :color="iface.ok ? 'positive' : 'negative'"
                  size="18px"
                  class="q-mr-sm"
                />
                <div class="col">
                  <div class="text-body2">{{ iface.name }}</div>
                  <div class="text-caption text-muted-theme mono">
                    {{ iface.iface }} · {{ iface.type }} · {{ iface.detail }}
                  </div>
                </div>
              </div>
            </template>
          </q-card>

          <q-card class="q-pa-md q-mb-md status-card" flat bordered>
            <div class="text-subtitle2 q-mb-sm">{{ t('diagnostics.actions') }}</div>
            <div class="column q-gutter-sm">
              <q-btn
                outline
                color="primary"
                icon="restart_alt"
                :label="t('diagnostics.restartAwg')"
                :loading="systemStore.restartBusy"
                :disable="systemStore.restartBusy"
                @click="restartAwg"
              />
              <q-btn
                outline
                color="warning"
                icon="power_settings_new"
                :label="t('diagnostics.restartServices')"
                :loading="systemStore.restartBusy"
                :disable="systemStore.restartBusy"
                @click="restartAll"
              />
              <q-btn
                outline
                color="primary"
                icon="data_object"
                :label="t('diagnostics.showSingboxJson')"
                :loading="store.singBoxModal.loading"
                @click="store.openSingBoxConfig()"
              />
              <q-btn
                outline
                color="primary"
                icon="description"
                :label="t('diagnostics.showAwgConfigs')"
                :loading="store.awgModal.loading"
                @click="store.openAwgConfigs()"
              />
              <q-btn
                outline
                color="primary"
                icon="content_copy"
                :label="t('diagnostics.copyDump')"
                :disable="!store.result"
                @click="copyDump"
              />
            </div>
          </q-card>

          <q-card class="q-pa-md status-card" flat bordered>
            <div class="text-subtitle2 q-mb-sm">{{ t('diagnostics.system') }}</div>
            <div class="text-caption text-muted-theme sys-grid">
              <div class="q-mb-xs">
                Endpoint:
                <span class="mono text-soft-theme">{{ store.system.endpoint || settingsStore.displayEndpoint || '—' }}</span>
              </div>
              <div class="q-mb-xs">
                Panel port:
                <span class="mono text-soft-theme">{{ store.system.panel_port ?? '—' }}</span>
              </div>
              <div class="q-mb-xs">
                Timezone:
                <span class="mono text-soft-theme">{{ store.system.timezone || '—' }}</span>
              </div>
              <div>
                AWG container:
                <span class="mono text-soft-theme">{{ store.system.awg_container || '—' }}</span>
              </div>
            </div>
          </q-card>
        </div>
      </div>
    </div>

    <!-- sing-box modal -->
    <q-dialog :model-value="store.singBoxModal.open" v-bind="mobileDialog" @update:model-value="onSingBoxOpen">
      <q-card class="config-dialog status-card dialog-card column no-wrap" style="width: min(720px, 95vw); max-width: 95vw;">
        <q-card-section class="row items-center">
          <div class="text-h6 col">sing-box.json</div>
          <q-badge color="primary" outline class="q-mr-sm">masked</q-badge>
          <q-btn flat dense color="primary" icon="content_copy" :disable="!store.singBoxModal.content" @click="copyText(store.singBoxModal.content)" />
          <q-btn flat dense round color="primary" icon="close" v-close-popup />
        </q-card-section>
        <q-card-section class="q-pt-none col dialog-scroll-body">
          <div v-if="store.singBoxModal.loading" class="flex flex-center q-pa-lg">
            <q-spinner color="primary" size="32px" />
          </div>
          <div v-else-if="store.singBoxModal.error" class="text-warning q-mb-sm">
            {{ store.singBoxModal.error }}
          </div>
          <pre v-if="store.singBoxModal.content" class="config-pre mono">{{ store.singBoxModal.content }}</pre>
          <div v-if="store.singBoxModal.updatedAt" class="text-caption text-muted-theme q-mt-sm">
            updated: {{ store.singBoxModal.updatedAt }}
          </div>
        </q-card-section>
      </q-card>
    </q-dialog>

    <!-- AWG configs modal -->
    <q-dialog :model-value="store.awgModal.open" v-bind="mobileDialog" @update:model-value="onAwgOpen">
      <q-card class="config-dialog status-card dialog-card column no-wrap" style="width: min(720px, 95vw); max-width: 95vw;">
        <q-card-section class="row items-center">
          <div class="text-h6 col">{{ t('diagnostics.awgConfigs') }}</div>
          <q-badge color="primary" outline class="q-mr-sm">masked</q-badge>
          <q-btn flat dense round color="primary" icon="close" v-close-popup />
        </q-card-section>
        <q-card-section class="q-pt-none col dialog-scroll-body">
          <div v-if="store.awgModal.loading" class="flex flex-center q-pa-lg">
            <q-spinner color="primary" size="32px" />
          </div>
          <div v-else-if="store.awgModal.error" class="text-negative">{{ store.awgModal.error }}</div>
          <div v-else-if="!store.awgModal.configs.length" class="text-muted-theme">{{ t('diagnostics.noAwgConfFiles') }}</div>
          <div v-else class="awg-config-list">
            <q-expansion-item
              v-for="cfg in store.awgModal.configs"
              :key="cfg.iface"
              expand-separator
              :label="`${cfg.name} (${cfg.iface})`"
              :caption="cfg.type_label || cfg.type || ''"
              class="awg-expand"
              header-class="awg-expand__header"
            >
              <div class="row justify-end q-mb-xs">
                <q-btn flat dense size="sm" color="primary" icon="content_copy" :label="t('diagnostics.copy')" @click="copyText(cfg.content)" />
              </div>
              <pre class="config-pre mono">{{ cfg.content }}</pre>
            </q-expansion-item>
          </div>
        </q-card-section>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import { useDiagnosticsStore } from '@/stores/diagnostics'
import { useSystemStore } from '@/stores/system'
import { useSettingsStore } from '@/stores/settings'
import { useMobileDialog } from '@/composables/useMobileDialog'

const { t } = useI18n()
const $q = useQuasar()
const mobileDialog = useMobileDialog()
const store = useDiagnosticsStore()
const systemStore = useSystemStore()
const settingsStore = useSettingsStore()

const resultBadgeColor = computed(() => {
  const s = store.result?.status
  if (s === 'success') return 'positive'
  if (s === 'warning') return 'warning'
  return 'negative'
})

const resultBadgeLabel = computed(() => {
  const s = store.result?.status
  if (s === 'success') return t('diagnostics.ok')
  if (s === 'warning') return t('diagnostics.hasRemarks')
  return t('diagnostics.errors')
})

function groupColor (status) {
  if (status === 'success') return 'positive'
  if (status === 'warning') return 'warning'
  return 'negative'
}

function groupIcon (status) {
  if (status === 'success') return 'check_circle'
  if (status === 'warning') return 'warning'
  return 'error'
}

function groupStatusLabel (status) {
  if (status === 'success') return t('diagnostics.ok')
  if (status === 'warning') return t('diagnostics.remarks')
  return t('diagnostics.errorsLower')
}

async function refreshStatus () {
  try {
    await store.fetchStatus()
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('diagnostics.loadStatusError') })
  }
}

async function run () {
  try {
    const data = await store.runDiagnostic()
    $q.notify({
      type: data.ok ? 'positive' : 'warning',
      message: data.ok ? t('diagnostics.diagnosticsOk') : t('diagnostics.diagnosticsRemarks')
    })
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('diagnostics.diagnosticsError') })
  }
}

async function restartAwg () {
  try {
    const data = await systemStore.restartAwg()
    $q.notify({ type: data.ok === false ? 'warning' : 'positive', message: data.message || t('diagnostics.awgRestarted') })
    await store.fetchStatus()
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('diagnostics.restartAwgError') })
  }
}

async function restartAll () {
  try {
    const data = await systemStore.restartAll()
    $q.notify({ type: 'warning', message: data.message || t('diagnostics.servicesRestartStarted') })
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('diagnostics.restartServicesError') })
  }
}

async function copyText (text) {
  try {
    await navigator.clipboard.writeText(text || '')
    $q.notify({ type: 'positive', message: t('diagnostics.copied') })
  } catch {
    $q.notify({ type: 'negative', message: t('diagnostics.copyFailed') })
  }
}

async function copyDump () {
  await copyText(store.dumpText())
}

function onSingBoxOpen (v) {
  if (!v) store.closeSingBoxModal()
}

function onAwgOpen (v) {
  if (!v) store.closeAwgModal()
}

onMounted(async () => {
  if (!settingsStore.loaded) {
    try { await settingsStore.fetch() } catch { /* ignore */ }
  }
  await refreshStatus()
})
</script>

<style scoped>
.diagnostics-page {
  color: var(--surface-text);
}

.config-chips {
  flex-wrap: wrap;
}

.config-chip {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin: 0;
  padding: 6px 12px;
  border: 1px solid var(--surface-border);
  border-radius: var(--surface-radius);
  background: var(--surface-panel-alt);
  color: var(--surface-text-soft);
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}

.config-chip:hover {
  border-color: var(--surface-highlight);
  color: var(--surface-text);
  background: var(--surface-hover);
}

.config-chip--active {
  border-color: var(--q-primary);
  color: var(--surface-highlight);
  background: var(--surface-highlight-bg);
  box-shadow: 0 0 8px var(--surface-glow);
}

.config-chip__iface {
  opacity: 0.75;
  margin-left: 4px;
}

.running-banner {
  border: 1px solid var(--surface-border);
  border-radius: var(--surface-radius);
}

.check-group {
  border-left: 3px solid var(--surface-border) !important;
}

.check-group--success {
  border-left-color: var(--q-positive) !important;
  background:
    linear-gradient(90deg, color-mix(in srgb, var(--q-positive) 12%, transparent), transparent 48%),
    var(--surface-panel) !important;
}

.check-group--warning {
  border-left-color: var(--q-warning) !important;
  background:
    linear-gradient(90deg, color-mix(in srgb, var(--q-warning) 14%, transparent), transparent 48%),
    var(--surface-panel) !important;
}

.check-group--error {
  border-left-color: var(--q-negative) !important;
  background:
    linear-gradient(90deg, color-mix(in srgb, var(--q-negative) 14%, transparent), transparent 48%),
    var(--surface-panel) !important;
}

.hints-card {
  background:
    linear-gradient(90deg, color-mix(in srgb, var(--q-warning) 10%, transparent), transparent 40%),
    var(--surface-panel) !important;
}

.hints-list {
  padding-left: 1.25rem;
  line-height: 1.55;
}

.diag-sep {
  background: var(--surface-border) !important;
  opacity: 0.85;
}

.check-row .q-icon {
  margin-top: 2px;
}

.config-pre {
  margin: 0;
  padding: 12px;
  max-height: 60vh;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--surface-panel-alt);
  color: var(--surface-text-soft);
  border: 1px solid var(--surface-border);
  border-radius: var(--surface-radius);
  font-size: 12px;
  line-height: 1.45;
}
</style>

<!-- Dialogs teleport outside scoped root -->
<style>
.config-dialog.status-card {
  background: var(--surface-panel) !important;
  color: var(--surface-text) !important;
  border: 1px solid var(--surface-border) !important;
  border-radius: var(--surface-radius);
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.45);
}

.config-dialog .config-pre {
  margin: 0;
  padding: 12px;
  max-height: 60vh;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--surface-panel-alt);
  color: var(--surface-text-soft);
  border: 1px solid var(--surface-border);
  border-radius: var(--surface-radius);
  font-family: var(--theme-mono);
  font-size: 12px;
  line-height: 1.45;
}

.config-dialog .awg-expand {
  border: 1px solid var(--surface-border);
  border-radius: var(--surface-radius);
  margin-bottom: 8px;
  background: var(--surface-panel-alt);
  color: var(--surface-text);
}

.config-dialog .awg-expand__header,
.config-dialog .awg-expand .q-item {
  color: var(--surface-text) !important;
}

.config-dialog .awg-expand .q-item__label--caption {
  color: var(--surface-text-muted) !important;
}

.config-dialog .awg-expand .q-separator {
  background: var(--surface-border) !important;
}

html[data-theme='crt'] .diagnostics-page .config-chip,
html[data-theme='crt'] .diagnostics-page .running-banner,
html[data-theme='crt'] .config-dialog .config-pre,
html[data-theme='crt'] .config-dialog .awg-expand {
  border-radius: 0;
}

html[data-theme='crt'] .diagnostics-page .config-chip--active {
  text-shadow: 0 0 6px var(--surface-glow);
}
</style>
