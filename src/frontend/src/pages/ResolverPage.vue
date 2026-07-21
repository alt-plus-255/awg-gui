<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="resolver-header q-mb-md">
        <div class="text-h5 resolver-header__title">{{ t('resolver.title') }}</div>
        <div class="resolver-header__actions">
          <q-btn
            flat
            dense
            no-wrap
            color="primary"
            icon="cable"
            :label="t('resolver.connectionsLink')"
            :to="{ name: 'resolver-connections' }"
          />
          <q-btn
            flat
            dense
            no-wrap
            color="primary"
            icon="tune"
            :label="t('resolver.listSettingsLink')"
            :to="{ name: 'resolver-settings' }"
          />
        </div>
      </div>

      <q-card class="q-pa-md q-mb-md status-card" flat bordered>
        <div class="row items-center q-col-gutter-md">
          <div class="col-auto">
            <q-badge :color="status.healthy ? 'positive' : 'negative'" class="q-pa-sm">
              {{ status.healthy ? t('diagnostics.ok') : t('common.error') }}
            </q-badge>
          </div>
          <div class="col">
            <div class="text-subtitle2">
              {{ status.enabled ? t('resolver.statusActive') : t('resolver.statusDisabled') }}
              <span class="text-grey-5"> · sing-box: {{ status.singbox_running ? t('resolver.singboxRunning') : t('resolver.singboxStopped') }}</span>
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
        :no-data-label="t('resolver.noServerConfigs')"
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
                {{ props.row.resolver_enabled ? t('common.on') : t('common.off') }}
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
                  <div class="col text-subtitle1">{{ t('resolver.settingsFor', { name: props.row.name }) }}</div>
                  <q-toggle
                    v-model="forms[props.row.id].resolver_enabled"
                    color="primary"
                    :label="forms[props.row.id].resolver_enabled ? t('resolver.resolverEnabled') : t('resolver.resolverDisabled')"
                    :disable="savingId === props.row.id || !props.row.enabled"
                  />
                </div>

                <q-banner
                  v-if="!props.row.enabled"
                  dense
                  rounded
                  class="q-mb-md text-warning surface-warn-bg"
                >
                  {{ t('resolver.configDisabledBanner') }}
                </q-banner>

                <q-select
                  v-model="forms[props.row.id].connection_id"
                  :options="connectionOptions"
                  :label="forms[props.row.id].resolver_enabled ? t('resolver.connectionRequired') : t('resolver.connectionOptional')"
                  emit-value
                  map-options
                  filled

                  :clearable="!forms[props.row.id].resolver_enabled"
                  class="q-mb-md"
                  :disable="!connectionOptions.length"
                  :rules="forms[props.row.id].resolver_enabled
                    ? [v => !!v || t('resolver.selectConnection')]
                    : []"
                  lazy-rules
                >
                  <template #no-option>
                    <q-item>
                      <q-item-section class="text-grey-5">
                        {{ t('resolver.noConnections') }}
                        <router-link :to="{ name: 'resolver-connections' }" class="text-primary">{{ t('resolver.create') }}</router-link>
                      </q-item-section>
                    </q-item>
                  </template>
                </q-select>

                <q-option-group
                  v-model="forms[props.row.id].resolver_routing_mode"
                  :options="routingModeOptions"
                  color="primary"
                  type="radio"
                  class="q-mb-sm"
                  :disable="!forms[props.row.id].resolver_enabled"
                />

                <q-input
                  v-model="forms[props.row.id].resolver_dns"
                  label="DNS (sing-box / upstream)"
                  filled

                  dense
                  class="q-mb-md"
                  placeholder="1.1.1.1"
                />

                <q-checkbox
                  v-model="forms[props.row.id].resolver_reject_quic"
                  :label="t('resolver.blockQuic')"
                  dense

                  class="q-mb-md"
                />

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
                      :label="t('resolver.customDomains')"
                      placeholder="example.com"
                      :empty-hint="t('resolver.noDomains')"
                      :normalize="normalizeDomain"
                      :validate="validateDomain"
                    />
                  </div>
                  <div class="col-12 col-md-6">
                    <TagListInput
                      v-model="forms[props.row.id].user_subnets"
                      :label="t('resolver.customSubnets')"
                      :placeholder="t('resolver.subnetsPlaceholder')"
                      :empty-hint="t('resolver.noSubnets')"
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
                    :label="t('common.save')"
                    :loading="savingId === props.row.id"
                    :disable="!isDirty(props.row.id)"
                    @click="save(props.row.id)"
                  />
                  <div v-if="props.row.resolver_updated_at" class="text-caption text-grey-6">
                    {{ t('resolver.appliedAt', { ts: formatTs(props.row.resolver_updated_at) }) }}
                  </div>
                </div>
              </div>
            </q-td>
          </q-tr>
        </template>
      </q-table>

      <div class="q-mt-lg faq-block surface-panel-alt surface-border rounded-borders">
        <div class="text-subtitle1 q-pa-md q-pb-sm">{{ t('resolver.faqTitle') }}</div>
        <q-list bordered separator class="rounded-borders">
          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="help_outline"
            :label="t('resolver.faqSectionOverview')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                {{ t('resolver.headerDesc') }}
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="sync"
            :label="t('resolver.faqSectionAfterEnable')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                <div class="q-mb-sm">
                  <strong>{{ t('resolver.afterEnableTitle') }}</strong> {{ t('resolver.afterEnableStep1') }}
                </div>
                <div>{{ t('resolver.afterEnableStep2') }}</div>
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="phone_iphone"
            :label="t('resolver.faqSectionPhoneCheck')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                <ul class="q-my-none client-checklist">
                  <li>{{ t('resolver.checkDeletedReimported') }}</li>
                  <li>
                    <span class="mono">DNS</span> = gateway
                    <span v-if="firstEnabledGateway" class="text-grey-5">({{ firstEnabledGateway }})</span>
                  </li>
                  <li>{{ t('resolver.checkAllowedIps') }}</li>
                  <li>{{ t('resolver.checkAllowedIpsSplit') }}</li>
                  <li>{{ t('resolver.checkPrivateDns') }}</li>
                  <li>{{ t('resolver.check2ip') }}</li>
                  <li>{{ t('resolver.check2ipSplit') }}</li>
                </ul>
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="alt_route"
            :label="t('resolver.faqSectionRouting')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                <div class="q-mb-sm">{{ t('resolver.modeVdsSplitHint') }}</div>
                <div class="q-mb-sm">{{ t('resolver.modeClientSplitHint') }}</div>
                <div>{{ t('resolver.modeClientSplitCidrWarning') }}</div>
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="public"
            :label="t('resolver.faqSection2ip')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                {{ t('resolver.phoneHint2ip') }}
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="smartphone"
            :label="t('resolver.faqSectionMobile')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                <div class="q-mb-sm">{{ t('resolver.phoneHintRelay') }}</div>
                <div>{{ t('resolver.checkPrivateDns') }}</div>
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="cable"
            :label="t('resolver.faqSectionConnection')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                <ul class="q-my-none client-checklist">
                  <li>{{ t('resolver.requiredWhenEnabled') }}</li>
                  <li>{{ t('resolver.optionalWhenDisabled') }}</li>
                  <li>{{ t('resolver.createConnectionFirst') }}</li>
                </ul>
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="dns"
            :label="t('resolver.faqSectionDns')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                {{ t('resolver.fakeIpHint') }}
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="block"
            :label="t('resolver.faqSectionQuic')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                {{ t('resolver.blockQuicHint') }}
              </q-card-section>
            </q-card>
          </q-expansion-item>

          <q-expansion-item
            group="resolver-faq"
            expand-separator
            icon="list"
            :label="t('resolver.faqSectionLists')"
            header-class="text-body2 faq-expansion-header"
          >
            <q-card flat class="faq-section-body">
              <q-card-section class="text-body2 text-grey-4">
                <div class="q-mb-sm">{{ t('resolver.communityListsHint') }}</div>
                <div class="q-mb-sm">{{ t('resolver.listsCommunityCustom') }}</div>
                <div class="q-mb-sm">{{ t('resolver.domainsHint') }}</div>
                <div>{{ t('resolver.subnetsHint') }}</div>
              </q-card-section>
            </q-card>
          </q-expansion-item>
        </q-list>
      </div>

      <div v-if="vnConfigs.length" class="text-caption text-grey-6 q-mt-md">
        {{ t('resolver.virtualNetworksHidden') }}
        {{ vnConfigs.map(c => c.name).join(', ') }}.
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import TagListInput from '@/components/TagListInput.vue'
import { useApplyProgress } from '@/composables/useApplyProgress'
import { bcp47Locale } from '@/i18n'

