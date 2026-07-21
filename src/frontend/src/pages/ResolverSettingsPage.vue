<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md q-col-gutter-sm">
        <div class="col">
          <div class="text-h5">{{ t('resolver.listSettingsTitle') }}</div>
          <div class="text-body2 text-grey-5 q-mt-xs">
            {{ t('resolver.listSettingsDesc') }}
          </div>
        </div>
        <div class="col-auto">
          <q-btn flat color="primary" icon="alt_route" :label="t('resolver.title')" :to="{ name: 'resolver' }" />
        </div>
      </div>

      <q-card class="q-pa-md q-mb-md status-card" flat bordered>
        <div class="text-subtitle2 q-mb-sm">{{ t('resolver.syncInterval') }}</div>
        <div class="row q-col-gutter-md items-end">
          <div class="col-12 col-sm-4 col-md-3">
            <q-input
              v-model.number="intervalMinutes"
              type="number"
              min="5"
              max="10080"
              :label="t('resolver.minutes')"
              :hint="t('resolver.syncIntervalHint')"

              filled
              dense
            />
          </div>
          <div class="col-12 col-sm-auto">
            <q-btn color="primary" :label="t('resolver.saveInterval')" :loading="savingInterval" @click="saveInterval" />
          </div>
          <div class="col-12 col-sm">
            <div class="text-caption text-grey-5">
              {{ t('resolver.lastFullSync') }}
              <span class="mono">{{ formatTs(lastSyncAt) }}</span>
            </div>
          </div>
          <div class="col-12 col-sm-auto">
            <q-btn
              outline
              color="warning"
              icon="cloud_download"
              :label="t('resolver.downloadAllNow')"
              :loading="syncingAll"
              :disable="!!syncingTag"
              @click="syncAll"
            />
          </div>
        </div>
      </q-card>

      <q-card class="q-pa-md status-card" flat bordered>
        <div class="row items-center q-mb-sm q-col-gutter-sm">
          <div class="col text-subtitle2">{{ t('resolver.lists') }}</div>
          <div class="col-auto">
            <q-btn flat dense color="primary" icon="open_in_full" :label="t('resolver.allLists')" @click="modalOpen = true" />
            <q-btn class="q-ml-sm" color="primary" icon="add" :label="t('resolver.customList')" @click="openEdit(null)" />
          </div>
        </div>

        <q-table
          flat

          dense
          row-key="tag"
          :rows="lists"
          :columns="columns"
          :loading="loading"
          :pagination="{ rowsPerPage: 20 }"
        >
          <template #body-cell-label="props">
            <q-td :props="props">
              <a
                v-if="props.row.source_url"
                :href="props.row.source_url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-primary"
                @click.stop
              >{{ props.row.label }}</a>
              <span v-else>{{ props.row.label }}</span>
            </q-td>
          </template>
          <template #body-cell-kind="props">
            <q-td :props="props">
              <q-badge :color="props.row.kind === 'custom' ? 'teal' : 'grey-7'">
                {{ props.row.kind === 'custom' ? (props.row.source_url ? t('resolver.kindCustomUrl') : t('resolver.kindCustom')) : t('resolver.kindCommunity') }}
              </q-badge>
            </q-td>
          </template>
          <template #body-cell-on_disk="props">
            <q-td :props="props">
              <span v-if="props.row.on_disk" class="text-positive">
                {{ formatSize(props.row.size) }}
              </span>
              <span v-else class="text-negative">{{ t('common.none') }}</span>
            </q-td>
          </template>
          <template #body-cell-downloaded_at="props">
            <q-td :props="props">
              <span class="mono text-caption">{{ formatTs(props.row.downloaded_at) }}</span>
            </q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn
                v-if="props.row.can_sync"
                flat
                dense
                round
                icon="cloud_download"
                color="warning"
                :loading="syncingTag === props.row.tag"
                :disable="syncingAll || (!!syncingTag && syncingTag !== props.row.tag)"
                @click="syncOne(props.row.tag)"
              >
                <q-tooltip>{{ syncingAll ? t('resolver.fullSyncInProgress') : t('resolver.download') }}</q-tooltip>
              </q-btn>
              <q-btn
                v-if="props.row.can_edit"
                flat
                dense
                round
                icon="edit"
                color="primary"
                :disable="syncingAll"
                @click="openEdit(props.row)"
              >
                <q-tooltip>{{ t('resolver.editList') }}</q-tooltip>
              </q-btn>
              <q-btn
                v-if="props.row.can_delete"
                flat
                dense
                round
                icon="delete"
                color="negative"
                :loading="deletingId === props.row.id"
                :disable="syncingAll"
                @click="removeList(props.row)"
              >
                <q-tooltip>{{ t('common.delete') }}</q-tooltip>
              </q-btn>
            </q-td>
          </template>
        </q-table>
      </q-card>

      <q-dialog v-model="modalOpen" maximized transition-show="slide-up" transition-hide="slide-down">
        <q-card class="surface-panel dialog-card column no-wrap">
          <DialogHeader :title="t('resolver.allLists')" always-show-close />
          <q-card-section class="q-pt-none col dialog-scroll-body">
            <div class="row q-mb-md">
              <q-btn
                outline
                color="warning"
                icon="cloud_download"
                :label="t('resolver.downloadAllCommunity')"
                :loading="syncingAll"
                :disable="!!syncingTag"
                @click="syncAll"
              />
            </div>
            <q-table
              flat

              dense
              row-key="tag"
              :rows="lists"
              :columns="columns"
              :pagination="{ rowsPerPage: 0 }"
            >
              <template #body-cell-label="props">
                <q-td :props="props">
                  <a
                    v-if="props.row.source_url"
                    :href="props.row.source_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-primary"
                    @click.stop
                  >{{ props.row.label }}</a>
                  <span v-else>{{ props.row.label }}</span>
                </q-td>
              </template>
              <template #body-cell-kind="props">
                <q-td :props="props">
                  <q-badge :color="props.row.kind === 'custom' ? 'teal' : 'grey-7'">
                    {{ props.row.kind === 'custom' ? (props.row.source_url ? t('resolver.kindCustomUrl') : t('resolver.kindCustom')) : t('resolver.kindCommunity') }}
                  </q-badge>
                </q-td>
              </template>
              <template #body-cell-on_disk="props">
                <q-td :props="props">
                  <span v-if="props.row.on_disk" class="text-positive">{{ formatSize(props.row.size) }}</span>
                  <span v-else class="text-negative">{{ t('common.none') }}</span>
                </q-td>
              </template>
              <template #body-cell-downloaded_at="props">
                <q-td :props="props">
                  <span class="mono text-caption">{{ formatTs(props.row.downloaded_at) }}</span>
                </q-td>
              </template>
              <template #body-cell-actions="props">
                <q-td :props="props">
                  <q-btn
                    v-if="props.row.can_sync"
                    flat
                    dense
                    icon="cloud_download"
                    color="warning"
                    label="Sync"
                    :loading="syncingTag === props.row.tag"
                    :disable="syncingAll || (!!syncingTag && syncingTag !== props.row.tag)"
                    @click="syncOne(props.row.tag)"
                  />
                  <q-btn
                    v-if="props.row.can_edit"
                    flat
                    dense
                    icon="edit"
                    color="primary"
                    :label="t('resolver.editList')"
                    :disable="syncingAll"
                    @click="openEdit(props.row)"
                  />
                </q-td>
              </template>
            </q-table>
          </q-card-section>
        </q-card>
      </q-dialog>

      <q-dialog v-model="editOpen" v-bind="mobileDialog" persistent>
        <q-card style="min-width: 420px; max-width: 640px" class="surface-panel dialog-card column no-wrap">
          <DialogHeader :title="editId ? t('resolver.editListTitle') : t('resolver.newListTitle')" />
          <q-card-section class="col dialog-scroll-body q-pt-none">
          <q-input v-model="editForm.name" :label="t('resolver.listName')" filled dense class="q-mb-md" />
          <q-btn-toggle
            v-model="editForm.mode"
            class="q-mb-md"
            spread
            unelevated
            toggle-color="primary"

            :options="[
              { label: t('resolver.sourceText'), value: 'text' },
              { label: t('resolver.sourceUrl'), value: 'url' }
            ]"
          />
          <template v-if="editForm.mode === 'url'">
            <q-input
              v-model="editForm.sourceUrl"
              :label="t('resolver.listUrl')"

              filled
              dense
              class="q-mb-md"
              :hint="t('resolver.listUrlHint')"
            />
          </template>
          <template v-else>
            <q-input
              v-model="editForm.domainsText"
              :label="t('resolver.domains')"
              type="textarea"
              :input-style="{ minHeight: '140px' }"
              rows="8"

              filled
              class="q-mb-md"
              :hint="t('resolver.domainsListHint')"
            />
            <q-input
              v-model="editForm.cidrsText"
              label="CIDR / IP"
              type="textarea"
              :input-style="{ minHeight: '140px' }"
              rows="8"

              filled
              class="q-mb-md"
              :hint="t('resolver.cidrsListHint')"
            />
          </template>
          </q-card-section>
          <q-card-actions align="right">
            <q-btn flat :label="t('common.cancel')" v-close-popup />
            <q-btn color="primary" :label="t('common.save')" :loading="savingEdit" @click="saveEdit" />
          </q-card-actions>
        </q-card>
      </q-dialog>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import { useMobileDialog } from '@/composables/useMobileDialog'
