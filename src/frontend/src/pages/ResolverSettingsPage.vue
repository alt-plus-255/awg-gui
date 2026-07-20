<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md q-col-gutter-sm">
        <div class="col">
          <div class="text-h5">Настройки списков</div>
          <div class="text-body2 text-grey-5 q-mt-xs">
            Интервал скачивания community-списков, свои списки и файлы на диске.
          </div>
        </div>
        <div class="col-auto">
          <q-btn flat color="primary" icon="alt_route" label="Резолвер" :to="{ name: 'resolver' }" />
        </div>
      </div>

      <q-card class="q-pa-md q-mb-md status-card" flat bordered>
        <div class="text-subtitle2 q-mb-sm">Интервал синхронизации</div>
        <div class="row q-col-gutter-md items-end">
          <div class="col-12 col-sm-4 col-md-3">
            <q-input
              v-model.number="intervalMinutes"
              type="number"
              min="5"
              max="10080"
              label="Минуты"
              hint="По умолчанию 360 (6 ч). Минимум 5."

              filled
              dense
            />
          </div>
          <div class="col-12 col-sm-auto">
            <q-btn color="primary" label="Сохранить интервал" :loading="savingInterval" @click="saveInterval" />
          </div>
          <div class="col-12 col-sm">
            <div class="text-caption text-grey-5">
              Последняя полная sync:
              <span class="mono">{{ formatTs(lastSyncAt) }}</span>
            </div>
          </div>
          <div class="col-12 col-sm-auto">
            <q-btn
              outline
              color="warning"
              icon="cloud_download"
              label="Скачать все сейчас"
              :loading="syncingAll"
              :disable="!!syncingTag"
              @click="syncAll"
            />
          </div>
        </div>
      </q-card>

      <q-card class="q-pa-md status-card" flat bordered>
        <div class="row items-center q-mb-sm q-col-gutter-sm">
          <div class="col text-subtitle2">Списки</div>
          <div class="col-auto">
            <q-btn flat dense color="primary" icon="open_in_full" label="Все списки" @click="modalOpen = true" />
            <q-btn class="q-ml-sm" color="primary" icon="add" label="Свой список" @click="openEdit(null)" />
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
                {{ props.row.kind === 'custom' ? (props.row.source_url ? 'свой · URL' : 'свой') : 'community' }}
              </q-badge>
            </q-td>
          </template>
          <template #body-cell-on_disk="props">
            <q-td :props="props">
              <span v-if="props.row.on_disk" class="text-positive">
                {{ formatSize(props.row.size) }}
              </span>
              <span v-else class="text-negative">нет</span>
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
                <q-tooltip>{{ syncingAll ? 'Идёт полная синхронизация' : 'Скачать' }}</q-tooltip>
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
                <q-tooltip>Править</q-tooltip>
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
                <q-tooltip>Удалить</q-tooltip>
              </q-btn>
            </q-td>
          </template>
        </q-table>
      </q-card>

      <q-dialog v-model="modalOpen" maximized transition-show="slide-up" transition-hide="slide-down">
        <q-card class="surface-panel dialog-card column no-wrap">
          <q-card-section class="row items-center">
            <div class="text-h6">Все списки</div>
            <q-space />
            <q-btn flat round dense icon="close" v-close-popup />
          </q-card-section>
          <q-card-section class="q-pt-none col dialog-scroll-body">
            <div class="row q-mb-md">
              <q-btn
                outline
                color="warning"
                icon="cloud_download"
                label="Скачать все community"
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
                    {{ props.row.kind === 'custom' ? (props.row.source_url ? 'свой · URL' : 'свой') : 'community' }}
                  </q-badge>
                </q-td>
              </template>
              <template #body-cell-on_disk="props">
                <q-td :props="props">
                  <span v-if="props.row.on_disk" class="text-positive">{{ formatSize(props.row.size) }}</span>
                  <span v-else class="text-negative">нет</span>
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
                    label="Править"
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
        <q-card style="min-width: 420px; max-width: 640px" class="surface-panel q-pa-md dialog-card column no-wrap">
          <div class="text-h6 q-mb-md">{{ editId ? 'Редактировать список' : 'Новый список' }}</div>
          <div class="col dialog-scroll-body">
          <q-input v-model="editForm.name" label="Имя" filled dense class="q-mb-md" />
          <q-btn-toggle
            v-model="editForm.mode"
            class="q-mb-md"
            spread
            unelevated
            toggle-color="primary"

            :options="[
              { label: 'Текст', value: 'text' },
              { label: 'Ссылка', value: 'url' }
            ]"
          />
          <template v-if="editForm.mode === 'url'">
            <q-input
              v-model="editForm.sourceUrl"
              label="URL списка"

              filled
              dense
              class="q-mb-md"
              hint="Как community: .srs или текстовый список доменов/CIDR по строкам"
            />
          </template>
          <template v-else>
            <q-input
              v-model="editForm.domainsText"
              label="Домены"
              type="textarea"
              :input-style="{ minHeight: '140px' }"
              rows="8"

              filled
              class="q-mb-md"
              hint="По одному домену на строку, например example.com"
            />
            <q-input
              v-model="editForm.cidrsText"
              label="CIDR / IP"
              type="textarea"
              :input-style="{ minHeight: '140px' }"
              rows="8"

              filled
              class="q-mb-md"
              hint="По одному на строку: 1.2.3.0/24 или 1.2.3.4"
            />
          </template>
          </div>
          <div class="row q-gutter-sm justify-end">
            <q-btn flat label="Отмена" v-close-popup />
            <q-btn color="primary" label="Сохранить" :loading="savingEdit" @click="saveEdit" />
          </div>
        </q-card>
      </q-dialog>
    </div>
  </q-page>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import { useMobileDialog } from '@/composables/useMobileDialog'

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

