import axios from 'axios'

const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
})

let csrfReady = false

export async function ensureCsrf () {
  if (csrfReady) return
  await api.get('/sanctum/csrf-cookie')
  csrfReady = true
}

api.interceptors.request.use(async (config) => {
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

    return Promise.reject(error)
  }
)

export default api
