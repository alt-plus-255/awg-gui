<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md no-wrap">
        <div class="col">
          <div class="text-h5">{{ t('dashboard.title') }}</div>
        </div>
        <div class="col-auto lt-md">
          <q-btn flat dense icon="refresh" :label="t('common.refresh')" @click="refreshFromDocker" :loading="refreshing" />
        </div>
      </div>

      <div class="row q-col-gutter-md q-mb-md items-center">
        <div class="col-12 col-md-3">
          <q-select
            v-model="filterConfigId"
            :options="configOptions"
            :label="t('dashboard.configFilter')"
            emit-value
            map-options

            filled
            dense
            class="full-width"
            @update:model-value="onFilterChange"
          />
        </div>
        <div class="col-12 col-md">
          <div v-if="syncLabel" class="text-caption text-grey-5">{{ syncLabel }}</div>
        </div>
        <div class="col-12 col-md-auto gt-sm">
          <div class="row items-center no-wrap justify-end">
            <q-btn flat icon="refresh" :label="t('common.refresh')" class="q-mr-sm" @click="refreshFromDocker" :loading="refreshing" />
          </div>
        </div>
      </div>

      <q-banner v-if="statsAvailable === false" dense rounded class="bg-warning text-dark q-mb-md">
        {{ t('dashboard.statsUnavailable') }}
      </q-banner>

      <div class="row q-col-gutter-md q-mb-lg items-stretch">
        <div class="col-12 col-sm-4" v-for="card in hostCards" :key="card.label">
          <q-card class="q-pa-md surface-panel full-height host-metric-card">
            <div class="row items-start no-wrap">
              <div class="col">
                <div class="text-caption text-grey-5">{{ card.label }}</div>
                <div class="text-h4">{{ card.percentLabel }}</div>
              </div>
              <q-btn
                v-if="card.modalSort"
                flat
                dense
                round
                icon="list_alt"
                color="grey-5"
                :title="card.modalTitle"
                @click="openProcessModal(card.modalSort)"
              />
            </div>
            <div class="text-caption text-grey-5 q-mt-xs host-metric-detail">
              {{ card.detail || '\u00a0' }}
            </div>
            <q-linear-progress
              class="q-mt-sm"
              size="6px"
              rounded

              :value="card.progress"
              :color="card.color"
            />
          </q-card>
        </div>
      </div>

      <div class="row q-col-gutter-md q-mb-lg">
        <div class="col-12 col-sm-3" v-for="card in cards" :key="card.label">
          <q-card class="q-pa-md surface-panel">
            <div class="text-caption text-grey-5">{{ card.label }}</div>
            <div class="text-h4">{{ card.value }}</div>
          </q-card>
        </div>
      </div>

      <q-dialog v-model="processModalOpen" v-bind="mobileDialog" @hide="stopProcessPolling">
        <q-card class="surface-panel process-modal-card column no-wrap dialog-card">
          <q-card-section class="row items-center q-pb-none">
            <div class="text-h6">{{ processModalTitle }}</div>
            <q-space />
            <q-spinner-dots v-if="processLoading" size="20px" color="primary" class="q-mr-sm" />
            <q-btn flat dense round icon="close" v-close-popup />
          </q-card-section>
          <q-card-section class="col dialog-scroll-body">
            <div class="text-caption text-grey-5 q-mb-sm">{{ t('dashboard.liveViewHint') }}</div>
            <q-table
              dense

              flat
              :rows="processRows"
              :columns="activeProcessColumns"
              row-key="pid"
              :loading="processLoading && !processRows.length"
              :rows-per-page-options="[0]"
              hide-pagination
              class="bg-transparent process-table"
              virtual-scroll
              style="max-height: 60vh;"
            >
              <template #body-cell-command="props">
                <q-td :props="props">
                  <div class="ellipsis process-cmd" :title="props.row.command">{{ props.row.command }}</div>
                </q-td>
              </template>
            </q-table>
            <div v-if="containerRows.length" class="q-mt-md">
              <div class="text-subtitle2 q-mb-xs">{{ t('dashboard.containers') }}</div>
              <q-table
                dense

                flat
                :rows="containerRows"
                :columns="activeContainerColumns"
                row-key="name"
                :rows-per-page-options="[0]"
                hide-pagination
                class="bg-transparent"
              />
            </div>
          </q-card-section>
        </q-card>
      </q-dialog>

      <q-table
        :title="t('dashboard.peersTitle')"
        :rows="rows"
        :columns="columns"
        row-key="membership_id"

        flat
        :loading="initialLoading"
        class="bg-transparent"
        :rows-per-page-options="[10, 25, 50, 0]"
      >
        <template #body-cell-online="props">
          <q-td :props="props">
            <q-badge :color="props.row.online ? 'positive' : 'grey-8'">
              {{ props.row.online != null ? (props.row.online ? t('common.online') : t('common.offline')) : '—' }}
            </q-badge>
          </q-td>
        </template>
        <template #body-cell-enabled="props">
          <q-td :props="props">
            <div class="row items-center no-wrap q-gutter-xs">
              <q-toggle
                :model-value="props.row.enabled"
                dense
                color="positive"
                :disable="isPeerToggling(props.row.config_id, props.row.client_id)"
                @update:model-value="(v) => toggle(props.row, v)"
              />
              <q-spinner-dots
                v-if="isPeerToggling(props.row.config_id, props.row.client_id)"
                size="20px"
                color="primary"
              />
            </div>
          </q-td>
        </template>
        <template #body-cell-client_allowed_ips="props">
          <q-td :props="props">
            <div class="ellipsis" style="max-width: 280px;" :title="props.row.client_allowed_ips">
              {{ props.row.client_allowed_ips }}
            </div>
          </q-td>
        </template>
        <template #body-cell-latest_handshake_human="props">
          <q-td :props="props">
            <template v-if="formatHandshake(props.row.latest_handshake_human)">
              <div class="text-no-wrap">{{ formatHandshake(props.row.latest_handshake_human).date }}</div>
              <div class="text-no-wrap">{{ formatHandshake(props.row.latest_handshake_human).time }}</div>
            </template>
            <template v-else>—</template>
          </q-td>
        </template>
        <template #body-cell-transfer_rx="props">
          <q-td :props="props">{{ props.row.transfer_rx != null ? formatBytes(props.row.transfer_rx) : '—' }}</q-td>
        </template>
        <template #body-cell-transfer_tx="props">
          <q-td :props="props">{{ props.row.transfer_tx != null ? formatBytes(props.row.transfer_tx) : '—' }}</q-td>
        </template>
        <template #body-cell-actions="props">
          <q-td :props="props">
            <q-btn flat dense icon="qr_code_2" title="QR" @click="openShare(props.row)" />
            <q-btn flat dense icon="download" :title="t('dashboard.configTooltip')" @click="downloadConf(props.row)" />
          </q-td>
        </template>
      </q-table>

      <q-card v-if="$q.screen.gt.sm" flat class="q-mt-lg surface-panel">
        <q-card-section class="q-pb-none">
          <div class="text-h6">{{ t('dashboard.graphTitle') }}</div>
          <div class="text-caption text-grey-5">
            {{ t('dashboard.graphHint') }}
          </div>
        </q-card-section>
        <q-card-section>
          <PeerConnectionsGraph :peers="displayPeers" :links="links" />
        </q-card-section>
      </q-card>

      <q-card v-else flat class="q-mt-lg surface-panel">
        <q-card-section>
          <div class="text-h6">{{ t('dashboard.graphTitle') }}</div>
          <div class="text-caption text-grey-5 q-mb-md">
            {{ t('dashboard.graphHint') }}
          </div>
          <q-btn
            color="primary"
            class="full-width"
            icon="account_tree"
            :label="t('dashboard.openGraph')"
            @click="graphOpen = true"
          />
        </q-card-section>
      </q-card>
    </div>

    <q-dialog
      v-model="graphOpen"
      maximized
      transition-show="slide-up"
      transition-hide="slide-down"
      @show="onGraphDialogShow"
    >
      <q-card class="graph-dialog-card column no-wrap surface-panel">
        <q-card-section class="row items-center q-pb-sm">
          <div class="text-h6">{{ t('dashboard.graphTitle') }}</div>
          <q-space />
          <q-btn flat round dense icon="close" :aria-label="t('common.close')" v-close-popup />
        </q-card-section>
        <q-card-section class="col q-pt-none graph-dialog-body">
          <PeerConnectionsGraph
            v-if="graphOpen"
            ref="mobileGraphRef"
            full-height
            :peers="displayPeers"
            :links="links"
          />
        </q-card-section>
        <q-card-actions class="q-pa-md">
          <q-btn class="full-width" outline color="primary" :label="t('common.close')" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <PeerShareDialog
      v-model="shareOpen"
      :config-id="shareTarget.configId"
      :client-id="shareTarget.clientId"
      :peer-name="shareTarget.peerName"
    />
  </q-page>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import PeerConnectionsGraph from '@/components/PeerConnectionsGraph.vue'
