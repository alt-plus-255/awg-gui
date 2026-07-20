<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="resolver-header q-mb-md">
        <div class="text-h5 resolver-header__title">Резолвер</div>
        <div class="resolver-header__actions">
          <q-btn
            flat
            dense
            no-wrap
            color="primary"
            icon="cable"
            label="Подключения"
            :to="{ name: 'resolver-connections' }"
          />
          <q-btn
            flat
            dense
            no-wrap
            color="primary"
            icon="tune"
            label="Настройки списков"
            :to="{ name: 'resolver-settings' }"
          />
        </div>
        <div class="text-body2 text-grey-5 resolver-header__desc">
          Полный туннель на VDS: домены из списков — через выбранное подключение (FakeIP),
          остальной трафик (2ip.ru и т.п.) — с IP сервера VDS.
        </div>
      </div>

      <q-banner
        rounded
        class="q-mb-md surface-panel-alt surface-border"
      >
        <template #avatar>
          <q-icon name="info" color="warning" />
        </template>
        <div class="text-body2">
          <div class="q-mb-xs">
            <strong>Обязательно после включения/изменения резолвера:</strong> удалите сервер в AmneziaWG
            и <strong>заново импортируйте</strong> QR / .conf на <strong>iPhone и Android</strong>.
          </div>
          <div class="q-mb-xs text-caption text-grey-4">
            В .conf должны быть <span class="mono">DNS = gateway</span> и
            <span class="mono">AllowedIPs = 0.0.0.0/0</span> (полный туннель).
            Без переимпорта списки и IP VDS на 2ip.ru не заработают.
          </div>
          <div class="q-mb-xs">
            <strong>2ip.ru / сайты вне списков</strong> покажут IP VDS.
            Домены из списков (YouTube, Telegram…) — через выбранное VPN-подключение.
          </div>
          <div class="q-mb-xs">
            iPhone: отключите <strong>iCloud Private Relay</strong>.
            Android: <strong>Private DNS / DoH</strong> — выкл.; при сбоях Telegram — очистите кэш приложения.
          </div>
          <div>
            Community-списки скачиваются в
            <router-link class="text-primary" :to="{ name: 'resolver-settings' }">Настройках</router-link>
            (<span class="mono">rulesets/*.srs</span>). При Сохранить HTTP не вызывается.
            Точка VPN —
            <router-link class="text-primary" :to="{ name: 'resolver-connections' }">Подключения</router-link>.
          </div>
        </div>
      </q-banner>

      <q-card class="q-pa-md q-mb-md status-card" flat bordered>
        <div class="text-subtitle2 q-mb-sm">Проверьте на телефоне</div>
        <ul class="q-my-none text-body2 text-grey-4 client-checklist">
          <li>Удалён старый сервер в AmneziaWG, импортирован свежий QR / .conf</li>
          <li>
            <span class="mono">DNS</span> = gateway
            <span v-if="firstEnabledGateway" class="text-grey-5">(напр. {{ firstEnabledGateway }})</span>
          </li>
          <li><span class="mono">AllowedIPs = 0.0.0.0/0</span> (полный туннель на VDS)</li>
          <li>Private DNS выключен (Android)</li>
          <li>2ip.ru → IP VDS; приложения из списков → через VPN-подключение</li>
        </ul>
      </q-card>

      <q-card class="q-pa-md q-mb-md status-card" flat bordered>
        <div class="row items-center q-col-gutter-md">
          <div class="col-auto">
            <q-badge :color="status.healthy ? 'positive' : 'negative'" class="q-pa-sm">
              {{ status.healthy ? 'OK' : 'Ошибка' }}
            </q-badge>
          </div>
          <div class="col">
            <div class="text-subtitle2">
              {{ status.enabled ? 'Резолвер активен' : 'Резолвер выключен' }}
              <span class="text-grey-5"> · sing-box: {{ status.singbox_running ? 'запущен' : 'остановлен' }}</span>
            </div>
            <div class="text-caption text-grey-5">{{ status.message }}</div>
          </div>
          <div class="col-auto text-caption text-grey-5 mono">
            FakeIP {{ status.fakeip_cidr || '198.18.0.0/15' }}
          </div>
        </div>
      </q-card>

      <q-table
        :rows="serverConfigs"
        :columns="columns"
        row-key="id"

        flat
        :loading="loading"
        class="bg-transparent"
        :rows-per-page-options="[10, 25, 0]"
        no-data-label="Нет серверных конфигов"
      >
        <template #body="props">
          <q-tr :props="props">
            <q-td auto-width>
              <q-btn
                flat
                dense
                round
                :icon="props.expand ? 'expand_less' : 'expand_more'"
                @click="toggleExpand(props)"
              />
            </q-td>
            <q-td key="name" :props="props">{{ props.row.name }}</q-td>
            <q-td key="iface" :props="props">
              <span class="mono">{{ props.row.iface }}</span>
            </q-td>
            <q-td key="resolver" :props="props">
              <q-badge :color="props.row.resolver_enabled ? 'deep-purple' : 'grey-8'">
                {{ props.row.resolver_enabled ? 'вкл' : 'выкл' }}
              </q-badge>
            </q-td>
            <q-td key="connection" :props="props">
              {{ props.row.connection_name || '—' }}
            </q-td>
          </q-tr>

          <q-tr v-show="props.expand" :props="props" :key="`e_${props.row.id}`" class="q-virtual-scroll--with-prev">
            <q-td colspan="100%" class="expanded-cell">
              <div v-if="forms[props.row.id]" class="q-pa-md expand-inner">
                <div class="row items-center q-mb-md">
                  <div class="col text-subtitle1">Настройки «{{ props.row.name }}»</div>
                  <q-toggle
                    v-model="forms[props.row.id].resolver_enabled"
                    color="primary"
                    :label="forms[props.row.id].resolver_enabled ? 'Резолвер включён' : 'Резолвер выключен'"
                    :disable="savingId === props.row.id || !props.row.enabled"
                  />
                </div>

                <q-banner
                  v-if="!props.row.enabled"
                  dense
                  rounded
                  class="q-mb-md text-warning surface-warn-bg"
                >
                  Конфиг выключен — резолвер не применяется.
                </q-banner>

                <q-select
                  v-model="forms[props.row.id].connection_id"
                  :options="connectionOptions"
                  :label="forms[props.row.id].resolver_enabled ? 'Подключение (точка VPN) *' : 'Подключение (точка VPN)'"
                  emit-value
                  map-options
                  filled

                  :clearable="!forms[props.row.id].resolver_enabled"
                  class="q-mb-md"
                  :hint="connectionHint(props.row.id)"
                  :disable="!connectionOptions.length"
                  :rules="forms[props.row.id].resolver_enabled
                    ? [v => !!v || 'Выберите подключение']
                    : []"
                  lazy-rules
                >
                  <template #no-option>
                    <q-item>
                      <q-item-section class="text-grey-5">
                        Нет подключений —
                        <router-link :to="{ name: 'resolver-connections' }" class="text-primary">создать</router-link>
                      </q-item-section>
                    </q-item>
                  </template>
                </q-select>

                <q-input
                  v-model="forms[props.row.id].resolver_dns"
                  label="DNS (sing-box / upstream)"
                  hint="Для доменов вне списков FakeIP. В клиентском .conf при включённом резолвере остаётся gateway."
                  filled

                  dense
                  class="q-mb-md"
                  placeholder="1.1.1.1"
                />

                <q-checkbox
                  v-model="forms[props.row.id].resolver_reject_quic"
                  label="Блокировать QUIC (UDP/443) для доменов из списков"
                  dense

                  class="q-mb-md"
                />
                <div class="text-caption text-grey-5 q-mb-md" style="margin-top: -8px">
                  Форсирует TCP для FakeIP-доменов этого конфига (аналог старого YouTube QUIC reject, но для всех выбранных списков).
                </div>

                <div class="text-caption text-grey-5 q-mb-xs">Списки (community + свои)</div>
                <div class="row q-col-gutter-sm q-mb-md">
                  <div
                    v-for="item in selectableLists"
                    :key="item.tag"
                    class="col-12 col-sm-6 col-md-4 col-lg-3"
                  >
                    <q-checkbox
                      v-model="forms[props.row.id].community_lists"
                      :val="item.tag"
                      :label="item.label"
                      dense

                      :disable="isListDisabled(props.row.id, item)"
                      @update:model-value="onListsChange(props.row.id, item)"
                    />
                  </div>
                </div>

                <div class="row q-col-gutter-md q-mb-md">
                  <div class="col-12 col-md-6">
                    <TagListInput
                      v-model="forms[props.row.id].user_domains"
                      label="Свои домены"
                      placeholder="example.com"
                      hint="Enter или «Добавить». Можно вставить список из буфера."
                      empty-hint="Нет доменов — добавьте ниже"
                      :normalize="normalizeDomain"
                      :validate="validateDomain"
                    />
                  </div>
                  <div class="col-12 col-md-6">
                    <TagListInput
                      v-model="forms[props.row.id].user_subnets"
                      label="Свои подсети (опционально)"
                      placeholder="1.2.3.0/24 или 1.2.3.4"
                      hint="CIDR или IP. Enter / «Добавить» / вставка списка."
                      empty-hint="Нет подсетей — добавьте ниже"
                      :normalize="normalizeSubnet"
                      :validate="validateSubnet"
                    />
                  </div>
                </div>

                <div v-if="forms[props.row.id].resolver_enabled" class="text-caption text-grey-5 q-mb-md mono">
                  DNS: {{ props.row.gateway_ip }} · AllowedIPs: {{ previewAllowed(props.row.id) }}
                </div>

                <div class="row q-gutter-sm items-center">
                  <q-btn
                    color="primary"
                    label="Сохранить"
                    :loading="savingId === props.row.id"
                    :disable="!isDirty(props.row.id)"
                    @click="save(props.row.id)"
                  />
                  <div v-if="props.row.resolver_updated_at" class="text-caption text-grey-6">
                    Применено: {{ formatTs(props.row.resolver_updated_at) }}
                  </div>
                </div>
              </div>
            </q-td>
          </q-tr>
        </template>
      </q-table>

      <div v-if="vnConfigs.length" class="text-caption text-grey-6 q-mt-md">
        Виртуальные сети не показаны:
        {{ vnConfigs.map(c => c.name).join(', ') }}.
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import TagListInput from '@/components/TagListInput.vue'
import { useApplyProgress } from '@/composables/useApplyProgress'

const $q = useQuasar()
const { withApplyProgress } = useApplyProgress()
const loading = ref(true)
const savingId = ref(null)
const expandedIds = ref([])
const status = reactive({
  enabled: false,
  healthy: true,
  singbox_running: false,
  message: '',
  fakeip_cidr: '198.18.0.0/15',
  updated_at: null,
  community_lists: [],
  custom_lists: [],
  connections: [],
  configs: []
})
const forms = reactive({})
const baselines = reactive({})

const columns = [
  { name: 'expand', label: '', field: 'expand', align: 'left' },
  { name: 'name', label: 'Конфиг', field: 'name', align: 'left' },
  { name: 'iface', label: 'Интерфейс', field: 'iface', align: 'left' },
  { name: 'resolver', label: 'Резолвер', field: 'resolver_enabled', align: 'left' },
  { name: 'connection', label: 'Подключение', field: 'connection_name', align: 'left' }
]

const selectableLists = computed(() => [
  ...(status.community_lists || []),
  ...(status.custom_lists || [])
])
const serverConfigs = computed(() => (status.configs || []).filter(c => c.type === 'server'))
const vnConfigs = computed(() => (status.configs || []).filter(c => c.type === 'virtual_network'))
const connectionOptions = computed(() =>
  (status.connections || [])
    .filter(c => c.enabled)
    .map(c => ({
      label: `${c.name}${c.outbound_type ? ` (${c.outbound_type})` : ''}`,
      value: c.id
    }))
)

const firstEnabledGateway = computed(() => {
  const cfg = (status.configs || []).find(c => c.type === 'server' && c.resolver_enabled)
  return cfg?.gateway_ip || null
})

function formatTs (iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString()
  } catch {
    return iso
  }
}

function normalizeDomain (raw) {
  let part = String(raw || '').toLowerCase().trim()
  if (!part || part.startsWith('//')) return null
  part = part.replace(/^https?:\/\//, '')
  part = part.split('/')[0]
  part = part.split(':')[0]
  part = part.replace(/^\.+/, '')
  return part || null
}

function validateDomain (domain) {
  if (!/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/.test(domain)) {
    return `Неверный домен: ${domain}`
  }
  return null
}

function normalizeSubnet (raw) {
  let part = String(raw || '').trim()
  if (!part || part.startsWith('//')) return null
  if (!part.includes('/')) {
    if (/^\d{1,3}(?:\.\d{1,3}){3}$/.test(part)) {
      part = `${part}/32`
    }
  }
  return part || null
}

function validateSubnet (cidr) {
  const [host, mask] = cidr.split('/')
  if (!host || mask === undefined || !/^\d+$/.test(mask)) {
    return `Неверная подсеть: ${cidr}`
  }
  const maskInt = Number(mask)
  const isV4 = /^\d{1,3}(?:\.\d{1,3}){3}$/.test(host)
  if (!isV4) return `Неверный IP: ${host}`
  const octets = host.split('.').map(Number)
  if (octets.some(o => o > 255)) return `Неверный IP: ${host}`
  if (maskInt < 0 || maskInt > 32) return `Неверная маска: ${cidr}`
  return null
}

function formSnapshot (form) {
  return {
    resolver_enabled: !!form.resolver_enabled,
    resolver_reject_quic: !!form.resolver_reject_quic,
    connection_id: form.connection_id || null,
    resolver_dns: String(form.resolver_dns || '1.1.1.1').trim(),
    community_lists: [...(form.community_lists || [])].map(String).sort(),
    user_domains: [...(form.user_domains || [])].map(String).sort(),
    user_subnets: [...(form.user_subnets || [])].map(String).sort()
  }
}

function syncForm (cfg) {
  forms[cfg.id] = {
    resolver_enabled: !!cfg.resolver_enabled,
    resolver_reject_quic: !!cfg.resolver_reject_quic,
    connection_id: cfg.connection_id || null,
    resolver_dns: cfg.resolver_dns || '1.1.1.1',
    community_lists: [...(cfg.community_lists || [])],
    user_domains: [...(cfg.user_domains || [])],
    user_subnets: [...(cfg.user_subnets || [])]
  }
  baselines[cfg.id] = formSnapshot(forms[cfg.id])
}

function isDirty (configId) {
  const form = forms[configId]
  const base = baselines[configId]
  if (!form || !base) return false
  return JSON.stringify(formSnapshot(form)) !== JSON.stringify(base)
}

function toggleExpand (props) {
  props.expand = !props.expand
  const id = props.row.id
  if (props.expand) {
    if (!expandedIds.value.includes(id)) expandedIds.value.push(id)
    if (!forms[id]) syncForm(props.row)
  } else {
    expandedIds.value = expandedIds.value.filter(x => x !== id)
  }
}

function isListDisabled (configId, item) {
  if (!item.exclusive_group) return false
  const selected = forms[configId]?.community_lists || []
  const exclusive = selectableLists.value
    .filter(l => l.exclusive_group === item.exclusive_group)
    .map(l => l.tag)
  const hit = selected.find(t => exclusive.includes(t))
  return !!(hit && hit !== item.tag)
}

function onListsChange (configId, item) {
  if (!item.exclusive_group) return
  const form = forms[configId]
  if (!form) return
  const exclusive = selectableLists.value
    .filter(l => l.exclusive_group === item.exclusive_group)
    .map(l => l.tag)
  const selectedExclusive = form.community_lists.filter(t => exclusive.includes(t))
  if (selectedExclusive.length > 1) {
    form.community_lists = form.community_lists.filter(
      t => !exclusive.includes(t) || t === item.tag
    )
  }
}

function previewAllowed (configId) {
  const form = forms[configId]
  const cfg = serverConfigs.value.find(c => c.id === configId)
  if (!form || !cfg) return ''
  if (!form.resolver_enabled) return cfg.client_allowed_ips_preview || ''
  return '0.0.0.0/0, ::/0'
}

function connectionHint (configId) {
  if (!connectionOptions.value.length) return 'Сначала создайте подключение'
  const form = forms[configId]
  if (form?.resolver_enabled) return 'Обязательно при включённом резолвере'
  return 'Необязательно, пока резолвер выключен'
}

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/api/resolver')
    Object.assign(status, data)
    for (const cfg of data.configs || []) {
      syncForm(cfg)
    }
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || 'Не удалось загрузить резолвер' })
  } finally {
    loading.value = false
  }
}

async function save (id) {
  const form = forms[id]
  if (!form) return
  if (form.resolver_enabled && !form.connection_id) {
    $q.notify({ type: 'negative', message: 'Выберите подключение (точку VPN)' })
    return
  }
  savingId.value = id
  try {
    const { data } = await withApplyProgress('resolver-save', () =>
      api.put(`/api/resolver/configs/${id}`, {
        resolver_enabled: form.resolver_enabled,
        resolver_reject_quic: form.resolver_reject_quic,
        connection_id: form.connection_id,
        resolver_dns: form.resolver_dns,
        community_lists: form.community_lists,
        user_domains: form.user_domains,
        user_subnets: form.user_subnets
      })
    )
    Object.assign(status, data.status)
    for (const cfg of data.status?.configs || []) {
      syncForm(cfg)
    }
    if (data.warning) {
      $q.notify({ type: 'warning', message: data.warning, timeout: 8000 })
    } else {
      $q.notify({ type: 'positive', message: 'Сохранено' })
    }
  } catch (e) {
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || 'Ошибка сохранения'
    $q.notify({ type: 'negative', message: msg })
  } finally {
    savingId.value = null
  }
}

onMounted(load)
</script>

<style scoped>
.page-wrap {
  max-width: 1100px;
  margin: 0 auto;
}

.resolver-header {
  display: grid;
  grid-template-columns: 1fr auto;
  grid-template-areas:
    'title actions'
    'desc actions';
  column-gap: 16px;
  row-gap: 4px;
  align-items: start;
}

.resolver-header__title {
  grid-area: title;
}

.resolver-header__actions {
  grid-area: actions;
  display: flex;
  align-items: center;
  gap: 8px;
}

.resolver-header__desc {
  grid-area: desc;
}

@media (max-width: 1023px) {
  .resolver-header {
    grid-template-columns: 1fr;
    grid-template-areas:
      'title'
      'actions'
      'desc';
    row-gap: 12px;
  }

  .resolver-header__actions {
    flex-direction: row;
    width: 100%;
  }

  .resolver-header__actions > .q-btn {
    flex: 1 1 0;
    min-width: 0;
  }

  .resolver-header__actions > .q-btn :deep(.q-btn__content) {
    flex-direction: row;
    flex-wrap: nowrap;
    white-space: nowrap;
  }
}

.status-card {
  background: var(--surface-panel);
  border-color: var(--surface-border) !important;
}
.expanded-cell {
  background: var(--surface-bg);
}
.expand-inner {
  border-left: 2px solid var(--surface-border);
  margin-left: 8px;
}
.mono {
  font-family: var(--theme-mono);
}
.client-checklist {
  padding-left: 1.25rem;
  line-height: 1.6;
}
</style>
