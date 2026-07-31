import { defineStore } from 'pinia'
import { computed, reactive, ref } from 'vue'
import { Notify } from 'quasar'
import api from '@/boot/axios'
import router from '@/router'
import { translate } from '@/stores/locale'

export const useSpeedTestStore = defineStore('speedTest', () => {
  const job = ref(null)
  const results = reactive({})
  const nodeResults = reactive({})
  const resultsUpdatedAt = ref(null)
  const starting = ref(false)
  const loadingStatus = ref(false)

  let pollTimer = null
  const pendingNotifyIds = new Set()

  const busy = computed(() => {
    if (starting.value) return true
    const status = job.value?.status
    return status === 'queued' || status === 'running'
  })

  function resultKey (connectionId, nodeKey = null) {
    return nodeKey ? `${connectionId}::${nodeKey}` : String(connectionId)
  }

  function applyByKey (byKey) {
    if (!byKey || typeof byKey !== 'object') return
    for (const [key, value] of Object.entries(byKey)) {
      if (!value || typeof value !== 'object') continue
      if (key.includes('::')) {
        nodeResults[key] = value
      } else {
        results[key] = value
      }
    }
  }

  function notifyCompletion (finishedJob) {
    if (!finishedJob?.id || !pendingNotifyIds.has(finishedJob.id)) return
    pendingNotifyIds.delete(finishedJob.id)

    if (router.currentRoute.value?.name === 'resolver-speed-test') return

    const openAction = {
      label: translate('resolver.speedTestOpen'),
      color: 'white',
      handler: () => { void router.push({ name: 'resolver-speed-test' }) }
    }

    if (finishedJob.status === 'done') {
      Notify.create({
        type: 'positive',
        message: translate('resolver.speedTestNotifyDone'),
        actions: [openAction]
      })
      return
    }

    Notify.create({
      type: 'negative',
      message: finishedJob.error || translate('resolver.speedTestNotifyFailed'),
      actions: [openAction]
    })
  }

  function applyPayload (data) {
    const prevStatus = job.value?.status
    const prevId = job.value?.id
    job.value = data?.job ?? null
    if (data?.results) {
      resultsUpdatedAt.value = data.results.updated_at ?? null
      applyByKey(data.results.by_key)
    }

    const next = job.value
    if (
      next
      && (next.status === 'done' || next.status === 'failed')
      && (prevId !== next.id || prevStatus === 'queued' || prevStatus === 'running' || pendingNotifyIds.has(next.id))
    ) {
      notifyCompletion(next)
    }
  }

  function schedulePoll () {
    if (pollTimer) clearTimeout(pollTimer)
    if (!busy.value) {
      pollTimer = null
      return
    }
    pollTimer = setTimeout(() => {
      void fetchStatus({ silent: true })
    }, 2000)
  }

  function stopPoll () {
    if (pollTimer) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  async function fetchStatus (opts = {}) {
    const silent = opts.silent === true
    if (!silent) loadingStatus.value = true
    try {
      const { data } = await api.get('/api/resolver/speed-test/status')
      applyPayload(data)
      return data
    } finally {
      if (!silent) loadingStatus.value = false
      schedulePoll()
    }
  }

  function isRunningKey (connectionId, nodeKey = null) {
    if (!busy.value || !job.value) return false
    const j = job.value
    if (j.kind === 'batch') {
      if (nodeKey) return false
      if (j.current_connection_id != null) {
        return Number(j.current_connection_id) === Number(connectionId)
      }
      const ids = Array.isArray(j.connection_ids) ? j.connection_ids : []
      return ids.map(Number).includes(Number(connectionId))
    }
    if (Number(j.connection_id) !== Number(connectionId)) return false
    const jNode = j.node_key || null
    const want = nodeKey || null
    return jNode === want
  }

  async function startConnection (connectionId, nodeKey = null) {
    starting.value = true
    try {
      const body = nodeKey ? { node_key: nodeKey } : {}
      const { data } = await api.post(`/api/resolver/connections/${connectionId}/speed-test`, body)
      if (data.job?.id) pendingNotifyIds.add(data.job.id)
      job.value = data.job ?? null
      Notify.create({ type: 'info', message: translate('resolver.speedTestStarted') })
      schedulePoll()
      return data
    } catch (e) {
      Notify.create({
        type: 'negative',
        message: e?.response?.data?.error || e.message || translate('resolver.speedTestFailed')
      })
      throw e
    } finally {
      starting.value = false
      schedulePoll()
    }
  }

  async function startBatch () {
    starting.value = true
    try {
      const { data } = await api.post('/api/resolver/speed-test/batch')
      if (data.job?.id) pendingNotifyIds.add(data.job.id)
      job.value = data.job ?? null
      Notify.create({ type: 'info', message: translate('resolver.speedTestStarted') })
      schedulePoll()
      return data
    } catch (e) {
      Notify.create({
        type: 'negative',
        message: e?.response?.data?.error || e.message || translate('resolver.speedTestFailed')
      })
      throw e
    } finally {
      starting.value = false
      schedulePoll()
    }
  }

  return {
    job,
    results,
    nodeResults,
    resultsUpdatedAt,
    starting,
    loadingStatus,
    busy,
    resultKey,
    isRunningKey,
    fetchStatus,
    startConnection,
    startBatch,
    stopPoll
  }
})
