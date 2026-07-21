<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md">
        <div class="text-h5 col">{{ t('settings.title') }}</div>
        <q-btn
          v-if="isDirty"
          color="primary"
          :label="t('common.save')"
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
          <q-tab name="general" :label="t('settings.tabGeneral')" />
          <q-tab name="panel" :label="t('settings.tabPanel')" />
          <q-tab v-if="hasDomain" name="https" label="HTTPS" />
          <q-tab name="twofa" label="2FA" />
        </q-tabs>

        <q-separator />

        <q-form>
          <q-tab-panels v-model="activeTab" animated class="settings-panels">
            <q-tab-panel name="general" class="q-pa-md">
              <div class="text-subtitle2 q-mb-sm">{{ t('settings.appearance') }}</div>

              <div class="text-caption text-soft-theme q-mb-xs">{{ t('theme.theme') }}</div>
              <q-option-group
                :model-value="theme.colorMode"
                :options="colorModeOptions"
                type="radio"
                color="primary"
                @update:model-value="theme.setColorMode"
              />

              <div v-if="theme.colorMode === 'auto'" class="row q-col-gutter-sm q-mt-sm q-mb-md">
                <div class="col-12 col-sm-6">
                  <q-input
                    :model-value="theme.autoHours.from"
                    type="time"
                    :label="t('theme.lightFrom')"
                    filled
                    @update:model-value="onAutoFrom"
                  />
                </div>
                <div class="col-12 col-sm-6">
                  <q-input
                    :model-value="theme.autoHours.to"
                    type="time"
                    :label="t('theme.lightUntil')"
                    filled
                    @update:model-value="onAutoTo"
                  />
                </div>
              </div>

              <div class="text-caption text-soft-theme q-mb-xs q-mt-md">{{ t('theme.style') }}</div>
              <q-option-group
                :model-value="theme.styleId"
                :options="theme.styleOptions"
                type="radio"
                color="primary"
                @update:model-value="theme.setStyle"
              />

              <div class="text-caption text-soft-theme q-mb-xs q-mt-md">{{ t('theme.language') }}</div>
              <q-option-group
                :model-value="localeStore.locale"
                :options="localeStore.localeOptions"
                type="radio"
                color="primary"
                @update:model-value="localeStore.setLocale"
              />

              <q-separator class="q-my-md" />

              <div class="text-subtitle2 q-mb-sm">{{ t('settings.sounds') }}</div>
              <q-toggle
                :model-value="sounds.enabled"
                :label="t('settings.uiSounds')"
                color="primary"

                @update:model-value="sounds.setEnabled"
              />

              <q-separator class="q-my-md" />

              <div class="text-subtitle2 q-mb-sm">{{ t('settings.failureAlert') }}</div>
              <q-input
                v-model="form.failure_webhook_url"
                :label="t('settings.webhookUrl')"
                :hint="t('settings.webhookUrlHint')"

                filled
                class="q-mb-md"
              />
              <div class="row q-gutter-sm">
                <q-btn outline color="accent" :label="t('settings.jsonSchema')" @click="schemaOpen = true" />
                <q-btn outline color="primary" :label="t('settings.sendTest')" :loading="testing" @click="testWebhook" />
              </div>
            </q-tab-panel>

            <q-tab-panel name="panel" class="q-pa-md">
              <div class="row q-col-gutter-md">
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.server_endpoint"
                    :label="t('settings.publicIp')"
                    :hint="t('settings.publicIpHint')"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_domain"
                    :label="t('settings.panelDomain')"
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
                    :label="t('settings.httpPort')"
                    :hint="t('settings.httpPortHint')"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_https_port"
                    :label="t('settings.httpsPort')"
                    :hint="t('settings.httpsPortHint')"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-select
                    v-model="form.timezone"
                    :options="timezoneOptions"
                    :label="t('settings.timezone')"
                    :hint="t('settings.timezoneHint')"

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
                    :label="t('settings.useDomainInEndpoint')"
                    color="primary"

                  />
                  <div class="text-caption text-grey-5 q-mt-xs">
                    {{ t('settings.inClientConfigs') }}
                    <span class="mono">Endpoint = {{ endpointHostPreview }}:&lt;listen_port&gt;</span>
                  </div>
                </div>
              </div>
              <div class="text-caption text-grey-5 q-mt-sm">
                {{ t('settings.panelUrl') }} <span class="mono">{{ panelUrl || '—' }}</span>
              </div>
              <div class="text-caption text-grey-5 q-mt-xs">
                {{ t('settings.awgConfigsHint') }}
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
                    :label="t('settings.letsEncryptEmail')"
                    :hint="t('settings.letsEncryptEmailHint')"

                    filled
                    type="email"
                    :disable="sslBusy"
                  />
                </div>
                <div class="col-12 col-md-6">
                  <div class="text-caption text-grey-5">{{ t('settings.certStatus') }}</div>
                  <div class="text-body2">{{ sslStatusLabel }}</div>
                  <div v-if="ssl.expires_at" class="text-caption text-grey-5 q-mt-xs">
                    {{ t('settings.validUntil') }} <span class="mono">{{ ssl.expires_at }}</span>
                  </div>
                  <div v-if="ssl.error" class="text-negative text-caption q-mt-xs">
                    {{ ssl.error }}
                  </div>
                </div>
              </div>

              <div v-if="activeChallenge" class="challenge-box q-mb-md">
                <div class="text-subtitle2 q-mb-sm">{{ t('settings.addTxtRecord') }}</div>
                <div class="text-caption text-grey-5 q-mb-xs">{{ t('settings.txtName') }}</div>
                <div class="mono q-mb-sm">{{ activeChallenge.txt_name }}</div>
                <div class="text-caption text-grey-5 q-mb-xs">{{ t('settings.txtValue') }}</div>
                <div class="mono q-mb-md" style="word-break: break-all;">{{ activeChallenge.txt_value }}</div>
                <div class="row q-gutter-sm">
                  <q-btn
                    color="primary"
                    :label="t('settings.continueAfterTxt')"
                    :loading="sslCompleting"
                    :disable="sslBusy && !sslCompleting"
                    @click="completeSsl"
                  />
                  <q-btn
                    flat
                    color="grey-5"
                    :label="t('settings.abort')"
                    :disable="sslBusy && !sslIssuing"
                    @click="abortSsl"
                  />
                </div>
              </div>

              <div class="row q-gutter-sm">
                <q-btn
                  v-if="!ssl.enabled"
                  color="primary"
                  :label="t('settings.issueCert')"
                  :loading="sslIssuing"
                  :disable="sslBusy || !!activeChallenge"
                  @click="issueSsl(false)"
                />
                <q-btn
                  v-if="ssl.enabled"
                  outline
                  color="primary"
                  :label="t('settings.renewCert')"
                  :loading="sslIssuing"
                  :disable="sslBusy || !!activeChallenge"
                  @click="issueSsl(true)"
                />
                <q-btn
                  v-if="ssl.enabled"
                  outline
                  color="negative"
                  :label="t('settings.disableHttps')"
                  :loading="sslDisabling"
                  :disable="sslBusy"
                  @click="disableSsl"
                />
              </div>
            </q-tab-panel>

            <q-tab-panel name="twofa" class="q-pa-md">
              <div class="text-caption text-grey-5 q-mb-md">
                {{ t('settings.twoFactorHint') }}
                 <span class="mono">awg-gui 2fa disable</span>
              </div>

              <div class="row items-center q-gutter-sm q-mb-md">
                <q-badge :color="twoFactorEnabled ? 'positive' : 'grey-7'">
                  {{ twoFactorEnabled ? t('settings.twoFactorOn') : t('settings.twoFactorOff') }}
                </q-badge>
                <q-spinner v-if="twoFactorLoading" size="20px" color="primary" />
              </div>

              <div v-if="!twoFactorEnabled && !twoFactorSetup" class="row q-gutter-sm">
                <q-btn
                  color="primary"
                  :label="t('settings.enable2fa')"
                  :loading="twoFactorBusy"
                  @click="startTwoFactorSetup"
                />
              </div>

              <div v-if="twoFactorSetup" class="q-mb-md">
                <div class="text-caption text-grey-5 q-mb-sm">
                  {{ t('settings.scanQrHint') }}
                </div>
                <div class="row q-gutter-md items-start q-mb-md">
                  <img v-if="twoFactorQr" :src="twoFactorQr" alt="QR 2FA" class="twofa-qr" />
                  <div class="col">
                    <div class="text-caption text-grey-5">{{ t('settings.secret') }}</div>
                    <div class="mono q-mb-sm" style="word-break: break-all;">{{ twoFactorSecret }}</div>
                  </div>
                </div>
                <q-input
                  v-model="twoFactorConfirmCode"
                  :label="t('settings.confirmCode')"

                  filled
                  maxlength="6"
                  inputmode="numeric"
                  class="q-mb-md"
                  style="max-width: 240px;"
                />
                <div class="row q-gutter-sm">
                  <q-btn
                    color="primary"
                    :label="t('settings.confirm')"
                    :loading="twoFactorBusy"
                    @click="confirmTwoFactor"
                  />
                  <q-btn flat color="grey-5" :label="t('common.cancel')" :disable="twoFactorBusy" @click="cancelTwoFactorSetup" />
                </div>
              </div>

              <div v-if="twoFactorEnabled">
                <q-btn
                  outline
                  color="negative"
                  :label="t('settings.disable2fa')"
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
        <DialogHeader :title="t('settings.disable2fa')" />
        <q-card-section class="col dialog-scroll-body">
          <div class="text-caption text-grey-5 q-mb-md">
            {{ t('settings.disable2faHint') }}
          </div>
          <q-input
            v-model="twoFactorDisablePassword"
            :label="t('settings.password')"
            type="password"

            filled
            class="q-mb-md"
            autocomplete="current-password"
            :disable="twoFactorBusy"
          />
          <q-input
            v-model="twoFactorDisableCode"
            :label="t('settings.twoFactorCode')"

            filled
            maxlength="6"
            inputmode="numeric"
            autocomplete="one-time-code"
            :disable="twoFactorBusy"
            @keyup.enter="disableTwoFactor"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat :label="t('common.cancel')" color="grey-5" :disable="twoFactorBusy" v-close-popup />
          <q-btn
            color="negative"
            :label="t('settings.disable')"
            :loading="twoFactorBusy"
            @click="disableTwoFactor"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="schemaOpen" v-bind="mobileDialog">
      <q-card style="width: min(720px, 95vw); max-width: 95vw;" class="surface-panel dialog-card column no-wrap">
        <DialogHeader :title="t('settings.webhookSchemaTitle')" />
        <q-card-section class="col dialog-scroll-body">
          <q-markup-table flat dense class="q-mb-md">
            <thead>
              <tr>
                <th class="text-left">{{ t('settings.field') }}</th>
                <th class="text-left">{{ t('settings.description') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(desc, key) in schema.fields || {}" :key="key">
                <td class="mono">{{ key }}</td>
                <td>{{ desc }}</td>
              </tr>
            </tbody>
          </q-markup-table>
          <div class="text-caption q-mb-sm">{{ t('settings.payloadExample') }}</div>
          <pre class="mono schema-pre">{{ JSON.stringify(schema.example || {}, null, 2) }}</pre>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn v-if="$q.screen.gt.sm" flat :label="t('common.close')" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import { useThemeStore } from '@/stores/theme'
import { useLocaleStore } from '@/stores/locale'
import { useSettingsStore } from '@/stores/settings'
import { useSoundStore } from '@/sounds/store'
import { useMobileDialog } from '@/composables/useMobileDialog'
import DialogHeader from '@/components/DialogHeader.vue'
import { COLOR_MODES } from '@/themes/themes'

const $q = useQuasar()
const { t } = useI18n()
const mobileDialog = useMobileDialog()
const theme = useThemeStore()
const localeStore = useLocaleStore()
const sounds = useSoundStore()

const colorModeOptions = computed(() =>
  COLOR_MODES.map((value) => ({ value, label: t(`theme.${value}`) }))
)

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
    return t('settings.panelDomainHint')
  }
  return t('settings.panelDomainDnsHint')
})

