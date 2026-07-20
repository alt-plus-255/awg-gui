<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-start q-mb-md">
        <div class="col">
          <div class="row items-center no-wrap">
            <div class="col">
              <div class="text-h5">Подключения</div>
            </div>
            <div class="col-auto lt-md">
              <q-btn flat dense icon="refresh" label="Обновить" :loading="loading" @click="load" />
            </div>
          </div>
          <div class="text-body2 text-grey-5 q-mt-xs">
            Точки выхода sing-box (VPN/прокси) для режима резолвера.
          </div>
        </div>
        <div class="col-auto gt-sm row items-center no-wrap">
          <q-btn flat icon="refresh" label="Обновить" class="q-mr-sm" :loading="loading" @click="load" />
          <q-btn color="primary" icon="add" label="Добавить" @click="openCreate" />
        </div>
      </div>
      <div class="lt-md q-mb-md">
        <q-btn color="primary" icon="add" label="Добавить" class="full-width" @click="openCreate" />
      </div>

      <q-banner
        v-if="pingErrorMessage"
        dense
        rounded
        class="bg-negative text-white q-mb-md"
      >
        <div class="text-body2">
          {{ pingErrorMessage }}
        </div>
        <template #action>
          <q-btn flat dense icon="close" @click="pingErrorMessage = null" />
        </template>
      </q-banner>

      <q-banner v-if="autoPingSession.active" dense rounded class="auto-ping-banner q-mb-md">
        <template #avatar>
          <q-spinner color="primary" size="20px" />
        </template>
        <span class="text-body2">
          Автопроверка пинга:
          <strong>{{ Math.min(autoPingSession.done + 1, autoPingSession.total) }} / {{ autoPingSession.total }}</strong>
          <span v-if="autoPingSession.currentName" class="text-grey-5"> — {{ autoPingSession.currentName }}</span>
        </span>
        <template #action>
          <q-btn
            flat
            dense
            color="orange"
            icon="stop"
            label="Остановить"
            :loading="pingProbeRestarting"
            @click="stopAndRestartPingProbe"
          />
        </template>
      </q-banner>

      <q-table
        :rows="connections"
        :columns="columns"
        row-key="id"

        flat
        :loading="loading"
        class="bg-transparent"
        :rows-per-page-options="[10, 25, 0]"
        no-data-label="Нет подключений — добавьте первое"
      >
        <template #body="props">
          <q-tr :props="props">
            <q-td auto-width>
              <q-btn
                v-if="props.row.kind === 'subscription'"
                flat
                dense
                round
                :icon="props.expand ? 'expand_less' : 'expand_more'"
                @click="toggleExpand(props)"
              />
            </q-td>
            <q-td key="name" :props="props">
              <div class="name-cell">
                <div class="text-body2">{{ props.row.name }}</div>
                <div class="traffic mono q-mt-xs">
                  <span class="rx">↓ {{ formatBytes(props.row.rx) }}</span>
                  <span class="tx">↑ {{ formatBytes(props.row.tx) }}</span>
                </div>
              </div>
            </q-td>
            <q-td key="config_type" :props="props">
              <div class="config-type-cell">
                <q-badge :color="configTypeBadgeColor(props.row)" dense>
                  {{ props.row.kind === 'subscription' ? 'подписка' : (props.row.config_type === 'url' ? 'прокси' : 'JSON') }}
                </q-badge>
                <span v-if="props.row.kind === 'subscription'" class="text-caption text-grey-5">
                  {{ subscriptionModeLabel(props.row) }}
                </span>
                <span v-else-if="props.row.outbound_type" class="text-caption text-grey-5 mono">
                  {{ props.row.outbound_type }}
                </span>
              </div>
            </q-td>
            <q-td key="active_node" :props="props">
              <template v-if="props.row.kind === 'subscription'">
                <div v-if="props.row.subscription_pick_name" class="row items-center no-wrap q-gutter-xs">
                  <q-badge
                    :color="activeNodeBadgeColor(props.row)"
                    :outline="props.row.subscription_pick_source === 'user'"
                    dense
                  >
                    {{ activeNodeBadge(props.row) }}
                  </q-badge>
                  <span class="text-body2">{{ props.row.subscription_pick_name }}</span>
                  <span v-if="props.row.subscription_pick_latency_ms != null" class="text-caption text-grey-5 mono">
                    · {{ props.row.subscription_pick_latency_ms }} ms
                  </span>
                </div>
                <span v-else class="text-caption text-grey-6">
                  {{ props.row.subscription_mode === 'single' ? 'локация не выбрана' : 'ожидание выбора системы' }}
                </span>
              </template>
              <span v-else class="text-caption text-grey-6">—</span>
            </q-td>
            <q-td key="online" :props="props">
              <div class="online-cell">
                <div v-if="pingStatus[props.row.id]?.active" class="row items-center q-gutter-xs no-wrap q-mb-xs">
                  <q-spinner size="14px" color="primary" />
                  <span class="text-caption text-primary">
                    {{ pingStatus[props.row.id].phase || 'пинг…' }}
                    <template v-if="pingStatus[props.row.id].total > 1">
                      · {{ pingStatus[props.row.id].tested }}/{{ pingStatus[props.row.id].total }}
                    </template>
                  </span>
                </div>
                <div class="row items-center q-gutter-xs no-wrap">
                  <q-badge :color="onlineColor(props.row)">
                    {{ onlineLabel(props.row) }}
                  </q-badge>
                  <span v-if="props.row.latency_ms != null" class="text-caption text-grey-5 mono">
                    {{ props.row.latency_ms }} ms
                  </span>
                  <q-badge v-if="props.row.tspu?.likely" color="warning">ТСПУ</q-badge>
                </div>
                <div v-if="props.row.tspu?.chain?.length" class="tspu-chain q-mt-xs">
                  <template v-for="(step, i) in props.row.tspu.chain" :key="step.id">
                    <span class="tspu-step mono" :class="stepClass(step, props.row.tspu)" :title="step.note">
                      {{ stepMark(step) }}{{ step.label }}
                    </span>
                    <span v-if="i < props.row.tspu.chain.length - 1" class="tspu-arrow text-grey-7">→</span>
                  </template>
                </div>
                <div v-if="tspuEndpoint(props.row.tspu)" class="text-caption text-grey-5 mono q-mt-xs">
                  {{ tspuEndpoint(props.row.tspu) }}
                </div>
                <div
                  v-if="props.row.tspu?.detail && (props.row.tspu.likely || props.row.last_test_ok === false)"
                  class="text-caption q-mt-xs"
                  :class="props.row.tspu.likely ? 'text-warning' : 'text-grey-5'"
                >
                  {{ props.row.tspu.detail }}
                </div>
                <div v-if="formatLastChecked(lastCheckedAt(props.row))" class="text-caption text-grey-6 q-mt-xs">
                  Проверено: {{ formatLastChecked(lastCheckedAt(props.row)) }}
                </div>
              </div>
            </q-td>
            <q-td key="enabled" :props="props">
              <q-badge :color="props.row.enabled ? 'positive' : 'grey-8'">
                {{ props.row.enabled ? 'вкл' : 'выкл' }}
              </q-badge>
            </q-td>
            <q-td key="actions" :props="props">
              <q-btn
                flat
                dense
                icon="network_check"
                color="primary"
                title="Проверить соединение"
                :loading="testingId === props.row.id || pingStatus[props.row.id]?.active"
                :disable="!props.row.enabled || (isAnyPingActive && testingId !== props.row.id && !pingStatus[props.row.id]?.active)"
                @click="testConnection(props.row)"
              />
              <q-btn flat dense icon="edit" title="Редактировать" @click="openEdit(props.row)" />
              <q-btn flat dense color="negative" icon="delete" title="Удалить" @click="remove(props.row)" />
            </q-td>
          </q-tr>

          <q-tr v-if="props.row.kind === 'subscription' && props.expand" :props="props" :key="`e_${props.row.id}`" class="q-virtual-scroll--with-prev">
            <q-td colspan="100%" class="expanded-cell">
              <div v-if="subscriptionState[props.row.id]" class="q-pa-md">
                <div class="row items-center q-mb-md q-gutter-sm">
                  <q-select
                    v-model="subscriptionState[props.row.id].pingIntervalMin"
                    :options="pingIntervalOptions"
                    label="Автопроверка пинга"
                    dense
                    outlined
                    emit-value
                    map-options
                    style="min-width: 200px"
                    :disable="isConnectionPinging(props.row.id)"
                    @update:model-value="() => savePingInterval(props.row)"
                  />
                  <q-badge
                    v-if="isConnectionPinging(props.row.id)"
                    color="info"
                    outline
                  >
                    проверка…
                  </q-badge>
                  <q-space />
                  <q-btn flat dense icon="cloud_download" label="Обновить подписку"
                    :loading="subscriptionState[props.row.id]?.refreshing"
                    @click="refreshSubscription(props.row)" />
                  <q-btn color="primary" dense icon="save" label="Применить"
                    :loading="subscriptionState[props.row.id]?.saving"
                    :disable="!subscriptionState[props.row.id]?.dirty"
                    @click="applySubscription(props.row)" />
                </div>

                <div v-if="(subscriptionState[props.row.id]?.nodes || []).length <= 6" class="text-caption text-warning q-mb-sm">
                  Мало узлов — отредактируйте подключение и вставьте содержимое подписки из клиента, если провайдер отдаёт неполный список по URL.
                </div>

                <SubscriptionNodesTable
                  :rows="subscriptionState[props.row.id]?.nodes || []"
                  :mode="subscriptionState[props.row.id]?.mode"
                  :mode-options="modeOptions"
                  :selected="subscriptionState[props.row.id]?.selected"
                  :best-pick-key="activeNodeKeyForRow(props.row)"
                  :active-pick-source="props.row.subscription_pick_source"
                  :ping-loading="isConnectionPinging(props.row.id)"
                  :ping-truncated="subscriptionState[props.row.id]?.pingTruncated"
                  :ping-tested="subscriptionState[props.row.id]?.pingTested"
                  @update:mode="(v) => onExpandModeUpdate(props.row.id, v)"
                  @ping="(fast) => pingExpandNodes(props.row, fast)"
                  @ping-node="(node) => pingExpandNode(props.row, node)"
                  @select="(node) => selectExpandNode(props.row.id, node)"
                />
              </div>
            </q-td>
          </q-tr>
        </template>
      </q-table>
    </div>

    <q-dialog v-model="dialogOpen" v-bind="mobileDialog" persistent>
      <q-card style="width: min(720px, 95vw); max-width: 95vw;" class="surface-panel dialog-card column no-wrap">
        <q-card-section class="text-h6">
          {{ editingId ? 'Редактировать подключение' : 'Новое подключение' }}
        </q-card-section>
        <q-card-section class="col dialog-scroll-body">
          <q-input v-model="form.name" label="Название" filled class="q-mb-md" />
          <q-btn-toggle
            v-model="form.kind"
            spread
            toggle-color="primary"
            class="q-mb-md full-width"
            :options="[
              { label: 'Прокси', value: 'proxy' },
              { label: 'Подписка', value: 'subscription' }
            ]"
          />

          <template v-if="form.kind === 'subscription'">
            <q-input
              v-model="form.subscription_url"
              label="URL подписки"
              hint="v2rayN/Hiddify — base64, Clash YAML или список URI"
              type="textarea"
              autogrow
              filled

              class="q-mb-md mono"
            />
            <q-input
              v-model="form.subscription_body"
              label="Содержимое подписки (если с сервера мало узлов)"
              hint="Скопируйте из клиента (Clash/v2rayN) — объединится с URL"
              type="textarea"
              autogrow
              filled

              class="q-mb-md mono"
              rows="4"
            />
            <p v-if="parseError" class="text-caption text-negative q-mb-sm">{{ parseError }}</p>
            <p v-else-if="previewNodes.length > 0 && previewNodes.length <= 6" class="text-caption text-warning q-mb-sm">
              Загружено мало узлов — если в клиенте их больше, вставьте содержимое подписки из v2rayN/Hiddify в поле ниже URL.
            </p>

            <SubscriptionNodesTable
              :rows="previewNodes"
              v-model:mode="form.subscription_mode"
              :mode-options="modeOptions"
              :mode-disabled="!previewNodes.length"
              :selected="form.subscription_selected"
              :best-pick-key="dialogBestPickKey"
              :active-pick-source="dialogActivePickSource"
              :parse-loading="parseLoading"
              :ping-loading="pingLoading"
              :ping-disabled="!editingId"
              ping-disabled-hint="Сохраните подключение перед проверкой пинга"
              :ping-truncated="previewTruncated"
              :ping-tested="previewTested"
              empty-label="Вставьте URL подписки — узлы загрузятся автоматически"
              @ping="pingDialogNodes"
              @ping-node="pingDialogNode"
              @select="selectPreviewNode"
            />
          </template>

          <template v-else>
            <q-btn-toggle
              v-model="form.config_type"
              spread
              toggle-color="primary"
              class="q-mb-md full-width"
              :options="[
                { label: 'Ссылка (URL)', value: 'url' },
                { label: 'Outbound JSON', value: 'json' }
              ]"
            />
            <q-input
              v-if="form.config_type === 'url'"
              v-model="form.share_url"
              label="Ссылка подключения"
              hint="vless://, ss://, trojan://, hy2://, socks://"
              type="textarea"
              autogrow
              filled

              class="q-mb-md mono"
            />
            <q-input
              v-else
              v-model="form.outbound_json"
              label="Outbound JSON"
              hint='Объект без поля "tag" — тег назначит система'
              type="textarea"
              autogrow
              filled

              class="q-mb-md mono"
              input-style="font-family: var(--theme-mono); min-height: 160px;"
            />
          </template>
          <q-toggle v-model="form.enabled" label="Включено" color="positive" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" v-close-popup />
          <q-btn color="primary" label="Сохранить" :loading="saving"
            :disable="form.kind === 'subscription' && !canSaveSubscription" @click="save" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { QBadge, QBtn, QBtnDropdown, QBtnToggle, QItem, QItemSection, QList, QRadio, QSpinner, QTable, QTd, QTr } from 'quasar'