import PeerShareDialog from '@/components/PeerShareDialog.vue'
import { useDashboardData } from '@/composables/useDashboardData'
import { useMobileDialog } from '@/composables/useMobileDialog'
import { bcp47Locale } from '@/i18n'
import {
  mergeLiveIntoPeers,
  onLiveStats,
  refreshLiveStats,
  startLivePolling,
  stopLivePolling,
  useLiveStatsState
} from '@/composables/useLiveStats'

const { t, locale } = useI18n()
const $q = useQuasar()
const mobileDialog = useMobileDialog()
const liveState = useLiveStatsState()
const dashboard = useDashboardData()

const refreshing = ref(false)
const initialLoading = ref(true)
const filterConfigId = ref(null)
const statsAvailable = ref(true)
const subscribedConfigIds = ref([])

const peers = dashboard.peers
const links = dashboard.links
const configs = dashboard.configs
const dbSummary = dashboard.summary

const shareOpen = ref(false)
const graphOpen = ref(false)
const mobileGraphRef = ref(null)
const shareTarget = ref({
  configId: null,
  clientId: null,
  peerName: ''
})

function onGraphDialogShow () {
  nextTick(() => {
    requestAnimationFrame(() => {
      mobileGraphRef.value?.fitGraphToView?.()
    })
  })
}
const peerToggling = reactive(new Set())
let liveOff = null

