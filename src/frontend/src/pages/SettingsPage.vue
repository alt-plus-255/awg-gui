<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md">
        <div class="text-h5 col">Настройки</div>
        <q-btn
          v-if="isDirty"
          color="primary"
          label="Сохранить"
          :loading="saving"
          @click="save"
        />
      </div>

      <q-card class="surface-panel settings-card">
        <q-tabs
          v-model="activeTab"
          dense
          align="left"
          active-color="primary"
          indicator-color="primary"
          class="settings-tabs text-muted-theme"
          outside-arrows
          mobile-arrows
        >
          <q-tab name="general" label="Общее" />
          <q-tab name="panel" label="Панель" />
          <q-tab v-if="hasDomain" name="https" label="HTTPS" />
          <q-tab name="twofa" label="2FA" />
        </q-tabs>

        <q-separator />

        <q-form>
          <q-tab-panels v-model="activeTab" animated class="settings-panels">
            <q-tab-panel name="general" class="q-pa-md">
              <div class="text-subtitle2 q-mb-sm">Оформление</div>

              <div class="text-caption text-soft-theme q-mb-xs">Тема</div>
              <q-option-group
                :model-value="theme.colorMode"
                :options="theme.colorModeOptionList"
                type="radio"
                color="primary"
                @update:model-value="theme.setColorMode"
              />

              <div v-if="theme.colorMode === 'auto'" class="row q-col-gutter-sm q-mt-sm q-mb-md">
                <div class="col-12 col-sm-6">
                  <q-input
                    :model-value="theme.autoHours.from"
                    type="time"
                    label="Светлая с"
                    filled
                    @update:model-value="onAutoFrom"
                  />
                </div>
                <div class="col-12 col-sm-6">
                  <q-input
                    :model-value="theme.autoHours.to"
                    type="time"
                    label="до"
                    filled
                    @update:model-value="onAutoTo"
                  />
                </div>
              </div>

              <div class="text-caption text-soft-theme q-mb-xs q-mt-md">Стиль</div>
              <q-option-group
                :model-value="theme.styleId"
                :options="theme.styleOptions"
                type="radio"
                color="primary"
                @update:model-value="theme.setStyle"
              />

              <q-separator class="q-my-md" />

              <div class="text-subtitle2 q-mb-sm">Звуки</div>
              <q-toggle
                :model-value="sounds.enabled"
                label="Звуки интерфейса"
                color="primary"

                @update:model-value="sounds.setEnabled"
              />

              <q-separator class="q-my-md" />

              <div class="text-subtitle2 q-mb-sm">Оповещение о сбое</div>
              <q-input
                v-model="form.failure_webhook_url"
                label="URL webhook при сбое"
                hint="POST JSON при сбое загрузки/ensure"

                filled
                class="q-mb-md"
              />
              <div class="row q-gutter-sm">
                <q-btn outline color="accent" label="JSON-схема" @click="schemaOpen = true" />
                <q-btn outline color="primary" label="Отправить тест" :loading="testing" @click="testWebhook" />
              </div>
            </q-tab-panel>

            <q-tab-panel name="panel" class="q-pa-md">
              <div class="row q-col-gutter-md">
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.server_endpoint"
                    label="Публичный IP сервера"
                    hint="IPv4 панели; используется в VPN Endpoint, если не выбран домен"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_domain"
                    label="Домен панели (необязательно)"
                    :hint="domainHint"

                    filled
                    clearable
                    :error="!!domainError"
                    :error-message="domainError"
                    @update:model-value="domainError = ''"
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_port"
                    label="Порт HTTP панели"
                    hint="По умолчанию 8877"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_https_port"
                    label="Порт HTTPS панели"
                    hint="По умолчанию 7443; используется после выпуска сертификата"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-select
                    v-model="form.timezone"
                    :options="timezoneOptions"
                    label="Временная зона"
                    hint="Используется для дат и webhook timestamp в панели"

                    filled
                    use-input
                    fill-input
                    hide-selected
                    input-debounce="0"
                    emit-value
                    map-options
                    @filter="filterTimezones"
                  />
                </div>
                <div v-if="hasDomain" class="col-12">
                  <q-toggle
                    v-model="form.endpoint_use_domain"
                    label="В Endpoint VPN-конфигов подставлять домен вместо IP"
                    color="primary"

                  />
                  <div class="text-caption text-grey-5 q-mt-xs">
                    В клиентских конфигах:
                    <span class="mono">Endpoint = {{ endpointHostPreview }}:&lt;listen_port&gt;</span>
                  </div>
                </div>
              </div>
              <div class="text-caption text-grey-5 q-mt-sm">
                URL панели: <span class="mono">{{ panelUrl || '—' }}</span>
              </div>
              <div class="text-caption text-grey-5 q-mt-xs">
                AWG-конфиги, ключи сервера и подсети настраиваются на странице «Конфиги».
              </div>
            </q-tab-panel>

            <q-tab-panel v-if="hasDomain" name="https" class="q-pa-md">
              <div class="text-caption text-grey-5 q-mb-md">
                {{ sslHint }}
              </div>

              <div class="row q-col-gutter-md q-mb-md">
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="sslEmail"
                    label="Email для Let's Encrypt"
                    hint="Нужен при выпуске и обновлении сертификата"

                    filled
                    type="email"
                    :disable="sslBusy"
                  />
                </div>
                <div class="col-12 col-md-6">
                  <div class="text-caption text-grey-5">Статус</div>
                  <div class="text-body2">{{ sslStatusLabel }}</div>
                  <div v-if="ssl.expires_at" class="text-caption text-grey-5 q-mt-xs">
                    Действует до: <span class="mono">{{ ssl.expires_at }}</span>
                  </div>
                  <div v-if="ssl.error" class="text-negative text-caption q-mt-xs">
                    {{ ssl.error }}
                  </div>
                </div>
              </div>

              <div v-if="activeChallenge" class="challenge-box q-mb-md">
                <div class="text-subtitle2 q-mb-sm">Добавьте TXT-запись в DNS</div>
                <div class="text-caption text-grey-5 q-mb-xs">Имя:</div>
                <div class="mono q-mb-sm">{{ activeChallenge.txt_name }}</div>
                <div class="text-caption text-grey-5 q-mb-xs">Значение:</div>
                <div class="mono q-mb-md" style="word-break: break-all;">{{ activeChallenge.txt_value }}</div>
                <div class="row q-gutter-sm">
                  <q-btn
                    color="primary"
                    label="Продолжить после добавления TXT"
                    :loading="sslCompleting"
                    :disable="sslBusy && !sslCompleting"
                    @click="completeSsl"
                  />
                  <q-btn
                    flat
                    color="grey-5"
                    label="Отменить"
                    :disable="sslBusy && !sslIssuing"
                    @click="abortSsl"
                  />
                </div>
              </div>

              <div class="row q-gutter-sm">
                <q-btn
                  v-if="!ssl.enabled"
                  color="primary"
                  label="Выпустить сертификат"
                  :loading="sslIssuing"
                  :disable="sslBusy || !!activeChallenge"
                  @click="issueSsl(false)"
                />
                <q-btn
                  v-if="ssl.enabled"
                  outline
                  color="primary"
                  label="Обновить сертификат"
                  :loading="sslIssuing"
                  :disable="sslBusy || !!activeChallenge"
                  @click="issueSsl(true)"
                />
                <q-btn
                  v-if="ssl.enabled"
                  outline
                  color="negative"
                  label="Отключить HTTPS"
                  :loading="sslDisabling"
                  :disable="sslBusy"
                  @click="disableSsl"
                />
              </div>
            </q-tab-panel>

            <q-tab-panel name="twofa" class="q-pa-md">
              <div class="text-caption text-grey-5 q-mb-md">
                Дополнительный код из приложения (Google Authenticator, Aegis и т.п.) при входе.
                При потере доступа: <span class="mono">awg-gui 2fa disable</span>
              </div>

              <div class="row items-center q-gutter-sm q-mb-md">
                <q-badge :color="twoFactorEnabled ? 'positive' : 'grey-7'">
                  {{ twoFactorEnabled ? 'Включена' : 'Выключена' }}
                </q-badge>
                <q-spinner v-if="twoFactorLoading" size="20px" color="primary" />
              </div>

              <div v-if="!twoFactorEnabled && !twoFactorSetup" class="row q-gutter-sm">
                <q-btn
                  color="primary"
                  label="Включить 2FA"
                  :loading="twoFactorBusy"
                  @click="startTwoFactorSetup"
                />
              </div>

              <div v-if="twoFactorSetup" class="q-mb-md">
                <div class="text-caption text-grey-5 q-mb-sm">
                  Отсканируйте QR в приложении или введите секрет вручную, затем подтвердите кодом.
                </div>
                <div class="row q-gutter-md items-start q-mb-md">
                  <img v-if="twoFactorQr" :src="twoFactorQr" alt="QR 2FA" class="twofa-qr" />
                  <div class="col">
                    <div class="text-caption text-grey-5">Секрет</div>
                    <div class="mono q-mb-sm" style="word-break: break-all;">{{ twoFactorSecret }}</div>
                  </div>
                </div>
                <q-input
                  v-model="twoFactorConfirmCode"
                  label="Код подтверждения"

                  filled
                  maxlength="6"
                  inputmode="numeric"
                  class="q-mb-md"
                  style="max-width: 240px;"
                />
                <div class="row q-gutter-sm">
                  <q-btn
                    color="primary"
                    label="Подтвердить"
                    :loading="twoFactorBusy"
                    @click="confirmTwoFactor"
                  />
                  <q-btn flat color="grey-5" label="Отмена" :disable="twoFactorBusy" @click="cancelTwoFactorSetup" />
                </div>
              </div>

              <div v-if="twoFactorEnabled">
                <q-btn
                  outline
                  color="negative"
                  label="Отключить 2FA"
                  :disable="twoFactorBusy"
                  @click="openDisableTwoFactor"
                />
              </div>
            </q-tab-panel>

          </q-tab-panels>
        </q-form>
      </q-card>
    </div>

    <q-dialog v-model="twoFactorDisableOpen" v-bind="mobileDialog" persistent>
      <q-card style="width: min(420px, 95vw);" class="surface-panel dialog-card column no-wrap">
        <q-card-section class="text-h6">Отключить 2FA</q-card-section>
        <q-card-section class="col dialog-scroll-body">
          <div class="text-caption text-grey-5 q-mb-md">
            Нужны пароль аккаунта и код из приложения — чтобы злоумышленник с открытой сессией
            не смог снять защиту одним кликом. Без этого отключение 2FA теряет смысл.
          </div>
          <q-input
            v-model="twoFactorDisablePassword"
            label="Пароль"
            type="password"

            filled
            class="q-mb-md"
            autocomplete="current-password"
            :disable="twoFactorBusy"
          />
          <q-input
            v-model="twoFactorDisableCode"
            label="Код 2FA"

            filled
            maxlength="6"
            inputmode="numeric"
            autocomplete="one-time-code"
            :disable="twoFactorBusy"
            @keyup.enter="disableTwoFactor"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Отмена" color="grey-5" :disable="twoFactorBusy" v-close-popup />
          <q-btn
            color="negative"
            label="Отключить"
            :loading="twoFactorBusy"
            @click="disableTwoFactor"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="schemaOpen" v-bind="mobileDialog">
      <q-card style="width: min(720px, 95vw); max-width: 95vw;" class="surface-panel dialog-card column no-wrap">
        <q-card-section class="text-h6">JSON-схема Failure webhook 1.0</q-card-section>
        <q-card-section class="col dialog-scroll-body">
          <q-markup-table flat dense class="q-mb-md">
            <thead>
              <tr>
                <th class="text-left">Поле</th>
                <th class="text-left">Описание</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(desc, key) in schema.fields || {}" :key="key">
                <td class="mono">{{ key }}</td>
                <td>{{ desc }}</td>
              </tr>
            </tbody>
          </q-markup-table>
          <div class="text-caption q-mb-sm">Пример payload:</div>
          <pre class="mono schema-pre">{{ JSON.stringify(schema.example || {}, null, 2) }}</pre>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat label="Закрыть" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import { useThemeStore } from '@/stores/theme'
