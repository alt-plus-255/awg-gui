import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '@/boot/axios'
import { translate } from '@/stores/locale'

export const useSystemStore = defineStore('system', () => {
  const status = ref(null)
  const checking = ref(false)
  const restarting = ref(false)
  let inFlight = null
  let pollTimer = null
  let restartPollTimer = null

  const ok = computed(() => status.value?.ok !== false)
  const blocked = computed(() => status.value != null && status.value.ok === false)
  const messages = computed(() => status.value?.messages || [])
  const awgRestarting = computed(() => !!status.value?.awg_restarting)
  const restartBusy = computed(() => restarting.value || awgRestarting.value)

  async function checkStatus (force = false) {
    if (inFlight && !force) return inFlight
    checking.value = true
    inFlight = api.get('/api/system/status')
      .then(({ data }) => {
        status.value = data
        if (data.ok) {
          stopBlockedPoll()
        } else {
          startBlockedPoll()
        }
        if (data.awg_restarting) {
          startRestartPoll()
        } else {
          stopRestartPoll()
        }
        return data
      })
      .catch(() => {
        status.value = {
          ok: false,
          awg_restarting: false,
          services: { app: { ok: false }, awg: { ok: false, running: false } },
          messages: [translate('common.apiUnreachable')]
        }
        startBlockedPoll()
        return status.value
      })
      .finally(() => {
        checking.value = false
        inFlight = null
      })
    return inFlight
  }

  function startBlockedPoll () {
    if (pollTimer) return
    pollTimer = setInterval(() => {
      void checkStatus(true)
    }, 5000)
  }

  function stopBlockedPoll () {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  function startRestartPoll () {
    if (restartPollTimer) return
    restartPollTimer = setInterval(() => {
      void checkStatus(true)
    }, 2000)
  }

  function stopRestartPoll () {
    if (restartPollTimer) {
      clearInterval(restartPollTimer)
      restartPollTimer = null
    }
  }

  async function restartAwg () {
    if (restartBusy.value) {
      return {
        ok: false,
        already_restarting: true,
        message: translate('common.restartAwgInProgress')
      }
    }

    restarting.value = true
    // Optimistically mark so reload/other tabs see busy via next status poll
    if (status.value) {
      status.value = { ...status.value, awg_restarting: true }
    }
    startRestartPoll()

    try {
      const { data } = await api.post('/api/system/restart-awg')
      await checkStatus(true)
      return data
    } catch (e) {
      await checkStatus(true)
      throw e
    } finally {
      restarting.value = false
    }
  }

  async function restartAll () {
    if (restartBusy.value) {
      return {
        ok: false,
        already_restarting: true,
        message: translate('common.restartAwgInProgress')
      }
    }

    restarting.value = true
    try {
      const { data } = await api.post('/api/system/restart-all')
      setTimeout(() => void checkStatus(true), 3000)
      return data
    } finally {
      restarting.value = false
    }
  }

  return {
    status,
    checking,
    restarting,
    awgRestarting,
    restartBusy,
    ok,
    blocked,
    messages,
    checkStatus,
    restartAwg,
    restartAll,
    stopBlockedPoll
  }
})