const processModalOpen = ref(false)
const processModalSort = ref('cpu')
const processLoading = ref(false)
const processRows = ref([])
const containerRows = ref([])
let processPollTimer = null

const processModalTitle = computed(() => (
  processModalSort.value === 'mem' ? t('dashboard.processesRam') : t('dashboard.processesCpu')
))

const processColumnsCpu = [
  { name: 'pid', label: 'PID', field: 'pid', align: 'right', style: 'width: 72px' },
  { name: 'cpu_percent', label: 'CPU%', field: 'cpu_percent', align: 'right', style: 'width: 72px', format: (v) => formatHostPercent(v) },
  { name: 'mem_percent', label: 'MEM%', field: 'mem_percent', align: 'right', style: 'width: 72px', format: (v) => formatHostPercent(v) },
  { name: 'used', label: 'RSS', field: 'used', align: 'right', style: 'width: 90px', format: (v) => formatBytes(v) },
  { name: 'command', label: 'Command', field: 'command', align: 'left' }
]

const processColumnsMem = [
  { name: 'pid', label: 'PID', field: 'pid', align: 'right', style: 'width: 72px' },
  { name: 'mem_percent', label: 'MEM%', field: 'mem_percent', align: 'right', style: 'width: 72px', format: (v) => formatHostPercent(v) },
  { name: 'used', label: 'RSS', field: 'used', align: 'right', style: 'width: 90px', format: (v) => formatBytes(v) },
  { name: 'cpu_percent', label: 'CPU%', field: 'cpu_percent', align: 'right', style: 'width: 72px', format: (v) => formatHostPercent(v) },
  { name: 'command', label: 'Command', field: 'command', align: 'left' }
]