import { useQuasar } from 'quasar'
import api, { ensureCsrf } from '@/boot/axios'
import { useApplyProgress } from '@/composables/useApplyProgress'
import { useMobileDialog } from '@/composables/useMobileDialog'

const SubscriptionNodesTable = defineComponent({
  name: 'SubscriptionNodesTable',
  props: {
    rows: { type: Array, default: () => [] },
    mode: { type: String, default: 'urltest' },
    modeOptions: { type: Array, default: () => [] },
    modeDisabled: { type: Boolean, default: false },
    selected: { type: String, default: null },
    parseLoading: { type: Boolean, default: false },
    pingLoading: { type: Boolean, default: false },
    pingDisabled: { type: Boolean, default: false },
    pingDisabledHint: { type: String, default: '' },
    pingTruncated: { type: Boolean, default: false },
    pingTested: { type: Number, default: 0 },
    bestPickKey: { type: String, default: null },
    activePickSource: { type: String, default: null },
    emptyLabel: { type: String, default: 'Нет узлов' }
  },
  emits: ['ping', 'select', 'update:mode', 'ping-node'],
  setup (props, { emit }) {
    const columns = computed(() => {
      const base = [
        { name: 'name', label: 'Локация', field: 'name', align: 'left', sortable: true },
        { name: 'type', label: 'Тип', field: 'type', align: 'left' },
        { name: 'latency_ms', label: 'Пинг', field: 'latency_ms', align: 'right', sortable: true, style: 'width: 140px' }
      ]
      if (props.mode === 'single') {
        return [
          { name: 'select', label: '', field: 'select', align: 'center', style: 'width: 44px' },
          ...base
        ]
      }
      return base
    })

    const nodePingBlocked = computed(() => props.pingDisabled || props.pingLoading)

    const pingCell = (row) => {
      if (row.pinging) {
        return h('div', { class: 'row items-center justify-end' }, [
          h(QSpinner, { size: '16px', color: 'primary' })
        ])
      }
      const sourceLabel = (source, testedAt) => {
        if (testedAt) {
          const mins = Math.max(0, Math.round((Date.now() - new Date(testedAt).getTime()) / 60000))
          if (mins > 0) return `${mins} мин`
        }
        if (source === 'tcp') return 'TCP'
        return ''
      }

      const pingBtn = h(QBtn, {
        flat: true,
        dense: true,
        round: true,
        icon: 'network_check',
        size: 'sm',
        color: 'primary',
        title: props.pingDisabled
          ? props.pingDisabledHint
          : (props.pingLoading ? 'Идёт проверка всех узлов' : 'Проверить пинг'),
        disable: nodePingBlocked.value,
        onClick: (e) => {
          e.stopPropagation()
          if (nodePingBlocked.value) return
          emit('ping-node', row)
        }
      })
      if (row.latency_ok && row.latency_ms != null) {
        const srcLabel = sourceLabel(row.latency_source, row.latency_tested_at)
        const src = srcLabel ? h('span', { class: 'text-caption text-grey-6 q-ml-xs' }, srcLabel) : null
        return h('div', { class: 'row items-center no-wrap justify-end q-gutter-xs' }, [
          h(QBadge, { color: 'positive', class: 'mono' }, () => `${row.latency_ms} ms`),
          src,
          pingBtn
        ])
      }
      if (row.latency_ok && row.latency_source === 'tcp') {
        return h('div', { class: 'row items-center no-wrap justify-end q-gutter-xs' }, [
          h(QBadge, { color: 'positive', outline: true }, () => 'TCP'),
          pingBtn
        ])
      }
      return h('div', { class: 'row items-center no-wrap justify-end q-gutter-xs' }, [
        h('span', { class: 'text-caption mono', style: row.latency_error ? 'color:#f1948a' : 'color:#888' }, row.latency_error || '—'),
        pingBtn
      ])
    }

    return () => h(QTable, {
      rows: props.rows,
      columns: columns.value,
      rowKey: 'key',
      dense: true,
      flat: true,
      loading: props.parseLoading,
      class: 'preview-nodes',
      rowsPerPageOptions: [0],
      hideBottom: true,
      pagination: { rowsPerPage: 0 },
      noDataLabel: props.emptyLabel
    }, {
      'top-left': () => (props.modeOptions.length
        ? h(QBtnToggle, {
          modelValue: props.mode,
          'onUpdate:modelValue': (v) => emit('update:mode', v),
          dense: true,
          flat: true,
          noCaps: true,
          class: 'subscription-mode-toggle',
          disable: props.modeDisabled,
          options: props.modeOptions
        })
        : null),
      'top-right': () => h('div', { class: 'row items-center q-gutter-sm' }, [
        props.rows.length
          ? h('span', { class: 'text-caption text-grey-5' }, [
              `${props.rows.length} узл.`,
              props.pingTruncated ? ` · пинг ${props.pingTested}` : ''
            ])
          : null,
        h(QBtnDropdown, {
          outline: true,
          color: 'primary',
          icon: 'network_check',
          label: 'Пинг',
          dense: true,
          noCaps: true,
          loading: props.pingLoading,
          disable: !props.rows.length || props.pingDisabled || props.pingLoading,
          title: props.pingDisabled
            ? props.pingDisabledHint
            : (props.pingLoading ? 'Идёт проверка всех узлов' : 'Проверка пинга узлов')
        }, {
          default: () => h(QList, { dense: true, style: 'min-width: 160px' }, () => [
            h(QItem, {
              clickable: !props.pingLoading,
              disable: props.pingLoading,
              dense: true,
              onClick: () => {
                if (props.pingLoading) return
                emit('ping', true)
              }
            }, () => [
              h(QItemSection, null, () => 'Быстро'),
              h(QItemSection, { side: true, class: 'text-caption text-grey-5' }, () => 'TCP')
            ]),
            h(QItem, {
              clickable: !props.pingLoading,
              disable: props.pingLoading,
              dense: true,
              onClick: () => {
                if (props.pingLoading) return
                emit('ping', false)
              }
            }, () => [
              h(QItemSection, null, () => 'Полный'),
              h(QItemSection, { side: true, class: 'text-caption text-grey-5' }, () => 'ms')
            ])
          ])
        })
      ]),
      'body-cell-select': (scope) => h(QTd, scope, () => h(QRadio, {
        modelValue: props.selected,
        val: scope.row.key,
        dense: true,
        color: 'primary',
        class: 'subscription-node-radio',
        'onUpdate:modelValue': () => emit('select', scope.row)
      })),
      'body-cell-name': (scope) => {
        const isActive = props.bestPickKey === scope.row.key || scope.row.is_active || scope.row.is_best_pick
        const source = scope.row.active_source || props.activePickSource
        const badgeLabel = source === 'user'
          ? 'выбран'
          : (source === 'urltest' ? 'авто' : (source === 'cached' ? 'кэш' : 'активный'))
        const badgeTitle = source === 'user'
          ? 'Выбрано пользователем'
          : (source === 'urltest' ? 'Выбрано системой (urltest)' : (source === 'cached' ? 'По кэшу пинга' : 'Активный узел'))
        return h(QTd, scope, () => h('div', { class: 'row items-center no-wrap q-gutter-xs' }, [
          h('span', {
            class: [
              'preview-node-name',
              props.mode === 'single' && props.selected === scope.row.key ? 'selected' : '',
              isActive ? 'best-pick-name' : ''
            ],
            onClick: () => emit('select', scope.row)
          }, scope.row.name),
          isActive
            ? h(QBadge, {
              color: source === 'user' ? 'info' : (source === 'cached' ? 'grey-7' : 'positive'),
              outline: source === 'user',
              dense: true,
              title: badgeTitle
            }, () => badgeLabel)
            : null
        ]))
      },
      'body-cell-latency_ms': (scope) => h(QTd, scope, () => pingCell(scope.row))
    })
  }
})

