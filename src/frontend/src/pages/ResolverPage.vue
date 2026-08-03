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
            icon="speed"
            :label="t('nav.speedTest')"
            :to="{ name: 'resolver-speed-test' }"
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
            <q-badge
              :color="status.healthy ? 'positive' : 'negative'"
              class="q-pa-sm status-health-badge"
              :class="{ 'status-health-badge--error': !status.healthy }"
            >
              {{ status.healthy ? t('diagnostics.ok') : t('common.error') }}
              <q-tooltip
                v-if="!status.healthy"
                anchor="bottom middle"
                self="top middle"
                :offset="[0, 8]"
                class="resolver-error-tooltip"
                max-width="360px"
              >
                <div class="text-weight-medium q-mb-xs">{{ t('resolver.errorTooltipTitle') }}</div>
                <div class="q-mb-xs">{{ t('resolver.errorTooltipBody') }}</div>
                <div v-if="status.message" class="mono q-mb-xs">{{ status.message }}</div>
                <ul v-if="configErrors.length" class="q-my-none q-pl-md">
                  <li v-for="err in configErrors" :key="err.id">
                    <span class="text-weight-medium">{{ err.name }}:</span>
                    {{ err.error }}
                  </li>
                </ul>
              </q-tooltip>
            </q-badge>
          </div>
          <div class="col">
            <div class="text-subtitle2">
              {{ status.enabled ? t('resolver.statusActive') : t('resolver.statusDisabled') }}
              <span class="text-grey-5"> · sing-box: {{ status.singbox_running ? t('resolver.singboxRunning') : t('resolver.singboxStopped') }}</span>
            </div>
            <div
              class="text-caption"
              :class="status.healthy ? 'text-grey-5' : 'text-negative cursor-pointer'"
            >
              {{ status.message }}
              <q-tooltip
                v-if="!status.healthy"
                anchor="bottom start"
                self="top start"
                :offset="[0, 6]"
                class="resolver-error-tooltip"
                max-width="360px"
              >
                <div class="text-weight-medium q-mb-xs">{{ t('resolver.errorTooltipTitle') }}</div>
                <div class="q-mb-xs">{{ t('resolver.errorTooltipBody') }}</div>
                <div v-if="status.message" class="mono q-mb-xs">{{ status.message }}</div>
                <ul v-if="configErrors.length" class="q-my-none q-pl-md">
                  <li v-for="err in configErrors" :key="err.id">
                    <span class="text-weight-medium">{{ err.name }}:</span>
                    {{ err.error }}
                  </li>
                </ul>
              </q-tooltip>
            </div>
          </div>
          <div class="col-auto text-caption text-grey-5 mono">
            FakeIP {{ status.fakeip_cidr || '198.18.0.0/15' }}
          </div>
        </div>
      </q-card>

      <div class="row justify-end q-mb-sm">
        <q-btn
          color="primary"
          unelevated
          :label="t('resolver.selectConfig')"
          :disable="!pickerConfigOptions.length || enabling"
          @click="openConfigPicker"
        />
      </div>

      <q-table
        v-model:expanded="expandedIds"
        :rows="visibleConfigs"
        :columns="columns"
        row-key="id"

        flat
        :loading="loading"
        class="bg-transparent"
        :rows-per-page-options="[10, 25, 0]"
        :no-data-label="tableEmptyLabel"
      >
        <template #body="props">
          <q-tr :props="props">
            <q-td auto-width>
              <q-btn
                flat
                dense
                round
                :icon="expandBtnIcon(props)"
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

          <q-tr
            v-show="!$q.screen.lt.md && props.expand"
            :props="props"
            :key="`e_${props.row.id}`"
            class="q-virtual-scroll--with-prev"
          >
            <q-td colspan="100%" class="expanded-cell">
              <div class="q-pa-md">
                <ResolverConfigExpandPanel
                  :config="props.row"
                  :form="forms[props.row.id]"
                  :connection-options="connectionOptions"
                  :selectable-lists="selectableLists"
                  :saving="savingId === props.row.id"
                  :dirty="isDirty(props.row.id)"
                  :preview-allowed="previewAllowed(props.row.id)"
                  :is-list-disabled="isListDisabled"
                  :on-lists-change="onListsChange"
                  :normalize-domain="normalizeDomain"
                  :validate-domain="validateDomain"
                  :normalize-subnet="normalizeSubnet"
                  :validate-subnet="validateSubnet"
                  :format-ts="formatTs"
                  @save="save"
                />
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
                  <li>{{ t('resolver.checkPrivateDns') }}</li>
                  <li>{{ t('resolver.check2ip') }}</li>
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
                <div>{{ t('resolver.modeVdsSplitHint') }}</div>
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

      <q-dialog v-model="expandModalOpen" v-bind="mobileDialog" @hide="onExpandModalHide">
        <q-card class="surface-panel dialog-card column no-wrap" style="width: min(720px, 95vw); max-width: 95vw;">
          <DialogHeader
            :title="expandModalRow ? t('resolver.settingsFor', { name: expandModalRow.name }) : ''"
            always-show-close
          />
          <q-card-section v-if="expandModalRow" class="col dialog-scroll-body">
            <ResolverConfigExpandPanel
              :config="expandModalRow"
              :form="forms[expandModalRow.id]"
              :connection-options="connectionOptions"
              :selectable-lists="selectableLists"
              :saving="savingId === expandModalRow.id"
              :dirty="isDirty(expandModalRow.id)"
              :preview-allowed="previewAllowed(expandModalRow.id)"
              :show-title="false"
              :is-list-disabled="isListDisabled"
              :on-lists-change="onListsChange"
              :normalize-domain="normalizeDomain"
              :validate-domain="validateDomain"
              :normalize-subnet="normalizeSubnet"
              :validate-subnet="validateSubnet"
              :format-ts="formatTs"
              @save="save"
            />
          </q-card-section>
        </q-card>
      </q-dialog>

      <q-dialog v-model="pickerOpen" v-bind="mobileDialog" persistent>
        <q-card style="width: min(420px, 95vw); max-width: 95vw;" class="surface-panel dialog-card column no-wrap">
          <DialogHeader :title="t('resolver.selectConfigTitle')" />
          <q-card-section class="col dialog-scroll-body">
            <q-select
              v-model="pickerConfigId"
              :options="pickerConfigOptions"
              option-value="id"
              option-label="name"
              emit-value
              map-options
              :label="t('resolver.selectConfigLabel')"
              filled
              class="q-mb-md"
              :disable="!pickerConfigOptions.length"
            >
              <template #no-option>
                <q-item>
                  <q-item-section class="text-grey-5">
                    {{ t('resolver.noConfigsToPick') }}
                  </q-item-section>
                </q-item>
              </template>
            </q-select>
            <q-select
              v-model="pickerConnectionId"
              :options="connectionOptions"
              :label="t('resolver.connectionRequired')"
              emit-value
              map-options
              filled
              :disable="!connectionOptions.length"
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
          </q-card-section>
          <q-card-actions align="right">
            <q-btn flat :label="t('common.cancel')" v-close-popup :disable="enabling" />
            <q-btn
              color="primary"
              :label="t('resolver.pickConfig')"
              :loading="enabling"
              :disable="!pickerConfigId || !pickerConnectionId"
              @click="confirmConfigPick"
            />
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
import DialogHeader from '@/components/DialogHeader.vue'
import ResolverConfigExpandPanel from '@/components/ResolverConfigExpandPanel.vue'
import { useApplyProgress } from '@/composables/useApplyProgress'
import { useMobileDialog } from '@/composables/useMobileDialog'
import { useResolverListsBootstrap } from '@/composables/useResolverListsBootstrap'
import { bcp47Locale } from '@/i18n'

