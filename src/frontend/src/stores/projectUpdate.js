import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'

/** Keep polling briefly after start even if one status reply looks idle (runner race). */
const START_GRACE_MS = 20000

export const useProjectUpdateStore = defineStore('projectUpdate', () => {
  const loading = ref(false)
  const checking = ref(false)
  const starting = ref(false)
  const clearingLog = ref(false)
  const downloadingLog = ref(false)
  const retryingStuck = ref(false)
  const reinstalling = ref(false)

  const current_version = ref(null)
  const latest_version = ref(null)
  const update_available = ref(false)
  const release_checked_at = ref(null)
  const release_check_error = ref(null)
  const installed_at = ref(null)
  const can_update = ref(false)
  const can_reinstall = ref(false)
  const status = ref('idle')
  const running = ref(false)
  const stuck = ref(false)
  const can_retry_stuck = ref(false)
  const can_clear_log = ref(true)
  const started_at = ref(null)
  const finished_at = ref(null)
  const message = ref('')
  const log_tail = ref('')

  let pollTimer = null
  let localStartMs = null

  const busy = computed(() =>
    loading.value || checking.value || starting.value || clearingLog.value || retryingStuck.value || reinstalling.value || running.value
  )

  function inStartGrace () {
    return localStartMs != null && (Date.now() - localStartMs) < START_GRACE_MS
  }

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
    can_reinstall.value = !!data.can_reinstall

    const nextStatus = data.status || 'idle'
    const nextRunning = !!data.running

    // After start, a too-early status poll can briefly report idle before update.state exists.
    // Keep running + refresh the log so the journal appears without a page reload.
    if (
      inStartGrace()
      && !nextRunning
      && nextStatus !== 'success'
      && nextStatus !== 'failed'
    ) {
      if (data.log_tail != null && data.log_tail !== '') {
        log_tail.value = data.log_tail
      }
      if (data.message) {
        message.value = data.message
      }
      if (data.started_at) {
        started_at.value = data.started_at
      }
      running.value = true
      status.value = 'running'
      can_clear_log.value = false
      return
    }

    if (nextRunning || nextStatus === 'success' || nextStatus === 'failed') {
      localStartMs = null
    }

    status.value = nextStatus
    running.value = nextRunning
    stuck.value = !!data.stuck
    can_retry_stuck.value = !!data.can_retry_stuck
    can_clear_log.value = data.can_clear_log !== false
    started_at.value = data.started_at ?? null
    finished_at.value = data.finished_at ?? null
    message.value = data.message || ''
    log_tail.value = data.log_tail ?? ''
  }

  function schedulePoll (delayMs = 3000) {
    if (pollTimer) clearTimeout(pollTimer)
    const auth = useAuthStore()
    if (!auth.user || (!running.value && !inStartGrace())) {
      pollTimer = null
      return
    }
    pollTimer = setTimeout(() => {
      void fetchStatus({ silent: true })
    }, delayMs)
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
      localStartMs = Date.now()
      applyPayload(data)
      return data
    } finally {
      starting.value = false
      // First poll sooner so the journal shows up without waiting a full 3s / refresh.
      schedulePoll(800)
    }
  }

  async function clearLog () {
    clearingLog.value = true
    try {
      const { data } = await api.post('/api/settings/update/clear-log')
      applyPayload(data, { preserveRelease: true })
      return data
    } finally {
      clearingLog.value = false
      schedulePoll()
    }
  }

  async function downloadLog () {
    downloadingLog.value = true
    try {
      const { data } = await api.get('/api/settings/update/log', { responseType: 'blob' })
      const blob = data instanceof Blob ? data : new Blob([data], { type: 'text/plain;charset=utf-8' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = 'awg-gui-update.log'
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
      return true
    } finally {
      downloadingLog.value = false
    }
  }

  async function retryStuck () {
    retryingStuck.value = true
    try {
      const { data } = await api.post('/api/settings/update/retry-stuck')
      localStartMs = Date.now()
      applyPayload(data)
      return data
    } finally {
      retryingStuck.value = false
      schedulePoll(800)
    }
  }

  async function reinstallCurrent () {
    reinstalling.value = true
    try {
      const { data } = await api.post('/api/settings/update/reinstall')
      localStartMs = Date.now()
      applyPayload(data)
      return data
    } finally {
      reinstalling.value = false
      schedulePoll(800)
    }
  }

  return {
    loading,
    checking,
    starting,
    clearingLog,
    downloadingLog,
    retryingStuck,
    reinstalling,
    busy,
    current_version,
    latest_version,
    update_available,
    release_checked_at,
    release_check_error,
    installed_at,
    can_update,
    can_reinstall,
    status,
    running,
    stuck,
    can_retry_stuck,
    can_clear_log,
    started_at,
    finished_at,
    message,
    log_tail,
    fetchStatus,
    checkForUpdates,
    startUpdate,
    clearLog,
    downloadLog,
    retryStuck,
    reinstallCurrent,
    stopPoll
  }
})
