import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/boot/axios'

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref({})
  const displayEndpoint = ref('')
  const panelUrl = ref('')
  const ssl = ref({
    enabled: false,
    email: '',
    status: 'disabled',
    error: '',
    expires_at: null,
    domain: '',
    https_port: '7443',
    challenge: null,
    hint: ''
  })
  const webhookSchema = ref({})
  const timezones = ref(['UTC'])
  const loaded = ref(false)
  const loading = ref(false)
  let inFlight = null

  const serverEndpoint = computed(() => settings.value.server_endpoint ?? '')

  function applySsl (payload) {
    if (!payload || typeof payload !== 'object') return
    ssl.value = {
      enabled: !!payload.enabled,
      email: payload.email || '',
      status: payload.status || 'disabled',
      error: payload.error || '',
      expires_at: payload.expires_at || null,
      domain: payload.domain || '',
      https_port: String(payload.https_port || '7443'),
      challenge: payload.challenge || null,
      hint: payload.hint || ssl.value.hint || ''
    }
  }

  async function fetch (force = false) {
    if (loaded.value && !force) return settings.value
    if (inFlight && !force) return inFlight

    loading.value = true
    inFlight = api.get('/api/settings')
      .then(({ data }) => {
        settings.value = data.settings || {}
        displayEndpoint.value = data.display_endpoint || data.settings?.server_endpoint || ''
        panelUrl.value = data.panel_url || ''
        webhookSchema.value = data.webhook_schema || {}
        applySsl(data.ssl)
        if (Array.isArray(data.timezones) && data.timezones.length) {
          timezones.value = data.timezones
        }
        loaded.value = true
        return settings.value
      })
      .finally(() => {
        loading.value = false
        inFlight = null
      })

    return inFlight
  }

  function applyLocal (partial) {
    settings.value = { ...settings.value, ...partial }
  }

  function applyResponse (data) {
    if (data.settings) settings.value = data.settings
    if (data.display_endpoint != null) displayEndpoint.value = data.display_endpoint
    if (data.panel_url != null) panelUrl.value = data.panel_url
    if (data.ssl) applySsl(data.ssl)
    if (Array.isArray(data.timezones) && data.timezones.length) {
      timezones.value = data.timezones
    }
  }

  async function sslIssueStart (email, renew = false) {
    const { data } = await api.post('/api/settings/ssl/issue/start', { email, renew })
    applyResponse(data)
    applySsl(data.ssl)
    return data
  }

  async function sslIssueComplete () {
    const { data } = await api.post('/api/settings/ssl/issue/complete')
    applyResponse(data)
    applySsl(data.ssl)
    return data
  }

  async function sslRecover () {
    const { data } = await api.post('/api/settings/ssl/recover')
    applyResponse(data)
    applySsl(data.ssl)
    return data
  }

  async function sslDisable () {
    const { data } = await api.post('/api/settings/ssl/disable')
    applyResponse(data)
    applySsl(data.ssl)
    return data
  }

  async function sslAbort () {
    const { data } = await api.post('/api/settings/ssl/abort')
    applyResponse(data)
    applySsl(data.ssl)
    return data
  }

  return {
    settings,
    displayEndpoint,
    panelUrl,
    ssl,
    webhookSchema,
    timezones,
    loaded,
    loading,
    serverEndpoint,
    fetch,
    applyLocal,
    applyResponse,
    sslIssueStart,
    sslIssueComplete,
    sslRecover,
    sslDisable,
    sslAbort
  }
})