import { useSettingsStore } from '@/stores/settings'
import { useSoundStore } from '@/sounds/store'
import { useMobileDialog } from '@/composables/useMobileDialog'

const $q = useQuasar()
const mobileDialog = useMobileDialog()
const theme = useThemeStore()
const sounds = useSoundStore()

function onAutoFrom (value) {
  theme.setAutoHours({ ...theme.autoHours, from: value })
}

function onAutoTo (value) {
  theme.setAutoHours({ ...theme.autoHours, to: value })
}
const settingsStore = useSettingsStore()
const activeTab = ref('general')
const saving = ref(false)
const testing = ref(false)
const schemaOpen = ref(false)
const domainError = ref('')
const baseline = ref(null)
const timezoneOptions = ref([{ label: 'UTC', value: 'UTC' }])
const sslEmail = ref('')
const sslIssuing = ref(false)
const sslCompleting = ref(false)
const sslDisabling = ref(false)

const twoFactorLoading = ref(false)
const twoFactorBusy = ref(false)
const twoFactorEnabled = ref(false)
const twoFactorSetup = ref(false)
const twoFactorQr = ref('')
const twoFactorSecret = ref('')
const twoFactorConfirmCode = ref('')
const twoFactorDisableOpen = ref(false)
const twoFactorDisablePassword = ref('')
const twoFactorDisableCode = ref('')

