import axios from 'axios'
import { DEFAULT_LOCALE, LOCALE_STORAGE_KEY, isValidLocale } from '@/i18n'
import { logApiErrorDebug, getApiDebugMessage } from '@/utils/apiError'

const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
})

let csrfReady = false

function currentLocale () {
  try {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
    if (isValidLocale(stored)) return stored
  } catch {
    // ignore
  }
  return DEFAULT_LOCALE
}

function isPublicApiUrl (url) {
  return url.includes('/sanctum/')
    || url.includes('/api/me')
    || url.includes('/api/login')
}

function isProtectedApiUrl (url) {
  return url.includes('/api/') && !isPublicApiUrl(url)
}

export function createAuthSkippedError (config) {
  const error = new Error('Request skipped: not authenticated')
  error.config = config
  error.code = 'ERR_AUTH_SKIPPED'
  error.isAuthSkipped = true
  return error
}

export async function ensureCsrf () {
  if (csrfReady) return
  await api.get('/sanctum/csrf-cookie')
  csrfReady = true
}

api.interceptors.request.use(async (config) => {
  config.headers['Accept-Language'] = currentLocale()

  const url = String(config.url || '')
  if (isProtectedApiUrl(url)) {
    const { useAuthStore } = await import('@/stores/auth')
    const auth = useAuthStore()
    // After session check, never hit authenticated APIs while logged out.
    if (auth.checked && !auth.user) {
      return Promise.reject(createAuthSkippedError(config))
    }
  }

  if (['post', 'put', 'patch', 'delete'].includes((config.method || '').toLowerCase())) {
    await ensureCsrf()
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    if (match) {
      config.headers['X-XSRF-TOKEN'] = decodeURIComponent(match[1])
    }
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error?.isAuthSkipped) {
      return Promise.reject(error)
    }

    const status = error.response?.status
    const url = String(error.config?.url || '')

    if (status === 401 && !url.includes('/api/login')) {
      const { useAuthStore } = await import('@/stores/auth')
      const auth = useAuthStore()
      auth.clearSession()

      // Router guard owns redirect for /api/me; avoid nested navigation.
      if (!url.includes('/api/me')) {
        const router = (await import('@/router')).default
        const current = router.currentRoute.value
        if (current.name !== 'login') {
          await router.replace({
            name: 'login',
            query: { redirect: current.fullPath }
          })
        }
      }
    }

    if (status >= 500) {
      logApiErrorDebug(error)
      const debugMsg = getApiDebugMessage(error)
      if (debugMsg) {
        const { Notify } = await import('quasar')
        const { translate } = await import('@/stores/locale')
        Notify.create({
          type: 'negative',
          message: `${translate('common.serverError')} · ${debugMsg}`,
          caption: String(error.config?.url || ''),
          timeout: 12000,
          actions: [{ icon: 'close', color: 'white', flat: true }],
        })
      }
    }

    return Promise.reject(error)
  }
)

export default api
