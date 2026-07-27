import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/boot/axios'

export const useProjectUpdateStore = defineStore('projectUpdate', () => {
  const loading = ref(false)
  const checking = ref(false)
  const starting = ref(false)

  const current_version = ref(null)
  const latest_version = ref(null)
  const update_available = ref(false)
  const release_checked_at = ref(null)
  const release_check_error = ref(null)
  const installed_at = ref(null)
  const can_update = ref(false)
  const status = ref('idle')
  const running = ref(false)
  const started_at = ref(null)
  const finished_at = ref(null)
  const message = ref('')
  const log_tail = ref('')

  let pollTimer = null

  const busy = computed(() => loading.value || checking.value || starting.value || running.value)

  function applyPayload (data, opts = {}) {
    if (!data || typeof data !== 'object') return
    const preserveRelease = opts.preserveRelease === true

    current_version.value = data.current_version ?? null
    latest_version.value = preserveRelease && data.latest_version == null
      ? latest_version.value
      : (data.latest_version ?? null)
    update_available.value = preserveRelease && data.release_checked_at == null
      ? update_available.value
      : !!data.update_available
    release_checked_at.value = preserveRelease && data.release_checked_at == null
      ? release_checked_at.value
      : (data.release_checked_at ?? null)
    release_check_error.value = preserveRelease && data.release_check_error == null
      ? release_check_error.value
      : (data.release_check_error ?? null)
    installed_at.value = data.installed_at ?? null
    can_update.value = !!data.can_update
    status.value = data.status || 'idle'
    running.value = !!data.running
    started_at.value = data.started_at ?? null
    finished_at.value = data.finished_at ?? null
    message.value = data.message || ''
    log_tail.value = data.log_tail ?? ''
  }

  function schedulePoll () {
    if (pollTimer) clearTimeout(pollTimer)
    if (!running.value) return
    pollTimer = setTimeout(() => {
      void fetchStatus({ silent: true })
    }, 3000)
  }

  function stopPoll () {
    if (pollTimer) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  async function fetchStatus (opts = {}) {
    const silent = opts.silent === true
    if (!silent) loading.value = true
    try {
      const { data } = await api.get('/api/settings/update-status')
      applyPayload(data, { preserveRelease: silent })
      return data
    } finally {
      if (!silent) loading.value = false
      schedulePoll()
    }
  }

  async function checkForUpdates (opts = {}) {
    const silent = opts.silent === true
    if (!silent) checking.value = true
    try {
      const { data } = await api.post('/api/settings/check-updates')
      applyPayload(data)
      return data
    } finally {
      if (!silent) checking.value = false
      schedulePoll()
    }
  }

  async function startUpdate () {
    starting.value = true
    try {
      const { data } = await api.post('/api/settings/update')
      applyPayload(data)
      return data
    } finally {
      starting.value = false
      schedulePoll()
    }
  }

  return {
    loading,
    checking,
    starting,
    busy,
    current_version,
    latest_version,
    update_available,
    release_checked_at,
    release_check_error,
    installed_at,
    can_update,
    status,
    running,
    started_at,
    finished_at,
    message,
    log_tail,
    fetchStatus,
    checkForUpdates,
    startUpdate,
    stopPoll
  }
})