const form = reactive({
  server_endpoint: '',
  panel_domain: '',
  endpoint_use_domain: false,
  panel_port: '',
  panel_https_port: '7443',
  failure_webhook_url: '',
  timezone: 'UTC'
})

const panelUrl = computed(() => settingsStore.panelUrl)
const schema = computed(() => settingsStore.webhookSchema)
const ssl = computed(() => settingsStore.ssl)
const hasDomain = computed(() => String(form.panel_domain || '').trim() !== '')
const activeChallenge = computed(() => ssl.value.challenge || null)
const sslBusy = computed(() => sslIssuing.value || sslCompleting.value || sslDisabling.value)

const domainHint = computed(() => {
  if (!hasDomain.value) {
    return 'Например: vpn.example.com — A-запись должна указывать на публичный IP'
  }
  return 'A-запись на IP панели. Для HTTPS позже потребуется дополнительная TXT-запись _acme-challenge в DNS (не только A).'
})

const sslHint = computed(() => {
  const port = String(form.panel_https_port || ssl.value.https_port || '7443').trim() || '7443'
  const base = ssl.value.hint
    || 'Для выпуска и обновления сертификата Let\'s Encrypt недостаточно A-записи на IP панели. В DNS домена нужно будет дополнительно создать TXT-запись _acme-challenge (имя и значение покажем после нажатия «Выпустить» / «Обновить»). После добавления записи подтвердите выпуск в панели.'
  return `${base} Доступ: https://ваш-домен:${port} (порт можно сменить во вкладке «Панель»).`
})