const $q = useQuasar()
const mobileDialog = useMobileDialog()
const { withApplyProgress } = useApplyProgress()
const loading = ref(false)
const saving = ref(false)
const pingErrorMessage = ref(null)
let pingErrorTimer = null
const parseLoading = ref(false)
const pingLoading = ref(false)
const previewNodes = ref([])
const previewTruncated = ref(false)
const previewTested = ref(0)
const parseError = ref('')
const testingId = ref(null)
const dialogOpen = ref(false)
const editingId = ref(null)
const editOriginalSubscriptionUrl = ref('')
const connections = ref([])
const subscriptionState = reactive({})
const expandedIds = reactive(new Set())
let parseTimer = null
const pingTimers = new Map()
const autoPingRunning = ref(false)
const autoPingSession = reactive({
  active: false,
  done: 0,
  total: 0,
  currentName: ''
})
const pingStatus = reactive({})
const pingQueue = []
const pingQueueActive = ref(false)
const pingCancelRequested = ref(false)
const pingProbeRestarting = ref(false)
let pingAbortController = null

const pingIntervalOptions = [
  { label: 'Выкл', value: 0 },
  { label: '1 мин', value: 1 },
  { label: '3 мин', value: 3 },
  { label: '5 мин', value: 5 },
  { label: '10 мин', value: 10 },
  { label: '15 мин', value: 15 },
  { label: '30 мин', value: 30 },
  { label: '60 мин', value: 60 }
]

const modeOptions = [
  { label: 'Одна локация', value: 'single' },
  { label: 'Лучший пинг (urltest)', value: 'urltest' }
]

const columns = [
  { name: 'expand', label: '', field: 'expand', align: 'left' },
  { name: 'name', label: 'Название', field: 'name', align: 'left' },
  { name: 'config_type', label: 'Тип', field: 'config_type', align: 'left' },
  { name: 'active_node', label: 'Активный узел', field: 'active_node', align: 'left', style: 'min-width: 180px' },
  { name: 'online', label: 'Онлайн / путь', field: 'online', align: 'left', style: 'min-width: 280px' },
  { name: 'enabled', label: 'Статус', field: 'enabled', align: 'left' },
  { name: 'actions', label: '', field: 'actions', align: 'right' }
]

const form = reactive({
  name: '',
  kind: 'proxy',
  config_type: 'url',
  share_url: '',
  subscription_url: '',
  subscription_body: '',
  subscription_mode: 'urltest',
  subscription_selected: null,
  outbound_json: `{
  "type": "vless",
  "server": "example.com",
  "server_port": 443,
  "uuid": "00000000-0000-0000-0000-000000000000",
  "flow": "xtls-rprx-vision",
  "network": "tcp",
  "packet_encoding": "xudp",
  "tls": {
    "enabled": true,
    "server_name": "example.com",
    "utls": { "enabled": true, "fingerprint": "chrome" },
    "reality": { "enabled": true, "public_key": "", "short_id": "" }
  },
  "domain_resolver": "bootstrap"
}`,
  enabled: true
})

const canSaveSubscription = computed(() => {
  if (form.kind !== 'subscription') return true
  if (!previewNodes.value.length) {
    return !!(editingId.value && form.subscription_url.trim() === editOriginalSubscriptionUrl.value)
  }
  if (form.subscription_mode === 'single' && !form.subscription_selected) return false
  return true
})

const isAnyPingActive = computed(() =>
  pingQueueActive.value ||
  pingQueue.length > 0 ||
  autoPingSession.active ||
  Object.values(pingStatus).some(s => s?.active) ||
  Object.values(subscriptionState).some(s => s?.pingLoading)
)