const containerColumnsCpu = computed(() => [
  { name: 'name', label: t('dashboard.container'), field: 'name', align: 'left' },
  { name: 'cpu_percent', label: 'CPU%', field: 'cpu_percent', align: 'right', format: (v) => formatHostPercent(v) },
  { name: 'mem_percent', label: 'MEM%', field: 'mem_percent', align: 'right', format: (v) => formatHostPercent(v) },
  { name: 'used', label: 'RAM', field: 'used', align: 'right', format: (v) => formatBytes(v) }
])

const containerColumnsMem = computed(() => [
  { name: 'name', label: t('dashboard.container'), field: 'name', align: 'left' },
  { name: 'mem_percent', label: 'MEM%', field: 'mem_percent', align: 'right', format: (v) => formatHostPercent(v) },
  { name: 'used', label: 'RAM', field: 'used', align: 'right', format: (v) => formatBytes(v) },
  { name: 'cpu_percent', label: 'CPU%', field: 'cpu_percent', align: 'right', format: (v) => formatHostPercent(v) }
])

const activeProcessColumns = computed(() => (
  processModalSort.value === 'mem' ? processColumnsMem : processColumnsCpu
))

const activeContainerColumns = computed(() => (
  processModalSort.value === 'mem' ? containerColumnsMem.value : containerColumnsCpu.value
))

function peerToggleKey (configId, clientId) {
  return `${configId}:${clientId}`
}

function isPeerToggling (configId, clientId) {
  return peerToggling.has(peerToggleKey(configId, clientId))
}

const configOptions = computed(() => [
  { label: t('dashboard.allConfigs'), value: null },
  ...configs.value.map((c) => ({ label: `${c.name} (${c.iface})`, value: c.id }))
])

const displayPeers = computed(() => {
  const enabledIds = new Set(configs.value.filter((c) => c.enabled).map((c) => c.id))
  let list = peers.value.filter((p) => enabledIds.has(p.config_id))
  if (filterConfigId.value) {
    list = list.filter((p) => p.config_id === filterConfigId.value)
  }

  const byConfig = {}
  for (const peer of list) {
    const configId = peer.config_id
    if (!byConfig[configId]) byConfig[configId] = []
    byConfig[configId].push(peer)
  }

  const merged = []
  for (const [configId, chunk] of Object.entries(byConfig)) {
    merged.push(...mergeLiveIntoPeers(chunk, Number(configId)))
  }
  return merged
})

const summary = computed(() => {
  const list = displayPeers.value
  return {
    clients_total: dbSummary.value.clients_total,
    memberships_total: list.length,
    memberships_enabled: list.filter((p) => p.enabled).length,
    online: list.filter((p) => p.online === true).length
  }
})

const cards = computed(() => [
  { label: 'Peers', value: summary.value.clients_total },
  { label: t('dashboard.memberships'), value: summary.value.memberships_total },
  { label: t('dashboard.enabledCount'), value: summary.value.memberships_enabled },
  { label: t('dashboard.onlineCount'), value: summary.value.online }
])

function hostProgressColor (percent) {
  if (percent == null) return 'grey-7'
  if (percent >= 90) return 'negative'
  if (percent >= 75) return 'warning'
  return 'primary'
}

function formatHostPercent (percent) {
  return percent == null ? '—' : `${Number(percent).toFixed(1)}%`
}

function formatHostBytesPair (used, total) {
  if (used == null || total == null) return null
  return `${formatBytes(used)} / ${formatBytes(total)}`
}

