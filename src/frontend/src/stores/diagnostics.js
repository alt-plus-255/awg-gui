import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import api from '@/boot/axios'
import { translate as t } from '@/stores/locale'

export const useDiagnosticsStore = defineStore('diagnostics', () => {
  const status = ref(null)
  const result = ref(null)
  const loadingStatus = ref(false)
  const running = ref(false)
  const selectedConfigIds = ref([])
  const singBoxModal = ref({ open: false, loading: false, content: '', error: null, updatedAt: null })
  const awgModal = ref({ open: false, loading: false, configs: [], error: null })

  const configs = computed(() => status.value?.configs || [])
  const containers = computed(() => status.value?.containers || [])
  const singbox = computed(() => status.value?.singbox || null)
  const ifaces = computed(() => status.value?.ifaces || [])
  const system = computed(() => status.value?.system || {})
  const groups = computed(() => result.value?.groups || [])
  const hints = computed(() => result.value?.hints || [])

  async function fetchStatus () {
    loadingStatus.value = true
    try {
      const { data } = await api.get('/api/diagnostics/status')
      status.value = data
      if (selectedConfigIds.value.length === 0 && Array.isArray(data.configs)) {
        selectedConfigIds.value = data.configs.map((c) => c.id)
      } else if (Array.isArray(data.configs)) {
        const valid = new Set(data.configs.map((c) => c.id))
        selectedConfigIds.value = selectedConfigIds.value.filter((id) => valid.has(id))
        if (selectedConfigIds.value.length === 0) {
          selectedConfigIds.value = data.configs.map((c) => c.id)
        }
      }
      return data
    } finally {
      loadingStatus.value = false
    }
  }

  async function runDiagnostic () {
    running.value = true
    try {
      const { data } = await api.post('/api/diagnostics/run', {
        config_ids: selectedConfigIds.value
      })
      result.value = data
      await fetchStatus()
      return data
    } finally {
      running.value = false
    }
  }

  function toggleConfig (id) {
    const set = new Set(selectedConfigIds.value)
    if (set.has(id)) set.delete(id)
    else set.add(id)
    selectedConfigIds.value = [...set]
  }

  function selectAllConfigs () {
    selectedConfigIds.value = configs.value.map((c) => c.id)
  }

  function clearConfigSelection () {
    selectedConfigIds.value = []
  }

  async function openSingBoxConfig () {
    singBoxModal.value = { open: true, loading: true, content: '', error: null, updatedAt: null }
    try {
      const { data } = await api.get('/api/diagnostics/configs/sing-box')
      singBoxModal.value = {
        open: true,
        loading: false,
        content: data.content || '',
        error: data.error || null,
        updatedAt: data.updated_at || null
      }
    } catch (e) {
      singBoxModal.value = {
        open: true,
        loading: false,
        content: '',
        error: e?.response?.data?.message || e.message || t('diagnostics.loadSingboxError'),
        updatedAt: null
      }
    }
  }

  async function openAwgConfigs () {
    awgModal.value = { open: true, loading: true, configs: [], error: null }
    try {
      const { data } = await api.get('/api/diagnostics/configs/awg')
      awgModal.value = {
        open: true,
        loading: false,
        configs: data.configs || [],
        error: null
      }
    } catch (e) {
      awgModal.value = {
        open: true,
        loading: false,
        configs: [],
        error: e?.response?.data?.message || e.message || t('diagnostics.loadAwgConfigsError')
      }
    }
  }

  function closeSingBoxModal () {
    singBoxModal.value = { ...singBoxModal.value, open: false }
  }

  function closeAwgModal () {
    awgModal.value = { ...awgModal.value, open: false }
  }

  function dumpText () {
    const lines = []
    lines.push('=== AWG-GUI Diagnostics ===')
    lines.push(`updated_at: ${result.value?.updated_at || status.value?.updated_at || ''}`)
    lines.push(`status: ${result.value?.status || (status.value?.ok ? 'ok' : 'fail')}`)
    lines.push('')

    if (status.value) {
      lines.push('--- Containers ---')
      for (const c of status.value.containers || []) {
        lines.push(`[${c.ok ? 'OK' : 'FAIL'}] ${c.label} (${c.name}): ${c.detail}`)
      }
      const sb = status.value.singbox
      if (sb) lines.push(`[${sb.ok ? 'OK' : 'FAIL'}] sing-box: ${sb.detail}`)
      lines.push('')
    }

    for (const g of result.value?.groups || []) {
      lines.push(`--- ${g.title} [${g.status}] ---`)
      for (const c of g.checks || []) {
        lines.push(`[${c.ok ? 'OK' : 'FAIL'}] ${c.label}: ${c.detail}`)
      }
      lines.push('')
    }

    if (result.value?.hints?.length) {
      lines.push('--- Hints ---')
      for (const h of result.value.hints) lines.push(`- ${h}`)
    }

    return lines.join('\n')
  }

  return {
    status,
    result,
    loadingStatus,
    running,
    selectedConfigIds,
    singBoxModal,
    awgModal,
    configs,
    containers,
    singbox,
    ifaces,
    system,
    groups,
    hints,
    fetchStatus,
    runDiagnostic,
    toggleConfig,
    selectAllConfigs,
    clearConfigSelection,
    openSingBoxConfig,
    openAwgConfigs,
    closeSingBoxModal,
    closeAwgModal,
    dumpText
  }
})