watch(() => [form.subscription_url, form.subscription_body], ([url, body]) => {
  if (form.kind !== 'subscription' || !dialogOpen.value) return
  if (parseTimer) clearTimeout(parseTimer)
  parseTimer = setTimeout(() => {
    if (url.trim() || body.trim()) parseDialogSubscription()
    else {
      previewNodes.value = []
      parseError.value = ''
    }
  }, 600)
})

watch(() => form.kind, (kind) => {
  if (kind === 'subscription' && dialogOpen.value && (form.subscription_url.trim() || form.subscription_body.trim())) {
    parseDialogSubscription()
  }
})

function subscriptionModeLabel (row) {
  if (row.subscription_mode === 'urltest') {
    return `urltest · ${row.subscription_nodes_count || 0} узл.`
  }
  return `single · ${row.subscription_nodes_count || 0} узл.`
}

function configTypeBadgeColor (row) {
  if (row.kind === 'subscription') return 'accent'
  if (row.config_type === 'url') return 'primary'
  return 'info'
}

function activeNodeBadge (row) {
  if (row.subscription_pick_source === 'user') return 'выбрано'
  if (row.subscription_pick_source === 'urltest') return 'авто'
  if (row.subscription_pick_source === 'cached' || row.subscription_pick_source === 'ping') return 'кэш'
  return 'активный'
}

function activeNodeBadgeColor (row) {
  if (row.subscription_pick_source === 'user') return 'info'
  if (row.subscription_pick_source === 'cached' || row.subscription_pick_source === 'ping') return 'grey-7'
  return 'positive'
}

function activeNodeKeyForRow (row) {
  if (row.subscription_mode === 'single') {
    return row.subscription_selected || row.subscription_pick_key || null
  }
  if (row.subscription_pick_key) return row.subscription_pick_key
  const st = subscriptionState[row.id]
  if (st?.nodes?.length) {
    const live = bestPickKeyFromNodes(st.nodes)
    if (live) return live
  }
  return null
}

function subscriptionSummary (row) {
  if (!row.subscription_pick_name) {
    return subscriptionModeLabel(row)
  }
  const ms = row.subscription_pick_latency_ms != null ? ` · ${row.subscription_pick_latency_ms} ms` : ''
  const prefix = row.subscription_pick_source === 'user'
    ? 'выбрано'
    : (row.subscription_pick_source === 'cached' || row.subscription_pick_source === 'ping' ? 'кэш' : 'авто')
  return `${prefix}: ${row.subscription_pick_name}${ms}`
}

function bestPickKeyFromNodes (nodes) {
  let best = null
  for (const n of nodes || []) {
    if (!n.latency_ok || n.latency_ms == null) continue
    if (!best || n.latency_ms < best.latency_ms) best = n
  }
  return best?.key ?? null
}

function bestPickKeyForRow (row) {
  return activeNodeKeyForRow(row)
}

const dialogBestPickKey = computed(() => {
  if (editingId.value) {
    const row = connections.value.find(c => c.id === editingId.value)
    if (row) return activeNodeKeyForRow(row)
  }
  if (form.subscription_mode === 'single' && form.subscription_selected) {
    return form.subscription_selected
  }
  return bestPickKeyFromNodes(previewNodes.value)
})

const dialogActivePickSource = computed(() => {
  if (editingId.value) {
    const row = connections.value.find(c => c.id === editingId.value)
    if (row?.subscription_pick_source) return row.subscription_pick_source
  }
  if (form.subscription_mode === 'single') return 'user'
  return null
})

function syncConnectionPick (connectionId, nodes) {
  const row = connections.value.find(c => c.id === connectionId)
  if (!row) return

  if (row.subscription_mode === 'single') {
    const key = row.subscription_selected
    if (key) {
      const node = nodes.find(n => n.key === key)
      row.subscription_pick_key = key
      row.subscription_pick_name = node?.name || row.subscription_selected_name || key
      row.subscription_pick_latency_ms = node?.latency_ms ?? row.subscription_pick_latency_ms
      row.subscription_pick_source = 'user'
      for (const n of nodes) {
        n.is_active = n.key === key
        n.is_best_pick = n.key === key
        n.active_source = n.key === key ? 'user' : null
      }
    }
    return
  }

  if (row.subscription_mode === 'urltest' && row.subscription_pick_key) {
    const key = row.subscription_pick_key
    const node = nodes.find(n => n.key === key)
    if (node?.latency_ms != null) {
      row.subscription_pick_latency_ms = node.latency_ms
    }
    const source = row.subscription_pick_source || 'urltest'
    for (const n of nodes) {
      const active = n.key === key
      n.is_active = active
      n.is_best_pick = active
      n.active_source = active ? source : null
    }
    return
  }

  const key = bestPickKeyFromNodes(nodes)
  if (!key) {
    row.subscription_pick_key = null
    row.subscription_pick_name = null
    row.subscription_pick_latency_ms = null
    row.subscription_pick_source = row.subscription_pick_source === 'urltest' ? 'urltest' : null
    return
  }
  const node = nodes.find(n => n.key === key)
  row.subscription_pick_key = key
  row.subscription_pick_name = node?.name || key
  row.subscription_pick_latency_ms = node?.latency_ms ?? null
  row.subscription_pick_source = 'cached'
  for (const n of nodes) {
    const active = row.subscription_mode === 'urltest'
      ? n.key === row.subscription_pick_key
      : n.key === key
    n.is_active = active
    n.is_best_pick = active
  }
}

function formatBytes (n) {
  const v = Number(n) || 0
  if (v < 1024) return `${v} B`
  if (v < 1024 * 1024) return `${(v / 1024).toFixed(1)} KB`
  if (v < 1024 * 1024 * 1024) return `${(v / (1024 * 1024)).toFixed(1)} MB`
  return `${(v / (1024 * 1024 * 1024)).toFixed(2)} GB`
}

function onlineLabel (row) {
  if (!row.enabled) return 'выкл'
  if (row.online === true) return 'онлайн'
  if (row.online === false) return 'оффлайн'
  return 'не проверен'
}

function lastCheckedAt (row) {
  return row.kind === 'subscription' ? row.ping_last_checked_at : row.last_tested_at
}

function formatLastChecked (iso) {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}

function onlineColor (row) {
  if (!row.enabled) return 'grey-8'
  if (row.tspu?.likely) return 'warning'
  if (row.online === true) return 'positive'
  if (row.online === false) return 'negative'
  return 'grey-7'
}

function stepMark (step) {
  if (step.ok === true) return '✓'
  if (step.ok === false) return '✗'
  return '·'
}

function stepClass (step, tspu) {
  if (step.ok === true) return 'ok'
  if (step.ok === false) {
    return tspu?.block_step === step.id || (tspu?.likely && step.id === 'tls') ? 'fail-block' : 'fail'
  }
  return 'skip'
}

function tspuEndpoint (tspu) {
  if (!tspu) return ''
  if (tspu.ip && tspu.port) return `${tspu.ip}:${tspu.port}`
  if (tspu.server && tspu.port) return `${tspu.server}:${tspu.port}`
  return tspu.server || ''
}

function compareNodesByLatency (a, b) {
  const tier = (n) => {
    if (n.pinging) return 1
    if (n.latency_ok) return 0
    if (n.latency_error || n.latency_ok === false) return 2
    return 1
  }
  const ta = tier(a)
  const tb = tier(b)
  if (ta !== tb) return ta - tb
  if (ta === 0) {
    const am = a.latency_ms ?? Number.MAX_SAFE_INTEGER
    const bm = b.latency_ms ?? Number.MAX_SAFE_INTEGER
    return am - bm
  }
  return String(a.name || '').localeCompare(String(b.name || ''), 'ru')
}

function sortNodesByLatency (list) {
  return [...list].sort(compareNodesByLatency)
}

function applySortedNodes (list) {
  const sorted = sortNodesByLatency(list)
  list.splice(0, list.length, ...sorted)
}

function mergePingResults (nodes, pingNodes) {
  const byKey = Object.fromEntries((pingNodes || []).map(n => [n.key, n]))
  return (nodes || []).map(n => {
    const p = byKey[n.key]
    if (!p) return { ...n }
    return {
      ...n,
      pinging: false,
      latency_ms: p.latency_ms,
      latency_ok: p.latency_ok,
      latency_error: p.latency_error,
      latency_source: p.latency_source
    }
  })
}

function patchNodePing (list, key, patch) {
  const idx = list.findIndex(n => n.key === key)
  if (idx >= 0) {
    list.splice(idx, 1, {
      ...list[idx],
      pinging: false,
      latency_ms: patch.latency_ms,
      latency_ok: patch.latency_ok,
      latency_error: patch.latency_error,
      latency_source: patch.latency_source
    })
  }
  applySortedNodes(list)
}

