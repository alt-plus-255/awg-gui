import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/boot/axios'
import { useAuthStore } from '@/stores/auth'

export const useAwgKernelStore = defineStore('awgKernel', () => {
  const loading = ref(false)
  const starting = ref(false)

  const module_loaded = ref(false)
  const package_installed = ref(false)
  const module_blacklisted = ref(false)
  const kernel_path_broken = ref(false)
  const awg_datapath = ref('unknown')
  const os_family = ref('unknown')
  const script_present = ref(false)
  const detail = ref('')
  const op_status = ref('idle')
  const op_message = ref('')
  const op_name = ref('')
  const running = ref(false)
  const error = ref('')

  let pollTimer = null

  const busy = computed(() => loading.value || starting.value || running.value)

  function applyPayload (data) {
    if (!data || typeof data !== 'object') return
    module_loaded.value = !!data.module_loaded
    package_installed.value = !!data.package_installed
    module_blacklisted.value = !!data.module_blacklisted
    kernel_path_broken.value = !!data.kernel_path_broken
    awg_datapath.value = data.awg_datapath || 'unknown'
    os_family.value = data.os_family || 'unknown'
    script_present.value = data.script_present !== false
    detail.value = data.detail || ''
    error.value = data.message || ''
    const op = data.op && typeof data.op === 'object' ? data.op : {}
    running.value = !!op.running
    op_status.value = op.status || (running.value ? 'running' : 'idle')
    op_message.value = op.message || ''
    op_name.value = op.op || ''
  }

  function schedulePoll () {
    if (pollTimer) clearTimeout(pollTimer)
    const auth = useAuthStore()
    if (!auth.user || !running.value) {
      pollTimer = null
      return
    }
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
      const { data } = await api.get('/api/settings/awg-kernel')
      applyPayload(data)
      return data
    } catch (e) {
      error.value = e?.response?.data?.message || e?.message || 'error'
      throw e
    } finally {
      if (!silent) loading.value = false
      schedulePoll()
    }
  }

  async function startOp (path) {
    starting.value = true
    try {
      await api.post(path)
      running.value = true
      op_status.value = 'running'
      await fetchStatus({ silent: true })
    } finally {
      starting.value = false
      schedulePoll()
    }
  }

  async function startInstall () {
    return startOp('/api/settings/awg-kernel/install')
  }

  async function startReinstall () {
    return startOp('/api/settings/awg-kernel/reinstall')
  }

  async function startRestartAwg () {
    return startOp('/api/settings/awg-kernel/restart-awg')
  }

  async function startUninstall () {
    return startOp('/api/settings/awg-kernel/uninstall')
  }

  return {
    loading,
    starting,
    busy,
    module_loaded,
    package_installed,
    module_blacklisted,
    kernel_path_broken,
    awg_datapath,
    os_family,
    script_present,
    detail,
    op_status,
    op_message,
    op_name,
    running,
    error,
    fetchStatus,
    startInstall,
    startReinstall,
    startRestartAwg,
    startUninstall,
    stopPoll
  }
})