import DialogHeader from '@/components/DialogHeader.vue'
import { bcp47Locale } from '@/i18n'

const { t, locale } = useI18n()
const $q = useQuasar()
const mobileDialog = useMobileDialog()
const loading = ref(true)
const savingInterval = ref(false)
const syncingAll = ref(false)
const syncingTag = ref(null)
const deletingId = ref(null)
const intervalMinutes = ref(360)
const lastSyncAt = ref(null)
const lists = ref([])
const modalOpen = ref(false)
const editOpen = ref(false)
const editId = ref(null)
const savingEdit = ref(false)
const editForm = reactive({
  name: '',
  mode: 'text',
  sourceUrl: '',
  domainsText: '',
  cidrsText: ''
})

const columns = computed(() => [
  { name: 'label', label: t('resolver.listName'), field: 'label', align: 'left' },
  { name: 'kind', label: t('resolver.colType'), field: 'kind', align: 'left' },
  { name: 'on_disk', label: t('resolver.colOnDisk'), field: 'on_disk', align: 'left' },
  { name: 'downloaded_at', label: t('resolver.colUpdated'), field: 'downloaded_at', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' }
])

function formatTs (iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(bcp47Locale(locale.value))
  } catch {
    return iso
  }
}

function formatSize (bytes) {
  const n = Number(bytes) || 0
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`
  return `${(n / (1024 * 1024)).toFixed(1)} MB`
}

function applyPayload (data) {
  intervalMinutes.value = data.sync_interval_minutes ?? 360
  lastSyncAt.value = data.last_sync_at || null
  lists.value = data.lists || []
}

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/api/resolver/settings')
    applyPayload(data)
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('resolver.loadSettingsError') })
  } finally {
    loading.value = false
  }
}

async function saveInterval () {
  savingInterval.value = true
  try {
    const { data } = await api.put('/api/resolver/settings', {
      sync_interval_minutes: Number(intervalMinutes.value)
    })
    applyPayload(data)
    $q.notify({ type: 'positive', message: t('resolver.intervalSaved') })
  } catch (e) {
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || t('common.saveError')
    $q.notify({ type: 'negative', message: msg })
  } finally {
    savingInterval.value = false
  }
}

async function syncAll () {
  if (syncingAll.value || syncingTag.value) return
  syncingAll.value = true
  try {
    const { data } = await api.post('/api/resolver/settings/sync-lists')
    applyPayload(data)
    $q.notify({ type: data.ok === false ? 'warning' : 'positive', message: data.message || t('resolver.listsDownloaded') })
  } catch (e) {
    if (e?.response?.data?.lists) applyPayload(e.response.data)
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('resolver.downloadError')
    })
  } finally {
    syncingAll.value = false
  }
}

async function syncOne (tag) {
  if (syncingAll.value || syncingTag.value) return
  syncingTag.value = tag
  try {
    const { data } = await api.post(`/api/resolver/settings/sync-lists/${encodeURIComponent(tag)}`)
    applyPayload(data)
    $q.notify({ type: 'positive', message: t('resolver.downloadedTag', { tag }) })
  } catch (e) {
    if (e?.response?.data?.lists) applyPayload(e.response.data)
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('resolver.errorTag', { tag })
    })
  } finally {
    syncingTag.value = null
  }
}

function linesToArray (text) {
  return String(text || '')
    .split(/\r?\n/)
    .map(s => s.trim())
    .filter(Boolean)
}

function openEdit (row) {
  editId.value = row?.id || null
  editForm.name = row?.label || ''
  editForm.mode = row?.source_url ? 'url' : 'text'
  editForm.sourceUrl = row?.source_url || ''
  editForm.domainsText = (row?.domains || []).join('\n')
  editForm.cidrsText = (row?.cidrs || []).join('\n')
  editOpen.value = true
}

async function saveEdit () {
  savingEdit.value = true
  const payload = {
    name: editForm.name,
    source_url: editForm.mode === 'url' ? editForm.sourceUrl.trim() : null,
    domains: editForm.mode === 'text' ? linesToArray(editForm.domainsText) : [],
    cidrs: editForm.mode === 'text' ? linesToArray(editForm.cidrsText) : []
  }
  try {
    const { data } = editId.value
      ? await api.put(`/api/resolver/custom-lists/${editId.value}`, payload)
      : await api.post('/api/resolver/custom-lists', payload)
    if (data.settings) applyPayload(data.settings)
    else await load()
    editOpen.value = false
    $q.notify({ type: 'positive', message: t('resolver.listSaved') })
  } catch (e) {
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || t('resolver.listSaveError')
    $q.notify({ type: 'negative', message: msg })
  } finally {
    savingEdit.value = false
  }
}

async function removeList (row) {
  $q.dialog({
    title: t('resolver.deleteListTitle'),
    message: t('resolver.deleteListConfirm', { label: row.label }),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    deletingId.value = row.id
    try {
      const { data } = await api.delete(`/api/resolver/custom-lists/${row.id}`)
      if (data.settings) applyPayload(data.settings)
      else await load()
      $q.notify({ type: 'positive', message: t('common.deleted') })
    } catch (e) {
      $q.notify({ type: 'negative', message: e?.response?.data?.message || t('common.deleteError') })
    } finally {
      deletingId.value = null
    }
  })
}

onMounted(load)
</script>

<style scoped>
.page-wrap {
  max-width: 1100px;
  margin: 0 auto;
}
.status-card {
  background: var(--surface-panel);
  border-color: var(--surface-border) !important;
}
.mono {
  font-family: var(--theme-mono);
}
</style>