function markNodesPinging (list, pinging = true) {
  for (let i = 0; i < list.length; i++) {
    list.splice(i, 1, {
      ...list[i],
      pinging,
      ...(pinging ? { latency_error: null } : {})
    })
  }
}

async function consumePingStream (url, payload, onResult, fast = false) {
  await ensureCsrf()
  pingAbortController?.abort()
  pingAbortController = new AbortController()
  const signal = pingAbortController.signal
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  let resp
  try {
    resp = await fetch(url, {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/x-ndjson',
        'X-Requested-With': 'XMLHttpRequest',
        ...(match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {})
      },
      body: JSON.stringify({ ...(payload || {}), fast: !!fast }),
      signal
    })
  } catch (e) {
    if (e?.name === 'AbortError' || pingCancelRequested.value) {
      return { tested: 0, truncated: false, cancelled: true }
    }
    throw e
  }
  if (!resp.ok) {
    let msg = 'Ошибка проверки пинга'
    try {
      const j = await resp.json()
      msg = j.message || j.error || Object.values(j.errors || {}).flat()[0] || msg
    } catch (_) { /* ignore */ }
    if (resp.status === 409 || isPingBusyMessage(msg)) {
      showPingBusyToast(msg)
      return { tested: 0, truncated: false, cancelled: true }
    }
    throw new Error(msg)
  }
  if (!resp.body) {
    throw new Error('Пустой ответ сервера')
  }
  const reader = resp.body.getReader()
  const decoder = new TextDecoder()
  let buf = ''
  let meta = { tested: 0, truncated: false }
  try {
    while (true) {
      if (pingCancelRequested.value) {
        await reader.cancel().catch(() => {})
        return { ...meta, cancelled: true }
      }
      const { done, value } = await reader.read()
      if (done) break
      buf += decoder.decode(value, { stream: true })
      const lines = buf.split('\n')
      buf = lines.pop() || ''
      for (const line of lines) {
        if (!line.trim()) continue
        const msg = JSON.parse(line)
        if (msg.type === 'start') {
          meta.truncated = !!msg.truncated
        } else if (msg.type === 'result') {
          onResult(msg)
        } else if (msg.type === 'error') {
          if (isPingBusyMessage(msg.message)) {
            showPingBusyToast(msg.message)
            return { ...meta, cancelled: true }
          }
          throw new Error(msg.message || 'Ошибка проверки пинга')
        } else if (msg.type === 'done') {
          meta.tested = msg.tested || meta.tested
          meta.truncated = !!msg.truncated
        } else if (msg.type === 'switch') {
          meta.switchInfo = msg
        }
      }
    }
  } catch (e) {
    if (e?.name === 'AbortError' || pingCancelRequested.value) {
      return { ...meta, cancelled: true }
    }
    throw e
  }
  return meta
}

function ensurePingStatus (id) {
  if (!pingStatus[id]) {
    pingStatus[id] = { active: false, tested: 0, total: 0, phase: '' }
  }
  return pingStatus[id]
}

function setConnectionPingStatus (id, patch) {
  Object.assign(ensurePingStatus(id), patch)
}

function clearConnectionPingStatus (id) {
  if (!pingStatus[id]) return
  pingStatus[id].active = false
  pingStatus[id].phase = ''
}

function isPingBusyMessage (message) {
  return String(message || '').includes('Проверка пинга уже выполняется')
}

function showPingBusyToast (message) {
  const msg = String(message || 'Проверка пинга уже выполняется — подождите').trim()
  if (!msg) return
  $q.notify({
    type: 'warning',
    color: 'orange',
    message: msg,
    timeout: 5000,
    position: 'top'
  })
}

function showPingError (message) {
  const msg = String(message || '').trim()
  if (!msg) return
  if (isPingBusyMessage(msg)) {
    showPingBusyToast(msg)
    return
  }
  pingErrorMessage.value = msg
  if (pingErrorTimer) clearTimeout(pingErrorTimer)
  pingErrorTimer = setTimeout(() => { pingErrorMessage.value = null }, 10000)
}

function resetAllPingUiState () {
  testingId.value = null
  for (const id of Object.keys(pingStatus)) {
    clearConnectionPingStatus(id)
  }
  for (const id of Object.keys(subscriptionState)) {
    const st = subscriptionState[id]
    if (!st) continue
    st.pingLoading = false
    st.autoPingActive = false
    if (Array.isArray(st.nodes)) {
      st.nodes = st.nodes.map(n => (n.pinging ? { ...n, pinging: false } : n))
    }
  }
}

async function stopAndRestartPingProbe () {
  if (pingProbeRestarting.value) return
  pingProbeRestarting.value = true
  pingCancelRequested.value = true
  pingAbortController?.abort()
  pingQueue.length = 0
  resetAllPingUiState()
  autoPingSession.active = false
  autoPingSession.currentName = ''
  autoPingRunning.value = false

  try {
    await api.post('/api/resolver/ping-probe/restart')
    $q.notify({ type: 'positive', message: 'Пинг остановлен, probe перезапущен', timeout: 4000 })
  } catch (e) {
    const msg = e?.response?.data?.error || e?.response?.data?.message || e?.message || 'Ошибка перезапуска probe'
    $q.notify({ type: 'negative', message: msg })
  } finally {
    pingCancelRequested.value = false
    pingProbeRestarting.value = false
  }
}

function isConnectionPinging (id) {
  return !!(subscriptionState[id]?.pingLoading || pingStatus[id]?.active)
}

function initSubscriptionState (row, { startMonitor = true } = {}) {
  const existing = subscriptionState[row.id]
  const pinging = isConnectionPinging(row.id)
  subscriptionState[row.id] = {
    mode: row.subscription_mode || 'urltest',
    selected: row.subscription_selected || null,
    nodes: sortNodesByLatency((row.subscription_nodes || []).map(n => ({ ...n }))),
    dirty: false,
    saving: false,
    refreshing: false,
    pingLoading: pinging,
    pingTruncated: existing?.pingTruncated ?? false,
    pingTested: existing?.pingTested ?? pingStatus[row.id]?.tested ?? 0,
    pingIntervalMin: row.ping_check_interval_min ?? 5,
    autoPingActive: pinging,
    baselineMode: row.subscription_mode || 'urltest',
    baselineSelected: row.subscription_selected || null
  }
  if (startMonitor) startPingMonitor(row)
}

function updateExpandDirty (id) {
  const st = subscriptionState[id]
  if (!st) return
  st.dirty = st.mode !== st.baselineMode || st.selected !== st.baselineSelected
}

function toggleExpand (props) {
  const id = props.row.id
  if (props.expand) {
    expandedIds.delete(id)
  } else {
    expandedIds.add(id)
    initSubscriptionState(props.row)
  }
  props.expand = !props.expand
}

function onExpandModeUpdate (id, mode) {
  const st = subscriptionState[id]
  if (!st) return
  st.mode = mode
  onExpandModeChange(id)
}

function onExpandModeChange (id) {
  const st = subscriptionState[id]
  if (!st) return
  if (st.mode === 'urltest') st.selected = null
  updateExpandDirty(id)
}

function selectPreviewNode (node) {
  if (form.subscription_mode !== 'single') return
  form.subscription_selected = node.key
}

function selectExpandNode (id, node) {
  const st = subscriptionState[id]
  if (!st || st.mode !== 'single') return
  st.selected = node.key
  updateExpandDirty(id)
}

function resetForm () {
  form.name = ''
  form.kind = 'proxy'
  form.config_type = 'url'
  form.share_url = ''
  form.subscription_url = ''
  form.subscription_body = ''
  form.subscription_mode = 'urltest'
  form.subscription_selected = null
  previewNodes.value = []
  previewTruncated.value = false
  previewTested.value = 0
  parseError.value = ''
  form.outbound_json = `{
  "type": "vless",
  "server": "example.com",
  "server_port": 443,
  "uuid": "00000000-0000-0000-0000-000000000000",
  "flow": "xtls-rprx-vision",
  "network": "tcp",
  "packet_encoding": "xudp",
  "tls": {
    "enabled": true,
    "server_name": "example.com",
    "utls": { "enabled": true, "fingerprint": "chrome" },
    "reality": { "enabled": true, "public_key": "", "short_id": "" }
  },
  "domain_resolver": "bootstrap"
}`
  form.enabled = true
}

function openCreate () {
  editingId.value = null
  editOriginalSubscriptionUrl.value = ''
  resetForm()
  dialogOpen.value = true
}

