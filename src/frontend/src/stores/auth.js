import { defineStore } from 'pinia'
import { ref } from 'vue'
import api, { ensureCsrf } from '@/boot/axios'

const AUTH_CACHE_KEY = 'awg-auth-user'

function writeCachedUser (user) {
  try {
    if (user) {
      sessionStorage.setItem(AUTH_CACHE_KEY, JSON.stringify(user))
    } else {
      sessionStorage.removeItem(AUTH_CACHE_KEY)
    }
  } catch {
    // ignore
  }
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const checked = ref(false)
  const checking = ref(false)
  let inFlight = null

  async function fetchMe () {
    if (inFlight) return inFlight

    checking.value = true
    inFlight = (async () => {
      try {
        const { data } = await api.get('/api/me')
        user.value = data.user
        writeCachedUser(data.user)
      } catch {
        user.value = null
        writeCachedUser(null)
      } finally {
        checked.value = true
        checking.value = false
        inFlight = null
      }
    })()

    return inFlight
  }

  async function loginStatus () {
    const { data } = await api.get('/api/login/status')
    return data
  }

  async function loginInfo () {
    const { data } = await api.get('/api/login/info')
    return data
  }

  async function fetchCaptcha () {
    const { data } = await api.get('/api/login/captcha')
    return data
  }

  async function login (username, password, extras = {}) {
    await ensureCsrf()
    const payload = {
      username,
      password,
      ...extras
    }
    const { data } = await api.post('/api/login', payload)
    user.value = data.user
    checked.value = true
    writeCachedUser(data.user)
    return data
  }

  function clearSession () {
    user.value = null
    checked.value = true
    writeCachedUser(null)
  }

  async function logout () {
    try {
      await api.post('/api/logout')
    } finally {
      clearSession()
    }
  }

  return {
    user,
    checked,
    checking,
    fetchMe,
    loginStatus,
    loginInfo,
    fetchCaptcha,
    login,
    logout,
    clearSession
  }
})
