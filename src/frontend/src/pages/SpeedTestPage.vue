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

      <q-card class="q-pa-md status-card" flat bordered>
        <div class="row items-center q-mb-md q-col-gutter-sm">
          <div class="col">
            <div class="text-subtitle1">{{ t('resolver.speedTestConnections') }}</div>
            <div v-if="store.busy" class="text-caption text-primary q-mt-xs row items-center q-gutter-xs">
              <q-spinner size="14px" color="primary" />
              <span>{{ t('resolver.speedTestRunning') }}</span>
            </div>
          </div>
          <div class="col-auto">
            <q-btn
              color="primary"
              icon="speed"
              :label="t('resolver.speedTestAll')"
              :loading="store.starting"
              :disable="store.busy || !enabledRows.length"
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
                  :disable="store.busy || !props.row.enabled"
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
                          <div
                            v-if="nodeResult(props.row.id, node.key)?.error"
                            class="text-caption text-negative q-mt-xs"
                          >
                            {{ nodeResult(props.row.id, node.key).error }}
                          </div>
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
                            :disable="store.busy || !props.row.enabled"
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
import { useSpeedTestStore } from '@/stores/speedTest'

const { t } = useI18n()
const store = useSpeedTestStore()

const loading = ref(false)
const rows = ref([])
const expanded = reactive({})

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

function resultFor (connectionId) {
  return store.results[String(connectionId)] || store.results[connectionId] || null
}

function nodeResult (connectionId, nodeKey) {
  return store.nodeResults[store.resultKey(connectionId, nodeKey)] || null
}

function isRunning (connectionId) {
  return store.isRunningKey(connectionId)
}

function isNodeRunning (connectionId, nodeKey) {
  return store.isRunningKey(connectionId, nodeKey)
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

async function load () {
  loading.value = true
  try {
    const [{ data }] = await Promise.all([
      api.get('/api/resolver/connections'),
      store.fetchStatus()
    ])
    rows.value = Array.isArray(data?.connections) ? data.connections : (Array.isArray(data) ? data : [])
  } catch (e) {
    Notify.create({ type: 'negative', message: e?.response?.data?.message || e.message || t('resolver.speedTestLoadFailed') })
  } finally {
    loading.value = false
  }
}

async function runOne (row) {
  await store.startConnection(row.id)
}

async function runNode (row, nodeKey) {
  await store.startConnection(row.id, nodeKey)
}

async function runBatch () {
  await store.startBatch()
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