const { t, locale } = useI18n()
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

const columns = computed(() => [
  { name: 'expand', label: '', field: 'expand', align: 'left' },
  { name: 'name', label: t('resolver.colConfig'), field: 'name', align: 'left' },
  { name: 'iface', label: t('resolver.colInterface'), field: 'iface', align: 'left' },
  { name: 'resolver', label: t('resolver.title'), field: 'resolver_enabled', align: 'left' },
  { name: 'connection', label: t('resolver.colConnection'), field: 'connection_name', align: 'left' }
])

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

const routingModeOptions = computed(() => [
  { label: t('resolver.modeVdsSplit'), value: 'vds_split' },
  { label: t('resolver.modeClientSplit'), value: 'client_split' }
])

function formatTs (iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(bcp47Locale(locale.value))
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
    return t('resolver.invalidDomain', { domain })
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
    return t('resolver.invalidSubnet', { cidr })
  }
  const maskInt = Number(mask)
  const isV4 = /^\d{1,3}(?:\.\d{1,3}){3}$/.test(host)
  if (!isV4) return t('resolver.invalidIp', { host })
  const octets = host.split('.').map(Number)
  if (octets.some(o => o > 255)) return t('resolver.invalidIp', { host })
  if (maskInt < 0 || maskInt > 32) return t('resolver.invalidMask', { cidr })
  return null
}