function openEdit (row) {
  editingId.value = row.id
  form.name = row.name
  form.kind = row.kind || 'proxy'
  form.config_type = row.config_type || 'url'
  form.share_url = row.share_url || ''
  form.subscription_url = row.subscription_url || ''
  editOriginalSubscriptionUrl.value = row.subscription_url || ''
  form.subscription_mode = row.subscription_mode || 'urltest'
  form.subscription_selected = row.subscription_selected || null
  previewNodes.value = (row.subscription_nodes || []).map(n => ({ ...n }))
  previewTruncated.value = false
  previewTested.value = 0
  parseError.value = ''
  form.outbound_json = JSON.stringify(row.outbound || {}, null, 2)
  form.enabled = !!row.enabled
  dialogOpen.value = true
  if (form.kind === 'subscription' && form.subscription_url.trim() && !previewNodes.value.length) {
    parseDialogSubscription()
  }
}

async function parseDialogSubscription () {
  const url = form.subscription_url.trim()
  const body = form.subscription_body.trim()
  if (!url && !body) return
  parseLoading.value = true
  parseError.value = ''
  try {
    const payload = {}
    if (url) payload.url = url
    if (body) payload.body = body
    const { data } = await api.post('/api/resolver/connections/parse-subscription', payload)
    previewNodes.value = data.nodes || []
    if (form.subscription_mode === 'single') {
      const stillValid = previewNodes.value.some(n => n.key === form.subscription_selected)
      if (!stillValid && previewNodes.value.length) {
        form.subscription_selected = previewNodes.value[0].key
      }
    }
  } catch (e) {
    previewNodes.value = []
    parseError.value = e?.response?.data?.errors?.url?.[0]
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || 'Не удалось разобрать подписку'
  } finally {
    parseLoading.value = false
  }
}

async function pingDialogNode (node) {
  if (!editingId.value) {
    $q.notify({ type: 'warning', message: 'Сохраните подключение перед проверкой пинга' })
    return
  }
  const list = previewNodes.value
  const idx = list.findIndex(n => n.key === node.key)
  if (idx >= 0) list.splice(idx, 1, { ...list[idx], pinging: true, latency_error: null })
  try {
    const { data } = await api.post(`/api/resolver/connections/${editingId.value}/ping-subscription-node`, { key: node.key }, { timeout: 120000 })
    patchNodePing(list, node.key, data.result || {})
  } catch (e) {
    const msg = Object.values(e?.response?.data?.errors || {}).flat()[0]
      || e?.response?.data?.message || 'Ошибка'
    patchNodePing(list, node.key, { latency_ok: false, latency_ms: null, latency_error: msg })
  }
}

async function pingDialogNodes (fast = false) {
  if (!editingId.value) {
    $q.notify({ type: 'warning', message: 'Сохраните подключение перед проверкой пинга' })
    return
  }
  await runSavedConnectionPing(editingId.value, previewNodes, {
    setLoading: (v) => { pingLoading.value = v },
    onTruncated: (v) => { previewTruncated.value = v },
    onTested: (v) => { previewTested.value = v },
    fast
  })
}

function enqueueAutoPing (task) {
  return new Promise((resolve, reject) => {
    pingQueue.push(async () => {
      try {
        resolve(await task())
      } catch (e) {
        reject(e)
      }
    })
    void drainPingQueue()
  })
}

async function drainPingQueue () {
  if (pingQueueActive.value) return
  pingQueueActive.value = true
  while (pingQueue.length) {
    if (pingCancelRequested.value) {
      pingQueue.length = 0
      break
    }
    const task = pingQueue.shift()
    try {
      await task()
    } catch (_) { /* next */ }
  }
  pingQueueActive.value = false
}

async function reloadConnectionById (connectionId) {
  const { data } = await api.get('/api/resolver/connections')
  const conn = (data.connections || []).find(c => c.id === connectionId)
  if (conn) {
    upsertConnection(conn)
    const st = subscriptionState[connectionId]
    if (st && !st.dirty) {
      st.nodes = sortNodesByLatency((conn.subscription_nodes || []).map(n => ({ ...n })))
      st.selected = conn.subscription_selected
      st.baselineSelected = conn.subscription_selected
      st.pingIntervalMin = conn.ping_check_interval_min ?? st.pingIntervalMin
    }
  }
  return conn
}

async function runSavedConnectionPing (connectionId, listRef, opts) {
  return enqueueAutoPing(() => runSavedConnectionPingInner(connectionId, listRef, opts))
}

async function runSavedConnectionPingInner (connectionId, listRef, { setLoading, onTruncated, onTested, fast, silent = false, autoApply = false }) {
  if (!listRef.value.length) return null
  setLoading(true)
  setConnectionPingStatus(connectionId, {
    active: true,
    tested: 0,
    total: listRef.value.length,
    phase: fast ? 'быстрый пинг…' : 'пинг…'
  })
  markNodesPinging(listRef.value, true)
  onTruncated(false)
  onTested(0)
  let tested = 0
  try {
    const meta = await consumePingStream(`/api/resolver/connections/${connectionId}/ping-subscription-stream`, { auto_apply: autoApply }, (msg) => {
      patchNodePing(listRef.value, msg.key, msg)
      syncConnectionPick(connectionId, listRef.value)
      tested += 1
      onTested(tested)
      setConnectionPingStatus(connectionId, { tested })
    }, fast)
    if (meta.cancelled) {
      listRef.value = listRef.value.map(n => (n.pinging ? { ...n, pinging: false } : n))
      return null
    }
    onTruncated(!!meta.truncated)
    onTested(meta.tested || tested)
    applySortedNodes(listRef.value)
    syncConnectionPick(connectionId, listRef.value)
    if (meta.switchInfo?.switched) {
      await refreshConnectionAfterSwitch(connectionId, meta.switchInfo, silent)
    } else if (!silent) {
      await reloadConnectionById(connectionId)
    }
    if (!silent) {
      const okCount = listRef.value.filter(n => n.latency_ok).length
      $q.notify({ type: 'positive', message: `Пинг: онлайн ${okCount} из ${meta.tested || tested}`, timeout: 5000 })
    }
    return meta
  } catch (e) {
    if (pingCancelRequested.value || e?.name === 'AbortError') {
      listRef.value = listRef.value.map(n => (n.pinging ? { ...n, pinging: false } : n))
      return null
    }
    listRef.value = listRef.value.map(n => (n.pinging ? { ...n, pinging: false } : n))
    if (!silent) {
      $q.notify({ type: 'negative', message: e.message || 'Ошибка проверки пинга' })
    }
    throw e
  } finally {
    setLoading(false)
    clearConnectionPingStatus(connectionId)
  }
}

async function refreshConnectionAfterSwitch (connectionId, switchInfo, silent) {
  await reloadConnectionById(connectionId)
  if (switchInfo?.switched && switchInfo.pick_name && !silent) {
    $q.notify({
      type: 'info',
      message: `Переключено на лучший узел: ${switchInfo.pick_name}`,
      timeout: 5000
    })
  }
}

async function runBackgroundPingForConnection (row, { silent = true, autoApply = true } = {}) {
  if (!row?.enabled) return
  if (row.kind === 'subscription') {
    const nodes = row.subscription_nodes || []
    if (!nodes.length) return
    if (!subscriptionState[row.id]) {
      initSubscriptionState(row, { startMonitor: false })
    }
    const st = subscriptionState[row.id]
    if (!st.nodes.length && nodes.length) {
      st.nodes = sortNodesByLatency(nodes.map(n => ({ ...n })))
    }
    setConnectionPingStatus(row.id, {
      active: true,
      tested: 0,
      total: st.nodes.length || nodes.length,
      phase: 'пинг…'
    })
    st.autoPingActive = true
    const nodesRef = { get value () { return st.nodes }, set value (v) { st.nodes = v } }
    try {
      await runSavedConnectionPingInner(row.id, nodesRef, {
        setLoading: (v) => {
          st.pingLoading = v
          st.autoPingActive = v
        },
        onTruncated: (v) => { st.pingTruncated = v },
        onTested: (v) => { st.pingTested = v },
        fast: false,
        silent,
        autoApply
      })
    } finally {
      st.autoPingActive = false
    }
    return
  }
  setConnectionPingStatus(row.id, { active: true, tested: 0, total: 1, phase: 'проверка…' })
  await testConnectionSilent(row)
}