const columns = [
  { name: 'label', label: 'Имя', field: 'label', align: 'left' },
  { name: 'kind', label: 'Тип', field: 'kind', align: 'left' },
  { name: 'on_disk', label: 'На диске', field: 'on_disk', align: 'left' },
  { name: 'downloaded_at', label: 'Обновлён', field: 'downloaded_at', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' }
]

function formatTs (iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString()
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
    $q.notify({ type: 'negative', message: e?.response?.data?.message || 'Не удалось загрузить настройки' })
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
    $q.notify({ type: 'positive', message: 'Интервал сохранён' })
  } catch (e) {
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || 'Ошибка сохранения'
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
    $q.notify({ type: data.ok === false ? 'warning' : 'positive', message: data.message || 'Списки скачаны' })
  } catch (e) {
    if (e?.response?.data?.lists) applyPayload(e.response.data)
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || 'Ошибка скачивания'
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
    $q.notify({ type: 'positive', message: `Скачан: ${tag}` })
  } catch (e) {
    if (e?.response?.data?.lists) applyPayload(e.response.data)
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || `Ошибка: ${tag}`
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
    $q.notify({ type: 'positive', message: 'Список сохранён' })
  } catch (e) {
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || 'Ошибка сохранения списка'
    $q.notify({ type: 'negative', message: msg })
  } finally {
    savingEdit.value = false
  }
}

async function removeList (row) {
  $q.dialog({
    title: 'Удалить список?',
    message: `«${row.label}» будет удалён с диска и из конфигов.`,
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    deletingId.value = row.id
    try {
      const { data } = await api.delete(`/api/resolver/custom-lists/${row.id}`)
      if (data.settings) applyPayload(data.settings)
      else await load()
      $q.notify({ type: 'positive', message: 'Удалено' })
    } catch (e) {
      $q.notify({ type: 'negative', message: e?.response?.data?.message || 'Ошибка удаления' })
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