const hostCards = computed(() => {
  const host = liveState.host || {}
  const cpuPct = host.cpu?.percent
  const memPct = host.memory?.percent
  const diskPct = host.disk?.percent

  return [
    {
      label: 'CPU',
      percentLabel: formatHostPercent(cpuPct),
      detail: null,
      modalSort: 'cpu',
      modalTitle: t('dashboard.processes'),
      progress: cpuPct == null ? 0 : Math.min(1, Number(cpuPct) / 100),
      color: hostProgressColor(cpuPct)
    },
    {
      label: 'RAM',
      percentLabel: formatHostPercent(memPct),
      detail: formatHostBytesPair(host.memory?.used, host.memory?.total),
      modalSort: 'mem',
      modalTitle: t('dashboard.memory'),
      progress: memPct == null ? 0 : Math.min(1, Number(memPct) / 100),
      color: hostProgressColor(memPct)
    },
    {
      label: 'ROM',
      percentLabel: formatHostPercent(diskPct),
      detail: formatHostBytesPair(host.disk?.used, host.disk?.total),
      modalSort: null,
      modalTitle: null,
      progress: diskPct == null ? 0 : Math.min(1, Number(diskPct) / 100),
      color: hostProgressColor(diskPct)
    }
  ]
})

const syncLabel = computed(() => {
  const ts = liveState.lastSyncedAt || displayPeers.value.find((p) => p.stats_synced_at)?.stats_synced_at
  if (!ts) return t('dashboard.statsFromDb')
  try {
    return t('dashboard.updatedAt', { ts: new Date(ts).toLocaleString(bcp47Locale(locale.value)) })
  } catch {
    return null
  }
})

const columns = computed(() => [
  { name: 'name', label: t('dashboard.colName'), field: 'name', align: 'left', sortable: true },
  { name: 'config_name', label: t('dashboard.colConfig'), field: 'config_name', align: 'left' },
  { name: 'client_allowed_ips', label: 'AllowedIPs', field: 'client_allowed_ips', align: 'left' },
  { name: 'online', label: t('dashboard.colStatus'), field: 'online', align: 'left' },
  { name: 'latest_handshake_human', label: 'Handshake', field: 'latest_handshake_human', align: 'left' },
  { name: 'transfer_rx', label: 'RX', field: 'transfer_rx', align: 'right' },
  { name: 'transfer_tx', label: 'TX', field: 'transfer_tx', align: 'right' },
  { name: 'enabled', label: t('dashboard.colEnabled'), field: 'enabled', align: 'left' },
  { name: 'actions', label: t('dashboard.colActions'), field: 'actions', align: 'right' }
])

const rows = computed(() => displayPeers.value.map((p) => ({
  ...p,
  latest_handshake_human: p.latest_handshake_human || '—'
})))

function enabledConfigIds () {
  const list = configs.value.filter((c) => c.enabled)
  if (filterConfigId.value) {
    return list.filter((c) => c.id === filterConfigId.value).map((c) => c.id)
  }
  return list.map((c) => c.id)
}

function formatBytes (n) {
  const v = Number(n) || 0
  if (v < 1024) return `${v} B`
  if (v < 1024 ** 2) return `${(v / 1024).toFixed(1)} KB`
  if (v < 1024 ** 3) return `${(v / 1024 ** 2).toFixed(1)} MB`
  return `${(v / 1024 ** 3).toFixed(2)} GB`
}

function formatHandshake (iso) {
  if (!iso || iso === '—') return null
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})(.*)$/)
  if (!m) return { date: String(iso), time: '' }
  return { date: `${m[3]}.${m[2]}.${m[1]}`, time: `${m[4]} ${m[5]}`.trim() }
}

function applyPeersFromRefresh (data) {
  statsAvailable.value = data.stats_available !== false
  // Live refresh returns lite stats only — merge via displayPeers / mergeLiveIntoPeers
  if (data.summary) {
    dbSummary.value = {
      ...dbSummary.value,
      ...data.summary,
      // keep full membership counts from DB load; online comes from live merge
      clients_total: data.summary.clients_total ?? dbSummary.value.clients_total,
      memberships_total: dbSummary.value.memberships_total,
      memberships_enabled: dbSummary.value.memberships_enabled
    }
  }
}