async function runAutoPingAll () {
  if (autoPingRunning.value) return
  autoPingRunning.value = true
  const enabled = connections.value.filter(r => r.enabled)
  const subscriptions = enabled.filter(r => r.kind === 'subscription' && (r.subscription_nodes?.length || 0))
  const proxies = enabled.filter(r => r.kind !== 'subscription')

  autoPingSession.active = true
  autoPingSession.done = 0
  autoPingSession.total = subscriptions.length + proxies.length
  autoPingSession.currentName = ''

  try {
    for (const row of subscriptions) {
      if (pingCancelRequested.value) break
      autoPingSession.currentName = row.name
      try {
        await enqueueAutoPing(() => runBackgroundPingForConnection(row, { silent: true, autoApply: true }))
      } catch (e) {
        showPingError(e?.message || 'Ошибка автопроверки пинга')
      } finally {
        autoPingSession.done += 1
      }
    }

    // Global lock на сессию пинга не позволяет параллельные проверки.
    // Поэтому прокси запускаем после подписок.
    for (const row of proxies) {
      if (pingCancelRequested.value) break
      autoPingSession.currentName = row.name
      try {
        await enqueueAutoPing(() => runBackgroundPingForConnection(row, { silent: true, autoApply: false }))
      } catch (e) {
        showPingError(e?.message || 'Ошибка автопроверки пинга')
      } finally {
        autoPingSession.done += 1
      }
    }

    await load(true)
  } finally {
    autoPingSession.active = false
    autoPingSession.currentName = ''
    autoPingRunning.value = false
  }
}

function stopPingMonitor (row) {
  const id = row?.id ?? row
  const t = pingTimers.get(id)
  if (t) {
    clearInterval(t)
    pingTimers.delete(id)
  }
}

function startPingMonitor (row) {
  if (!row?.id || row.kind !== 'subscription') return
  stopPingMonitor(row)
  const st = subscriptionState[row.id]
  const intervalMin = st?.pingIntervalMin ?? row.ping_check_interval_min ?? 5
  if (!row.enabled || !intervalMin || intervalMin <= 0) return
  const ms = intervalMin * 60 * 1000
  pingTimers.set(row.id, setInterval(() => {
    void enqueueAutoPing(() => runBackgroundPingForConnection(row, { silent: true, autoApply: true }))
  }, ms))
}

function restartAllPingMonitors () {
  for (const id of [...pingTimers.keys()]) stopPingMonitor(id)
  for (const row of connections.value) {
    if (row.kind === 'subscription' && row.enabled) {
      if (!subscriptionState[row.id]) initSubscriptionState(row, { startMonitor: false })
      startPingMonitor(row)
    }
  }
}

async function savePingInterval (row) {
  const st = subscriptionState[row.id]
  if (!st) return
  try {
    const { data } = await api.put(`/api/resolver/connections/${row.id}`, {
      ping_check_interval_min: st.pingIntervalMin
    })
    if (data.connection) {
      upsertConnection(data.connection)
      st.pingIntervalMin = data.connection.ping_check_interval_min ?? st.pingIntervalMin
    }
    startPingMonitor(row)
    $q.notify({ type: 'positive', message: 'Интервал автопроверки сохранён', timeout: 2500 })
  } catch (e) {
    const msg = Object.values(e?.response?.data?.errors || {}).flat()[0]
      || e?.response?.data?.message || 'Ошибка сохранения интервала'
    $q.notify({ type: 'negative', message: msg })
  }
}

async function testConnectionSilent (row) {
  try {
    const { data } = await api.post(`/api/resolver/connections/${row.id}/test`)
    if (data.connection) upsertConnection(data.connection)
  } catch (e) {
    const conn = e?.response?.data?.connection
    if (conn) upsertConnection(conn)
    const msg = e?.response?.data?.error
      || e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || e?.message
      || ''
    if (e?.response?.status === 409 || isPingBusyMessage(msg)) {
      showPingBusyToast(msg)
    }
  } finally {
    clearConnectionPingStatus(row.id)
  }
}

async function pingExpandNode (row, node) {
  if (isConnectionPinging(row.id)) return
  if (!subscriptionState[row.id]) initSubscriptionState(row, { startMonitor: false })
  const st = subscriptionState[row.id]
  setConnectionPingStatus(row.id, { active: true, tested: 0, total: 1, phase: 'пинг узла…' })
  const idx = st.nodes.findIndex(n => n.key === node.key)
  if (idx >= 0) st.nodes.splice(idx, 1, { ...st.nodes[idx], pinging: true, latency_error: null })
  try {
    const { data } = await enqueueAutoPing(() =>
      api.post(`/api/resolver/connections/${row.id}/ping-subscription-node`, { key: node.key }, { timeout: 120000 })
    )
    patchNodePing(st.nodes, node.key, data.result || {})
    syncConnectionPick(row.id, st.nodes)
  } catch (e) {
    const status = e?.response?.status
    const msg = e?.response?.data?.error
      || Object.values(e?.response?.data?.errors || {}).flat()[0]
      || e?.response?.data?.message || 'Ошибка'
    if (status === 409 || isPingBusyMessage(msg)) {
      showPingBusyToast(msg)
    }
    patchNodePing(st.nodes, node.key, { latency_ok: false, latency_ms: null, latency_error: msg })
  } finally {
    clearConnectionPingStatus(row.id)
    if (idx >= 0) st.nodes.splice(idx, 1, { ...st.nodes[idx], pinging: false })
    await reloadConnectionById(row.id)
  }
}

async function pingExpandNodes (row, fast = false) {
  if (isConnectionPinging(row.id)) return
  if (!subscriptionState[row.id]) initSubscriptionState(row, { startMonitor: false })
  const st = subscriptionState[row.id]
  if (!st.nodes.length && (row.subscription_nodes || []).length) {
    st.nodes = sortNodesByLatency((row.subscription_nodes || []).map(n => ({ ...n })))
  }
  const nodesRef = { get value () { return st.nodes }, set value (v) { st.nodes = v } }
  await runSavedConnectionPing(row.id, nodesRef, {
    setLoading: (v) => {
      st.pingLoading = v
      st.autoPingActive = v
    },
    onTruncated: (v) => { st.pingTruncated = v },
    onTested: (v) => { st.pingTested = v },
    fast
  })
}

function upsertConnection (conn) {
  const idx = connections.value.findIndex(c => c.id === conn.id)
  if (idx >= 0) {
    connections.value[idx] = conn
  } else {
    connections.value.push(conn)
  }
}

async function refreshSubscription (row) {
  const st = subscriptionState[row.id]
  if (!st) return
  st.refreshing = true
  try {
    const { data } = await withApplyProgress('connection-refresh', () =>
      api.put(`/api/resolver/connections/${row.id}`, {
        refresh_subscription: true
      })
    )
    const conn = data.connection
    upsertConnection(conn)
    st.nodes = (conn.subscription_nodes || []).map(n => ({ ...n }))
    st.baselineMode = conn.subscription_mode
    st.baselineSelected = conn.subscription_selected
    st.mode = conn.subscription_mode
    st.selected = conn.subscription_selected
    st.dirty = false
    $q.notify({
      type: 'positive',
      message: data.singbox_reloaded
        ? `Обновлено ${conn.subscription_nodes_count} узл.`
        : `Подписка актуальна (${conn.subscription_nodes_count} узл.)`
    })
  } catch (e) {
    const msg = Object.values(e?.response?.data?.errors || {}).flat()[0]
      || e?.response?.data?.message || 'Ошибка обновления'
    $q.notify({ type: 'negative', message: msg })
  } finally {
    st.refreshing = false
  }
}

async function applySubscription (row) {
  const st = subscriptionState[row.id]
  if (!st || !st.dirty) return
  if (st.mode === 'single' && !st.selected) {
    $q.notify({ type: 'warning', message: 'Выберите локацию' })
    return
  }
  st.saving = true
  try {
    const { data } = await withApplyProgress('connection-apply', () =>
      api.put(`/api/resolver/connections/${row.id}`, {
        subscription_mode: st.mode,
        subscription_selected: st.mode === 'single' ? st.selected : null
      })
    )
    const conn = data.connection
    upsertConnection(conn)
    st.baselineMode = conn.subscription_mode
    st.baselineSelected = conn.subscription_selected
    st.mode = conn.subscription_mode
    st.selected = conn.subscription_selected
    st.dirty = false
    $q.notify({ type: 'positive', message: 'Стратегия применена' })
  } catch (e) {
    const msg = Object.values(e?.response?.data?.errors || {}).flat()[0]
      || e?.response?.data?.message || 'Ошибка сохранения'
    $q.notify({ type: 'negative', message: msg })
  } finally {
    st.saving = false
  }
}

