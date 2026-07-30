<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md q-col-gutter-sm">
        <div class="col">
          <div class="text-h5">{{ t('resolver.speedTestTitle') }}</div>
          <div class="text-body2 text-grey-5 q-mt-xs">
            {{ t('resolver.speedTestDesc') }}
          </div>
        </div>
        <div class="col-auto row q-gutter-sm">
          <q-btn flat color="primary" icon="cable" :label="t('nav.connections')" :to="{ name: 'resolver-connections' }" />
          <q-btn flat color="primary" icon="alt_route" :label="t('resolver.title')" :to="{ name: 'resolver' }" />
        </div>
      </div>

      <q-banner class="q-mb-md bg-grey-9 text-grey-3" rounded dense>
        {{ t('resolver.speedTestHint') }}
      </q-banner>

      <q-card class="q-pa-md status-card" flat bordered>
        <div class="row items-center q-mb-md q-col-gutter-sm">
          <div class="col">
            <div class="text-subtitle1">{{ t('resolver.speedTestConnections') }}</div>
          </div>
          <div class="col-auto">
            <q-btn
              color="primary"
              icon="speed"
              :label="t('resolver.speedTestAll')"
              :loading="batchRunning"
              :disable="busy || !enabledRows.length"
              @click="runBatch"
            />
          </div>
          <div class="col-auto">
            <q-btn flat icon="refresh" :label="t('common.refresh')" :loading="loading" @click="load" />
          </div>
        </div>

        <q-table
          flat
          bordered
          dense
          row-key="id"
          :rows="rows"
          :columns="columns"
          :loading="loading"
          hide-pagination
          :rows-per-page-options="[0]"
        >
          <template #body="props">
            <q-tr :props="props">
              <q-td key="name" :props="props">
                <div class="text-weight-medium">{{ props.row.name }}</div>
                <div class="text-caption text-grey-5 mono">{{ props.row.tag || `conn_${props.row.id}` }}</div>
              </q-td>
              <q-td key="mode" :props="props">
                <q-badge outline :color="props.row.enabled ? 'positive' : 'grey'">
                  {{ modeLabel(props.row) }}
                </q-badge>
              </q-td>
              <q-td key="ping" :props="props" class="mono">
                {{ formatPing(resultFor(props.row.id)) }}
              </q-td>
              <q-td key="down" :props="props" class="mono">
                {{ formatMbps(resultFor(props.row.id)?.download_mbps) }}
              </q-td>
              <q-td key="up" :props="props" class="mono">
                {{ formatMbps(resultFor(props.row.id)?.upload_mbps) }}
              </q-td>
              <q-td key="status" :props="props">
                <div v-if="isRunning(props.row.id)" class="row items-center q-gutter-xs">
                  <q-spinner size="16px" color="primary" />
                  <span class="text-caption">{{ t('resolver.speedTestRunning') }}</span>
                </div>
                <div v-else-if="resultFor(props.row.id)?.error" class="text-negative text-caption">
                  {{ resultFor(props.row.id).error }}
                </div>
                <div v-else-if="resultFor(props.row.id)" class="text-positive text-caption">
                  {{ t('resolver.speedTestDone') }}
                </div>
                <span v-else class="text-grey-6">—</span>
              </q-td>
              <q-td key="actions" :props="props" class="text-right">
                <q-btn
                  flat
                  dense
                  color="primary"
                  icon="speed"
                  :label="t('resolver.speedTestRun')"
                  :loading="isRunning(props.row.id)"
                  :disable="busy || !props.row.enabled"
                  @click="runOne(props.row)"
                />
                <q-btn
                  v-if="props.row.subscription_mode === 'urltest' && (props.row.subscription_nodes || []).length"
                  flat
                  dense
                  icon="expand_more"
                  :label="t('resolver.speedTestNodes')"
                  @click="toggleExpand(props.row.id)"
                />
              </q-td>
            </q-tr>
            <q-tr v-if="expanded[props.row.id]" :props="props">
              <q-td colspan="7" class="bg-grey-10">
                <div class="q-pa-sm">
                  <div class="text-caption text-grey-5 q-mb-sm">{{ t('resolver.speedTestNodesHint') }}</div>
                  <q-markup-table flat dense bordered class="bg-transparent">
                    <thead>
                      <tr>
                        <th class="text-left">{{ t('resolver.speedTestNode') }}</th>
                        <th class="text-left">Ping</th>
                        <th class="text-left">↓ Mbps</th>
                        <th class="text-left">↑ Mbps</th>
                        <th class="text-right" />
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="node in (props.row.subscription_nodes || []).slice(0, 40)" :key="node.key">
                        <td>
                          <div>{{ node.name || node.key }}</div>
                          <div class="text-caption text-grey-5 mono">{{ node.key }}</div>
                        </td>
                        <td class="mono">{{ formatPing(nodeResult(props.row.id, node.key)) }}</td>
                        <td class="mono">{{ formatMbps(nodeResult(props.row.id, node.key)?.download_mbps) }}</td>
                        <td class="mono">{{ formatMbps(nodeResult(props.row.id, node.key)?.upload_mbps) }}</td>
                        <td class="text-right">
                          <q-btn
                            flat
                            dense
                            color="primary"
                            icon="speed"
                            :loading="isNodeRunning(props.row.id, node.key)"
                            :disable="busy || !props.row.enabled"
                            @click="runNode(props.row, node.key)"
                          />
                        </td>
                      </tr>
                    </tbody>
                  </q-markup-table>
                </div>
              </q-td>
            </q-tr>
          </template>
        </q-table>
      </q-card>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Notify } from 'quasar'