const { t, locale } = useI18n()
const $q = useQuasar()
const mobileDialog = useMobileDialog()
const { withApplyProgress } = useApplyProgress()
const { ensureListsReady } = useResolverListsBootstrap()
const loading = ref(true)
const savingId = ref(null)
const enabling = ref(false)
const expandedIds = ref([])
const expandModalOpen = ref(false)
const expandModalId = ref(null)
const pickerOpen = ref(false)
const pickerConfigId = ref(null)
const pickerConnectionId = ref(null)
const status = reactive({
  enabled: false,
  healthy: true,
  singbox_running: false,
  message: '',
  fakeip_cidr: '198.18.0.0/15',
  updated_at: null,
  needs_initial_sync: false,
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
const visibleConfigs = computed(() => serverConfigs.value.filter(c => c.resolver_enabled))
const expandModalRow = computed(() =>
  expandModalId.value == null
    ? null
    : (visibleConfigs.value.find(c => c.id === expandModalId.value) || null)
)
const pickerConfigOptions = computed(() => serverConfigs.value.filter(c => !c.resolver_enabled))
const vnConfigs = computed(() => (status.configs || []).filter(c => c.type === 'virtual_network'))
const tableEmptyLabel = computed(() =>
  serverConfigs.value.length
    ? t('resolver.noConfigsSelected')
    : t('resolver.noServerConfigs')
)
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

const configErrors = computed(() =>
  (status.configs || [])
    .filter(c => c.resolver_enabled && c.resolver_last_error)
    .map(c => ({
      id: c.id,
      name: c.name,
      error: c.resolver_last_error
    }))
)

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

function expandBtnIcon (props) {
  if ($q.screen.lt.md) {
    return expandModalOpen.value && expandModalId.value === props.row.id ? 'close' : 'open_in_full'
  }
  return props.expand ? 'expand_less' : 'expand_more'
}

function openExpandModal (row) {
  const id = row.id
  if (!forms[id]) syncForm(row)
  expandModalId.value = id
  expandModalOpen.value = true
  if (!expandedIds.value.includes(id)) expandedIds.value.push(id)
}

function toggleExpand (props) {
  const id = props.row.id
  if ($q.screen.lt.md) {
    openExpandModal(props.row)
    return
  }

  const next = !props.expand
  if (next && !forms[id]) syncForm(props.row)
  // Let v-model:expanded own expandedIds — do not push/filter here
  props.expand = next
}

function onExpandModalHide () {
  if (expandModalId.value != null) {
    expandedIds.value = expandedIds.value.filter(x => x !== expandModalId.value)
    expandModalId.value = null
  }
}

function openConfigPicker () {
  pickerConfigId.value = pickerConfigOptions.value.length === 1
    ? pickerConfigOptions.value[0].id
    : null
  pickerConnectionId.value = connectionOptions.value.length === 1
    ? connectionOptions.value[0].value
    : null
  pickerOpen.value = true
}

async function confirmConfigPick () {
  const id = pickerConfigId.value
  const connectionId = pickerConnectionId.value
  if (!id || !connectionId) return

  const cfg = serverConfigs.value.find(c => c.id === id)
  if (!cfg) return
  if (!forms[id]) syncForm(cfg)

  const form = forms[id]
  form.resolver_enabled = true
  form.connection_id = connectionId

  enabling.value = true
  savingId.value = id
  try {
    const { data } = await withApplyProgress('resolver-save', () =>
      api.put(`/api/resolver/configs/${id}`, {
        resolver_enabled: true,
        resolver_reject_quic: form.resolver_reject_quic,
        connection_id: connectionId,
        resolver_dns: form.resolver_dns,
        community_lists: form.community_lists,
        user_domains: form.user_domains,
        user_subnets: form.user_subnets
      })
    )
    Object.assign(status, data.status)
    for (const row of data.status?.configs || []) {
      syncForm(row)
    }
    pickerOpen.value = false
    pickerConfigId.value = null
    pickerConnectionId.value = null
    if ($q.screen.lt.md) {
      openExpandModal(cfg)
    } else if (!expandedIds.value.includes(id)) {
      expandedIds.value.push(id)
    }
    if (data.apply_error) {
      $q.notify({ type: 'negative', message: data.apply_error, timeout: 12000 })
    } else if (data.warning) {
      $q.notify({ type: 'warning', message: data.warning, timeout: 8000 })
    } else {
      $q.notify({ type: 'positive', message: t('common.saved') })
    }
  } catch (e) {
    form.resolver_enabled = false
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || t('common.saveError')
    $q.notify({ type: 'negative', message: msg })
  } finally {
    enabling.value = false
    savingId.value = null
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

async function load () {
  loading.value = true
  try {
    const { data } = await api.get('/api/resolver')
    Object.assign(status, data)
    for (const cfg of data.configs || []) {
      syncForm(cfg)
    }
    if (data.needs_initial_sync) {
      const synced = await ensureListsReady()
      status.needs_initial_sync = !!synced?.needs_initial_sync
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
    // Drop expand state for configs that left the table after disable
    const visibleIds = new Set(
      (data.status?.configs || []).filter(c => c.type === 'server' && c.resolver_enabled).map(c => c.id)
    )
    expandedIds.value = expandedIds.value.filter(id => visibleIds.has(id))
    if (expandModalId.value != null && !visibleIds.has(expandModalId.value)) {
      expandModalOpen.value = false
      expandModalId.value = null
    }
    if (data.apply_error) {
      $q.notify({ type: 'negative', message: data.apply_error, timeout: 12000 })
    } else if (data.warning) {
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

.status-health-badge--error {
  cursor: help;
}

.text-negative.cursor-pointer {
  cursor: help;
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