function updateStatsAvailable () {
  statsAvailable.value = liveState.statsAvailable !== false
}

async function loadFromDb () {
  await dashboard.load(filterConfigId.value || null)
  initialLoading.value = false
}

async function refreshFromDocker () {
  refreshing.value = true
  try {
    const data = await refreshLiveStats(filterConfigId.value ? [filterConfigId.value] : enabledConfigIds())
    applyPeersFromRefresh(data)
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('dashboard.refreshStatsError') })
  } finally {
    refreshing.value = false
  }
}

function restartLive () {
  const ids = enabledConfigIds()
  if (!ids.length) return
  subscribedConfigIds.value = ids
  startLivePolling(ids, liveState.intervalMs)
}

async function onFilterChange () {
  stopLivePolling(subscribedConfigIds.value)
  subscribedConfigIds.value = []
  await loadFromDb()
  restartLive()
}

async function toggle (row, enabled) {
  const key = peerToggleKey(row.config_id, row.client_id)
  if (peerToggling.has(key)) return

  const peer = peers.value.find((p) => p.config_id === row.config_id && p.client_id === row.client_id)
  if (!peer) return

  const prev = peer.enabled
  peer.enabled = enabled
  peerToggling.add(key)

  try {
    await api.put(`/api/configs/${row.config_id}/peers/${row.client_id}`, { enabled })
    await loadFromDb()
  } catch (e) {
    peer.enabled = prev
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('dashboard.togglePeerError') })
  } finally {
    peerToggling.delete(key)
  }
}

async function downloadConf (row) {
  const res = await api.get(`/api/configs/${row.config_id}/peers/${row.client_id}/config`, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data)
  const a = document.createElement('a')
  a.href = url
  a.download = `${row.name}.conf`
  a.click()
  URL.revokeObjectURL(url)
}

function openShare (row) {
  shareTarget.value = {
    configId: row.config_id,
    clientId: row.client_id,
    peerName: row.name || `peer #${row.client_id}`
  }
  shareOpen.value = true
}

async function loadProcesses () {
  processLoading.value = true
  try {
    const { data } = await api.get('/api/system/processes', {
      params: { sort: processModalSort.value, limit: 40 }
    })
    processRows.value = data.processes || []
    containerRows.value = data.containers || []
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('dashboard.loadProcessesError') })
  } finally {
    processLoading.value = false
  }
}

function stopProcessPolling () {
  if (processPollTimer) {
    clearInterval(processPollTimer)
    processPollTimer = null
  }
}

function startProcessPolling () {
  stopProcessPolling()
  void loadProcesses()
  processPollTimer = setInterval(() => {
    void loadProcesses()
  }, liveState.intervalMs || 10000)
}

function openProcessModal (sort = 'cpu') {
  processModalSort.value = sort === 'mem' ? 'mem' : 'cpu'
  processRows.value = []
  containerRows.value = []
  processModalOpen.value = true
  startProcessPolling()
}

watch(() => liveState.intervalMs, () => {
  if (processModalOpen.value) startProcessPolling()
})

onMounted(async () => {
  liveOff = onLiveStats(() => updateStatsAvailable())

  await loadFromDb()
  restartLive()
})

onUnmounted(() => {
  if (liveOff) liveOff()
  stopProcessPolling()
  if (subscribedConfigIds.value.length) {
    stopLivePolling(subscribedConfigIds.value)
  }
})
</script>

<style scoped>
.process-modal-card {
  min-width: min(900px, 96vw);
}

.process-cmd {
  max-width: 520px;
  font-family: var(--theme-mono);
  font-size: 0.85em;
}
.process-table :deep(td) {
  font-family: var(--theme-mono);
}

.graph-dialog-card {
  width: 100%;
  height: 100%;
  max-height: 100%;
}

.graph-dialog-body {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.graph-dialog-body :deep(.graph-wrap) {
  flex: 1 1 auto;
}
</style>