import api from '@/boot/axios'

const { t } = useI18n()

const loading = ref(false)
const rows = ref([])
const results = reactive({})
const nodeResults = reactive({})
const running = reactive({})
const expanded = reactive({})
const batchRunning = ref(false)

const busy = computed(() => batchRunning.value || Object.values(running).some(Boolean))
const enabledRows = computed(() => rows.value.filter(r => r.enabled))

const columns = computed(() => [
  { name: 'name', label: t('resolver.speedTestColName'), field: 'name', align: 'left' },
  { name: 'mode', label: t('resolver.speedTestColMode'), field: 'subscription_mode', align: 'left' },
  { name: 'ping', label: 'Ping', field: 'ping', align: 'left' },
  { name: 'down', label: '↓ Mbps', field: 'down', align: 'left' },
  { name: 'up', label: '↑ Mbps', field: 'up', align: 'left' },
  { name: 'status', label: t('resolver.speedTestColStatus'), field: 'status', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' }
])

function modeLabel (row) {
  if (!row.enabled) return t('resolver.speedTestDisabled')
  if (row.kind === 'subscription' && row.subscription_mode === 'urltest') {
    return t('resolver.speedTestUrltest', { n: row.subscription_nodes_count || 0 })
  }
  if (row.kind === 'subscription') return t('resolver.speedTestSingle')
  return t('resolver.speedTestManual')
}

function resultKey (connectionId, nodeKey = null) {
  return nodeKey ? `${connectionId}::${nodeKey}` : String(connectionId)
}

function resultFor (connectionId) {
  return results[connectionId] || null
}

function nodeResult (connectionId, nodeKey) {
  return nodeResults[resultKey(connectionId, nodeKey)] || null
}

function isRunning (connectionId) {
  return !!running[resultKey(connectionId)]
}

function isNodeRunning (connectionId, nodeKey) {
  return !!running[resultKey(connectionId, nodeKey)]
}

function formatPing (res) {
  if (!res || res.ping_ms == null) return '—'
  return `${res.ping_ms} ms`
}

function formatMbps (v) {
  if (v == null || Number.isNaN(Number(v))) return '—'
  return Number(v).toFixed(2)
}

function toggleExpand (id) {
  expanded[id] = !expanded[id]
}

function applyResult (connectionId, nodeKey, data) {
  if (nodeKey) {
    nodeResults[resultKey(connectionId, nodeKey)] = data
  } else {
    results[connectionId] = data
  }
}

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/api/resolver/connections')
    rows.value = Array.isArray(data?.connections) ? data.connections : (Array.isArray(data) ? data : [])
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || e.message || t('resolver.speedTestLoadFailed') })
  } finally {
    loading.value = false
  }
}

async function runOne (row) {
  const key = resultKey(row.id)
  running[key] = true
  try {
    const { data } = await api.post(`/api/resolver/connections/${row.id}/speed-test`, {}, { timeout: 120000 })
    applyResult(row.id, null, data)
    if (!data.ok) {
      Notify.create({ type: 'warning', message: data.error || t('resolver.speedTestFailed') })
    }
  } catch (e) {
    const data = e?.response?.data
    if (data && typeof data === 'object') {
      applyResult(row.id, null, data)
    }
    Notify.create({ type: 'negative', message: data?.error || e.message || t('resolver.speedTestFailed') })
  } finally {
    running[key] = false
  }
}

async function runNode (row, nodeKey) {
  const key = resultKey(row.id, nodeKey)
  running[key] = true
  try {
    const { data } = await api.post(
      `/api/resolver/connections/${row.id}/speed-test`,
      { node_key: nodeKey },
      { timeout: 120000 }
    )
    applyResult(row.id, nodeKey, data)
    if (!data.ok) {
      Notify.create({ type: 'warning', message: data.error || t('resolver.speedTestFailed') })
    }
  } catch (e) {
    const data = e?.response?.data
    if (data && typeof data === 'object') {
      applyResult(row.id, nodeKey, data)
    }
    Notify.create({ type: 'negative', message: data?.error || e.message || t('resolver.speedTestFailed') })
  } finally {
    running[key] = false
  }
}

async function runBatch () {
  batchRunning.value = true
  try {
    const { data } = await api.post('/api/resolver/speed-test/batch', {}, { timeout: 600000 })
    for (const item of (data.results || [])) {
      if (item?.connection_id != null) {
        applyResult(item.connection_id, null, item)
      }
    }
    if (!data.ok) {
      Notify.create({ type: 'warning', message: data.error || t('resolver.speedTestFailed') })
    } else {
      Notify.create({ type: 'positive', message: t('resolver.speedTestBatchDone') })
    }
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.error || e.message || t('resolver.speedTestFailed') })
  } finally {
    batchRunning.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<style scoped>
.page-wrap {
  max-width: 1100px;
  margin: 0 auto;
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
</style>