async function load (silent = false) {
  if (!silent) loading.value = true
  try {
    const { data } = await api.get('/api/resolver/connections')
    connections.value = data.connections || []
    expandedIds.forEach((id) => {
      const row = connections.value.find(c => c.id === id)
      if (row && row.kind === 'subscription') {
        if (!subscriptionState[id]) initSubscriptionState(row, { startMonitor: false })
        else if (!subscriptionState[id].dirty) {
          subscriptionState[id].nodes = sortNodesByLatency((row.subscription_nodes || []).map(n => ({ ...n })))
          subscriptionState[id].baselineMode = row.subscription_mode
          subscriptionState[id].baselineSelected = row.subscription_selected
          subscriptionState[id].mode = row.subscription_mode
          subscriptionState[id].selected = row.subscription_selected
          subscriptionState[id].pingIntervalMin = row.ping_check_interval_min ?? subscriptionState[id].pingIntervalMin
        }
      }
    })
  } catch (e) {
    if (!silent) {
      $q.notify({ type: 'negative', message: e?.response?.data?.message || 'Ошибка загрузки' })
    }
  } finally {
    if (!silent) loading.value = false
  }
}

async function testConnection (row) {
  if (!row?.enabled) return
  testingId.value = row.id
  try {
    const { data } = await enqueueAutoPing(() => api.post(`/api/resolver/connections/${row.id}/test`))
    if (data.connection) upsertConnection(data.connection)
    $q.notify({
      type: 'positive',
      message: data.tspu?.likely
        ? `Онлайн · ${data.latency_ms} ms (ТСПУ-проверка: подозрительно)`
        : `Онлайн · ${data.latency_ms} ms`
    })
  } catch (e) {
    const conn = e?.response?.data?.connection
    if (conn) upsertConnection(conn)
    const tspu = e?.response?.data?.tspu
    const msg = (tspu?.likely ? 'ТСПУ · ' : '')
      + (e?.response?.data?.error || e?.response?.data?.message
        || Object.values(e?.response?.data?.errors || {}).flat()[0] || 'Соединение недоступно')
    const status = e?.response?.status
    if (status === 409 || isPingBusyMessage(msg)) {
      showPingBusyToast(msg)
    } else {
      $q.notify({ type: tspu?.likely ? 'warning' : 'negative', message: msg, timeout: 6000 })
    }
  } finally {
    testingId.value = null
  }
}

async function save () {
  if (form.kind === 'subscription' && !canSaveSubscription.value) {
    $q.notify({ type: 'warning', message: 'Дождитесь загрузки узлов подписки' })
    return
  }

  saving.value = true
  try {
    let payload
    if (form.kind === 'subscription') {
      payload = {
        name: form.name,
        kind: 'subscription',
        subscription_url: form.subscription_url.trim(),
        subscription_body: form.subscription_body.trim() || null,
        subscription_mode: form.subscription_mode,
        subscription_selected: form.subscription_mode === 'single' ? form.subscription_selected : null,
        enabled: form.enabled
      }
    } else {
      payload = {
        name: form.name,
        kind: 'proxy',
        config_type: form.config_type,
        enabled: form.enabled,
        share_url: form.config_type === 'url' ? form.share_url : null,
        outbound_json: form.config_type === 'json' ? form.outbound_json : null
      }
    }

    const scenario = form.kind === 'subscription' ? 'connection-save-subscription' : 'connection-save-proxy'
    const { data } = await withApplyProgress(scenario, () =>
      editingId.value
        ? api.put(`/api/resolver/connections/${editingId.value}`, payload)
        : api.post('/api/resolver/connections', payload)
    )

    upsertConnection(data.connection)
    dialogOpen.value = false
    $q.notify({ type: 'positive', message: 'Сохранено' })
  } catch (e) {
    const msg = e?.response?.data?.message
      || Object.values(e?.response?.data?.errors || {}).flat()[0] || 'Ошибка сохранения'
    $q.notify({ type: 'negative', message: msg })
  } finally {
    saving.value = false
  }
}

async function remove (row) {
  $q.dialog({
    title: 'Удалить подключение?',
    message: `«${row.name}» будет удалено.`,
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    try {
      await api.delete(`/api/resolver/connections/${row.id}`)
      delete subscriptionState[row.id]
      expandedIds.delete(row.id)
      $q.notify({ type: 'positive', message: 'Удалено' })
      await load()
    } catch (e) {
      const msg = e?.response?.data?.message
        || Object.values(e?.response?.data?.errors || {}).flat()[0] || 'Ошибка удаления'
      $q.notify({ type: 'negative', message: msg })
    }
  })
}

onMounted(async () => {
  await load()
  await nextTick()
  void api.post('/api/resolver/ping-probe/warmup').catch(() => {})
  void runAutoPingAll().catch(e => showPingError(e?.message || 'Ошибка автопроверки пинга'))
  restartAllPingMonitors()
})

onUnmounted(() => {
  if (parseTimer) clearTimeout(parseTimer)
  for (const id of [...pingTimers.keys()]) stopPingMonitor(id)
})
</script>

<style scoped>
.page-wrap { max-width: 1200px; margin: 0 auto; }
.config-type-cell {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  line-height: 1.35;
}
.mono { font-family: var(--theme-mono); }
.full-width { width: 100%; }
.name-cell { white-space: normal; line-height: 1.35; }
.traffic { display: flex; flex-direction: column; gap: 2px; font-size: 12px; line-height: 1.3; }
.traffic .rx { color: #7ee787; }
.traffic .tx { color: #79c0ff; }
.online-cell { min-width: 260px; max-width: 440px; white-space: normal; }
.auto-ping-banner {
  background: rgba(88, 166, 255, 0.12);
  border: 1px solid rgba(88, 166, 255, 0.35);
  color: var(--surface-text, #e6edf3);
}
.expanded-cell { background: rgba(255, 255, 255, 0.03); }
.tspu-chain { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 4px; line-height: 1.35; }
.tspu-step { font-size: 11px; padding: 1px 4px; border-radius: 3px; }
.tspu-step.ok { color: #7dcea0; background: rgba(125, 206, 160, 0.12); }
.tspu-step.fail { color: #f1948a; background: rgba(241, 148, 138, 0.12); }
.tspu-step.fail-block { color: #f5b041; background: rgba(245, 176, 65, 0.18); font-weight: 600; }
.tspu-step.skip { color: #888; }
.tspu-arrow { font-size: 10px; }
:deep(.preview-nodes .q-table__top) {
  padding: 8px 12px;
  gap: 8px;
}
:deep(.preview-nodes) {
  max-height: 280px;
  overflow: auto;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 6px;
}
:deep(.preview-node-name) { cursor: pointer; }
:deep(.preview-node-name.selected) { color: var(--surface-highlight, #7ee787); font-weight: 600; }
:deep(.preview-node-name.best-pick-name) { font-weight: 600; }
:deep(.preview-node-name:hover) { text-decoration: underline; }

/* Separate mode buttons — Quasar QBtnGroup otherwise merges them into one outline.
   flat + no toggleColor: avoid CRT/SA theme rules that force text on .bg-primary */
:deep(.subscription-mode-toggle.q-btn-group) {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 8px;
  box-shadow: none;
}
:deep(.subscription-mode-toggle .q-btn) {
  margin: 0 !important;
  border-radius: var(--surface-radius, 6px) !important;
  border: 1px solid var(--surface-border) !important;
  background: var(--surface-panel-alt) !important;
  color: var(--surface-text-soft) !important;
  opacity: 1;
  font-weight: 500;
  box-shadow: none !important;
  text-shadow: none !important;
}
:deep(.subscription-mode-toggle .q-btn .q-btn__content) {
  color: inherit !important;
}
:deep(.subscription-mode-toggle .q-btn.q-btn--active),
:deep(.subscription-mode-toggle .q-btn[aria-pressed='true']) {
  background: var(--surface-highlight-bg) !important;
  border-color: var(--surface-highlight) !important;
  color: var(--surface-text) !important;
  font-weight: 700;
}
:deep(.subscription-mode-toggle .q-btn.q-btn--active .q-btn__content),
:deep(.subscription-mode-toggle .q-btn[aria-pressed='true'] .q-btn__content) {
  color: var(--surface-text) !important;
}

/* Radio must stay visible on all themes (secondary was #243447 — invisible) */
:deep(.subscription-node-radio .q-radio__inner) {
  color: var(--surface-text-muted);
  width: 24px;
  min-width: 24px;
  height: 24px;
}
:deep(.subscription-node-radio.q-radio--checked .q-radio__inner),
:deep(.subscription-node-radio .q-radio__inner--truthy) {
  color: var(--surface-highlight);
}
:deep(.subscription-node-radio .q-radio__bg) {
  opacity: 1;
}
</style>