const sslHint = computed(() => {
  const port = String(form.panel_https_port || ssl.value.https_port || '7443').trim() || '7443'
  const base = ssl.value.hint || t('settings.acmeTxtHint')
  return `${base} ${t('settings.httpsAccessHint', { port })}`
})

const sslStatusLabel = computed(() => {
  const map = {
    disabled: t('settings.httpsDisabled'),
    pending: t('settings.waitingTxt'),
    active: t('settings.httpsActive'),
    error: t('settings.httpsError')
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
      message: e?.response?.data?.message || t('settings.twoFactorSetupError')
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
    $q.notify({ type: 'positive', position: 'top-right', message: t('settings.twoFactorEnabled') })
  } catch (e) {
    const msg = e?.response?.data?.errors?.code?.[0] || e?.response?.data?.message || t('settings.invalidCode')
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
    $q.notify({ type: 'positive', position: 'top-right', message: t('settings.twoFactorDisabled') })
  } catch (e) {
    const errors = e?.response?.data?.errors
    const msg = errors?.password?.[0] || errors?.code?.[0] || e?.response?.data?.message || t('settings.disableFailed')
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
    $q.notify({ type: 'positive', position: 'top-right', message: t('settings.settingsSaved') })
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
      message: firstError || e?.response?.data?.message || t('settings.saveError')
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
    message: t('settings.switchingToHttps'),
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
        message: data.message || t('settings.certPickedUp')
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
    $q.notify({ type: 'warning', position: 'top-right', message: t('settings.specifyLetsEncryptEmail') })
    return
  }
  sslIssuing.value = true
  try {
    const data = await settingsStore.sslIssueStart(email, renew)
    if (data?.redirect || data?.recovered) {
      $q.notify({
        type: 'positive',
        position: 'top-right',
        message: data.message || t('settings.httpsEnabledNotify')
      })
      await redirectToHttpsPanel(data.panel_url)
      return
    }
    $q.notify({
      type: 'info',
      position: 'top-right',
      message: data.message || t('settings.addTxtNotify')
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
      message: msg || t('settings.certIssueStartError')
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
      message: data.message || t('settings.certIssued')
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
        message: body.message || t('settings.certPickedUp')
      })
      await redirectToHttpsPanel(body.panel_url)
      return
    }
    if (await tryRecoverSslAndRedirect()) return
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: body?.message || t('settings.certFinishError')
    })
  } finally {
    sslCompleting.value = false
  }
}

async function abortSsl () {
  try {
    await settingsStore.sslAbort()
    $q.notify({ type: 'info', position: 'top-right', message: t('settings.issueCancelled') })
  } catch (e) {
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: e?.response?.data?.message || t('settings.cancelFailed')
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
      message: data.message || t('settings.httpsDisabledNotify')
    })
  } catch (e) {
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: e?.response?.data?.message || t('settings.disableHttpsError')
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
      message: data.ok ? t('settings.webhookTestSent') : t('settings.webhookTestFailedCode', { code: data.exit_code })
    })
  } catch (e) {
    $q.notify({
      type: 'negative',
      position: 'top-right',
      message: e?.response?.data?.message || t('settings.testFailed')
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