const sslStatusLabel = computed(() => {
  const map = {
    disabled: 'Выключен',
    pending: 'Ожидание TXT в DNS',
    active: 'Активен',
    error: 'Ошибка'
  }
  return map[ssl.value.status] || ssl.value.status || '—'
})

const endpointHostPreview = computed(() => {
  const domain = String(form.panel_domain || '').trim()
  if (form.endpoint_use_domain && domain) return domain
  return String(form.server_endpoint || '').trim() || '—'
})

const isDirty = computed(() => {
  if (!baseline.value) return false
  return snapshotForm() !== baseline.value
})

function asBool (value) {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function snapshotForm () {
  return JSON.stringify({
    server_endpoint: String(form.server_endpoint || '').trim(),
    panel_domain: String(form.panel_domain || '').trim(),
    endpoint_use_domain: hasDomain.value ? !!form.endpoint_use_domain : false,
    panel_port: String(form.panel_port || '').trim(),
    panel_https_port: String(form.panel_https_port || '').trim(),
    failure_webhook_url: String(form.failure_webhook_url || '').trim(),
    timezone: String(form.timezone || 'UTC').trim() || 'UTC'
  })
}

function markBaseline () {
  baseline.value = snapshotForm()
}

function buildTimezoneOptions (list) {
  const values = (Array.isArray(list) && list.length) ? list : ['UTC']
  return values.map((tz) => ({ label: tz, value: tz }))
}

function filterTimezones (val, update) {
  const all = buildTimezoneOptions(settingsStore.timezones)
  if (!val) {
    update(() => { timezoneOptions.value = all })
    return
  }
  const needle = String(val).toLowerCase()
  update(() => {
    timezoneOptions.value = all.filter((o) => o.label.toLowerCase().includes(needle))
  })
}

function applySettings (s) {
  if (s.server_endpoint !== undefined) form.server_endpoint = String(s.server_endpoint)
  if (s.panel_domain !== undefined) form.panel_domain = String(s.panel_domain ?? '')
  if (s.endpoint_use_domain !== undefined) form.endpoint_use_domain = asBool(s.endpoint_use_domain)
  if (s.panel_port !== undefined) form.panel_port = String(s.panel_port)
  if (s.panel_https_port !== undefined) form.panel_https_port = String(s.panel_https_port || '7443')
  if (s.failure_webhook_url !== undefined) form.failure_webhook_url = String(s.failure_webhook_url ?? '')
  if (s.timezone !== undefined) form.timezone = String(s.timezone || 'UTC')
  if (!String(form.panel_domain || '').trim()) form.endpoint_use_domain = false
  if (!form.panel_https_port) form.panel_https_port = '7443'
  timezoneOptions.value = buildTimezoneOptions(settingsStore.timezones)
  markBaseline()
}

watch(
  () => settingsStore.settings,
  (s) => {
    if (s && Object.keys(s).length) applySettings(s)
  },
  { immediate: true, deep: true }
)

watch(
  () => form.panel_domain,
  (value) => {
    if (!String(value || '').trim()) form.endpoint_use_domain = false
  }
)

watch(hasDomain, (ok) => {
  if (!ok && activeTab.value === 'https') activeTab.value = 'panel'
})

watch(
  () => ssl.value.email,
  (value) => {
    if (value && !sslEmail.value) sslEmail.value = value
  },
  { immediate: true }
)

async function loadTwoFactor () {
  twoFactorLoading.value = true
  try {
    const { data } = await api.get('/api/2fa/status')
    twoFactorEnabled.value = !!data.enabled
    if (!data.enabled && !data.pending) {
      twoFactorSetup.value = false
      twoFactorQr.value = ''
      twoFactorSecret.value = ''
    }
  } catch {
    // ignore
  } finally {
    twoFactorLoading.value = false
  }
}

async function startTwoFactorSetup () {
  twoFactorBusy.value = true
  try {
    const { data } = await api.post('/api/2fa/setup')
    twoFactorSetup.value = true
    twoFactorQr.value = data.qr || ''
    twoFactorSecret.value = data.secret || ''
    twoFactorConfirmCode.value = ''
    twoFactorEnabled.value = false
  } catch (e) {
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: e?.response?.data?.message || 'Не удалось начать настройку 2FA'
    })
  } finally {
    twoFactorBusy.value = false
  }
}