function formSnapshot (form) {
  return {
    resolver_enabled: !!form.resolver_enabled,
    resolver_routing_mode: form.resolver_routing_mode === 'client_split' ? 'client_split' : 'vds_split',
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
    resolver_routing_mode: cfg.resolver_routing_mode === 'client_split' ? 'client_split' : 'vds_split',
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
  if (form.resolver_routing_mode === 'client_split') {
    const parts = ['198.18.0.0/15']
    if (cfg.gateway_ip) parts.push(`${cfg.gateway_ip}/32`)
    for (const cidr of form.user_subnets || []) {
      const c = String(cidr || '').trim()
      if (c) parts.push(c.includes('/') ? c : `${c}/32`)
    }
    return [...new Set(parts)].join(', ')
  }
  return '0.0.0.0/0, ::/0'
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
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('resolver.loadError') })
  } finally {
    loading.value = false
  }
}

async function save (id) {
  const form = forms[id]
  if (!form) return
  if (form.resolver_enabled && !form.connection_id) {
    $q.notify({ type: 'negative', message: t('resolver.selectConnectionPoint') })
    return
  }
  savingId.value = id
  try {
    const { data } = await withApplyProgress('resolver-save', () =>
      api.put(`/api/resolver/configs/${id}`, {
        resolver_enabled: form.resolver_enabled,
        resolver_routing_mode: form.resolver_routing_mode === 'client_split' ? 'client_split' : 'vds_split',
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
      $q.notify({ type: 'positive', message: t('common.saved') })
    }
  } catch (e) {
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || t('common.saveError')
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
    'title actions';
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

@media (max-width: 1023px) {
  .resolver-header {
    grid-template-columns: 1fr;
    grid-template-areas:
      'title'
      'actions';
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
  /* q-table defaults to --no-wrap; allow form/banner text to wrap */
  white-space: normal;
}
.expand-inner {
  border-left: 2px solid var(--surface-border);
  margin-left: 8px;
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
}
.expand-inner :deep(.q-banner),
.expand-inner :deep(.q-banner__content) {
  min-width: 0;
  max-width: 100%;
  white-space: normal;
  overflow-wrap: anywhere;
}
.mono {
  font-family: var(--theme-mono);
}
.client-checklist {
  padding-left: 1.25rem;
  line-height: 1.6;
}
.faq-block {
  overflow: hidden;
}
.faq-section-body {
  background: transparent;
}
.faq-expansion-header {
  white-space: normal;
}
:deep(.faq-block .q-item__label) {
  white-space: normal;
}
</style>
