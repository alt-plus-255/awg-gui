import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { Preferences } from '@capacitor/preferences'

const STORAGE_KEY = 'awg-mobile-connection'

export const useConnectionStore = defineStore('connection', () => {
  const host = ref('')
  const sshPort = ref(22)
  const username = ref('root')
  const password = ref('')
  const skipKernel = ref(false)
  const panelPort = ref(8877)
  const panelUsername = ref('admin')
  const panelPassword = ref('')
  const panelUrl = ref('')
  const hydrated = ref(false)

  const panelBaseUrl = computed(() => {
    if (panelUrl.value) return panelUrl.value.replace(/\/$/, '')
    if (!host.value) return ''
    return `http://${host.value}:${panelPort.value}`
  })

  const hasPanel = computed(() => Boolean(host.value && panelPassword.value && panelBaseUrl.value))

  async function hydrate () {
    if (hydrated.value) return
    try {
      const { value } = await Preferences.get({ key: STORAGE_KEY })
      if (value) {
        const data = JSON.parse(value)
        host.value = data.host || ''
        sshPort.value = Number(data.sshPort) || 22
        username.value = data.username || 'root'
        password.value = data.password || ''
        skipKernel.value = Boolean(data.skipKernel)
        panelPort.value = Number(data.panelPort) || 8877
        panelUsername.value = data.panelUsername || 'admin'
        panelPassword.value = data.panelPassword || ''
        panelUrl.value = data.panelUrl || ''
      }
    } catch {
      // ignore
    } finally {
      hydrated.value = true
    }
  }

  async function persist () {
    const payload = {
      host: host.value,
      sshPort: sshPort.value,
      username: username.value,
      password: password.value,
      skipKernel: skipKernel.value,
      panelPort: panelPort.value,
      panelUsername: panelUsername.value,
      panelPassword: panelPassword.value,
      panelUrl: panelUrl.value
    }
    await Preferences.set({ key: STORAGE_KEY, value: JSON.stringify(payload) })
  }

  async function setInstallForm (form) {
    host.value = form.host.trim()
    sshPort.value = Number(form.sshPort) || 22
    username.value = form.username.trim() || 'root'
    password.value = form.password
    skipKernel.value = Boolean(form.skipKernel)
    await persist()
  }

  async function setPanelCredentials ({ panelPort: port, username: user, password: pass, url }) {
    if (port) panelPort.value = Number(port) || 8877
    if (user) panelUsername.value = user
    if (pass) panelPassword.value = pass
    if (url) panelUrl.value = url
    await persist()
  }

  async function clear () {
    host.value = ''
    sshPort.value = 22
    username.value = 'root'
    password.value = ''
    skipKernel.value = false
    panelPort.value = 8877
    panelUsername.value = 'admin'
    panelPassword.value = ''
    panelUrl.value = ''
    await Preferences.remove({ key: STORAGE_KEY })
  }

  return {
    host,
    sshPort,
    username,
    password,
    skipKernel,
    panelPort,
    panelUsername,
    panelPassword,
    panelUrl,
    panelBaseUrl,
    hasPanel,
    hydrated,
    hydrate,
    persist,
    setInstallForm,
    setPanelCredentials,
    clear
  }
})