async function confirmTwoFactor () {
  twoFactorBusy.value = true
  try {
    await api.post('/api/2fa/confirm', { code: twoFactorConfirmCode.value })
    twoFactorEnabled.value = true
    twoFactorSetup.value = false
    twoFactorQr.value = ''
    twoFactorSecret.value = ''
    twoFactorConfirmCode.value = ''
    $q.notify({ type: 'positive', position: 'top-right', message: '2FA включена' })
  } catch (e) {
    const msg = e?.response?.data?.errors?.code?.[0] || e?.response?.data?.message || 'Неверный код'
    $q.notify({ type: 'negative', position: 'top-right', message: msg })
  } finally {
    twoFactorBusy.value = false
  }
}

function cancelTwoFactorSetup () {
  twoFactorSetup.value = false
  twoFactorQr.value = ''
  twoFactorSecret.value = ''
  twoFactorConfirmCode.value = ''
}

function openDisableTwoFactor () {
  twoFactorDisablePassword.value = ''
  twoFactorDisableCode.value = ''
  twoFactorDisableOpen.value = true
}

async function disableTwoFactor () {
  twoFactorBusy.value = true
  try {
    await api.delete('/api/2fa', {
      data: {
        password: twoFactorDisablePassword.value,
        code: twoFactorDisableCode.value
      }
    })
    twoFactorEnabled.value = false
    twoFactorDisableOpen.value = false
    twoFactorDisablePassword.value = ''
    twoFactorDisableCode.value = ''
    $q.notify({ type: 'positive', position: 'top-right', message: '2FA отключена' })
  } catch (e) {
    const errors = e?.response?.data?.errors
    const msg = errors?.password?.[0] || errors?.code?.[0] || e?.response?.data?.message || 'Не удалось отключить'
    $q.notify({ type: 'negative', position: 'top-right', message: msg })
  } finally {
    twoFactorBusy.value = false
  }
}

async function load () {
  await settingsStore.fetch(true)
  applySettings(settingsStore.settings)
  if (settingsStore.ssl?.email) sslEmail.value = settingsStore.ssl.email
  await loadTwoFactor()
}

async function save () {
  if (!isDirty.value) return
  saving.value = true
  domainError.value = ''
  try {
    const payload = {
      ...form,
      panel_domain: String(form.panel_domain || '').trim() || null,
      endpoint_use_domain: hasDomain.value ? !!form.endpoint_use_domain : false,
      panel_https_port: String(form.panel_https_port || '7443').trim() || '7443',
      timezone: String(form.timezone || 'UTC').trim() || 'UTC'
    }
    const { data } = await api.put('/api/settings', payload)
    settingsStore.applyResponse(data)
    applySettings(data.settings || {})
    $q.notify({ type: 'positive', position: 'top-right', message: 'Настройки сохранены' })
  } catch (e) {
    const errors = e?.response?.data?.errors
    const panelDomainMsg = errors?.panel_domain?.[0]
    if (panelDomainMsg) {
      domainError.value = panelDomainMsg
      form.panel_domain = ''
      form.endpoint_use_domain = false
    }
    const firstError = errors && Object.values(errors).flat().find(Boolean)
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: firstError || e?.response?.data?.message || 'Ошибка сохранения'
    })
  } finally {
    saving.value = false
  }
}

async function redirectToHttpsPanel (panelUrl) {
  const target = String(panelUrl || settingsStore.panelUrl || '').trim()
  if (!target.startsWith('https://')) return false
  if (window.location.href.startsWith(target)) return false
  $q.notify({
    type: 'positive',
    position: 'top-right',
    message: 'Переход на HTTPS…',
    timeout: 1500
  })
  const path = window.location.pathname + window.location.search + window.location.hash
  window.location.replace(target.replace(/\/$/, '') + (path.startsWith('/') ? path : '/'))
  return true
}

async function tryRecoverSslAndRedirect () {
  try {
    const data = await settingsStore.sslRecover()
    if (data?.ok) {
      $q.notify({
        type: 'positive',
        position: 'top-right',
        message: data.message || 'Сертификат подхвачен'
      })
      await redirectToHttpsPanel(data.panel_url)
      return true
    }
  } catch (_) {
    // no cert to recover
  }
  return false
}

async function issueSsl (renew) {
  const email = String(sslEmail.value || '').trim()
  if (!email) {
    $q.notify({ type: 'warning', position: 'top-right', message: 'Укажите email для Let\'s Encrypt' })
    return
  }
  sslIssuing.value = true
  try {
    const data = await settingsStore.sslIssueStart(email, renew)
    if (data?.redirect || data?.recovered) {
      $q.notify({
        type: 'positive',
        position: 'top-right',
        message: data.message || 'HTTPS включён'
      })
      await redirectToHttpsPanel(data.panel_url)
      return
    }
    $q.notify({
      type: 'info',
      position: 'top-right',
      message: data.message || 'Добавьте TXT-запись в DNS'
    })
  } catch (e) {
    const msg = e?.response?.data?.message || ''
    if (e?.response?.data?.redirect || e?.response?.data?.recovered) {
      settingsStore.applyResponse(e.response.data)
      await redirectToHttpsPanel(e.response.data.panel_url)
      return
    }
    if (msg.includes('Successfully received certificate')) {
      if (await tryRecoverSslAndRedirect()) return
    }
    if (await tryRecoverSslAndRedirect()) return
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: msg || 'Не удалось начать выпуск сертификата'
    })
  } finally {
    sslIssuing.value = false
  }
}

async function completeSsl () {
  sslCompleting.value = true
  try {
    const data = await settingsStore.sslIssueComplete()
    $q.notify({
      type: 'positive',
      position: 'top-right',
      message: data.message || 'Сертификат выпущен'
    })
    if (data?.redirect !== false) {
      await redirectToHttpsPanel(data.panel_url)
    }
  } catch (e) {
    const body = e?.response?.data
    if (body?.ok && (body.redirect || body.recovered)) {
      settingsStore.applyResponse(body)
      $q.notify({
        type: 'positive',
        position: 'top-right',
        message: body.message || 'Сертификат подхвачен'
      })
      await redirectToHttpsPanel(body.panel_url)
      return
    }
    if (await tryRecoverSslAndRedirect()) return
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: body?.message || 'Не удалось завершить выпуск'
    })
  } finally {
    sslCompleting.value = false
  }
}

async function abortSsl () {
  try {
    await settingsStore.sslAbort()
    $q.notify({ type: 'info', position: 'top-right', message: 'Выпуск отменён' })
  } catch (e) {
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: e?.response?.data?.message || 'Не удалось отменить'
    })
  }
}

async function disableSsl () {
  sslDisabling.value = true
  try {
    const data = await settingsStore.sslDisable()
    $q.notify({
      type: 'positive',
      position: 'top-right',
      message: data.message || 'HTTPS отключён'
    })
  } catch (e) {
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: e?.response?.data?.message || 'Не удалось отключить HTTPS'
    })
  } finally {
    sslDisabling.value = false
  }
}

async function testWebhook () {
  testing.value = true
  try {
    const { data } = await api.post('/api/settings/test-webhook')
    $q.notify({
      type: data.ok ? 'positive' : 'warning',
      position: 'top-right',
      message: data.ok ? 'Тестовый webhook отправлен' : `Webhook не удался (код ${data.exit_code})`
    })
  } catch (e) {
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: e?.response?.data?.message || 'Тест не удался'
    })
  } finally {
    testing.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.settings-card {
  overflow: hidden;
}
.settings-tabs {
  padding: 0 8px;
}
.settings-tabs :deep(.q-tab) {
  min-height: 44px;
  text-transform: none;
}
.settings-panels {
  background: transparent;
}
.settings-panels :deep(.q-tab-panel) {
  background: transparent;
}
.mono {
  font-family: var(--theme-mono);
  font-size: 12px;
}
.schema-pre {
  white-space: pre-wrap;
  background: var(--surface-bg);
  padding: 12px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
}
.challenge-box {
  padding: 12px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
  background: var(--surface-bg);
}
.twofa-qr {
  width: 160px;
  height: 160px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
  background: #fff;
}
</style>
