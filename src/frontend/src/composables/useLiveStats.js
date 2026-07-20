import { reactive } from 'vue'
import api from '@/boot/axios'

const LIVE_INTERVAL_KEY = 'awg-live-interval'
const DEFAULT_INTERVAL_MS = 10000
const INTERVAL_OPTIONS = [5000, 10000, 15000, 20000]
const MAX_FAILURES = 3
const MAX_WS_RECONNECT = 3
const WS_PING_MS = 20000
const WS_STALE_MS = 24000
const WS_RETRY_WHILE_HTTP_MS = 60000
const WS_RECONNECT_BASE_MS = 1000

const emptyHost = () => ({
  cpu: { percent: null },
  memory: { used: null, total: null, percent: null },
  disk: { used: null, total: null, percent: null }
})

const state = reactive({
  active: false,
  polling: false,
  failed: false,
  statsAvailable: true,
  lastSyncedAt: null,
  intervalMs: readInterval(),
  /** @type {'idle' | 'ws' | 'http'} */
  transport: 'idle',
  host: emptyHost(),
  /** @type {Record<number, { stats_available: boolean, by_public_key: Record<string, object> }>} */
  byConfig: {}
})

/** @type {Map<number, number>} */
const subscriptionRefs = new Map()
let pollTimer = null
let failureCount = 0
const listeners = new Set()

/** @type {WebSocket | null} */
let socket = null
let wsConnecting = false
let intentionalClose = false
let wsReconnectAttempts = 0
let wsReconnectTimer = null
let wsPingTimer = null
let wsStaleTimer = null
let wsHttpRetryTimer = null
let awaitingPong = false
let lastWsMessageAt = 0

function readInterval () {
  const v = Number(localStorage.getItem(LIVE_INTERVAL_KEY))
  return INTERVAL_OPTIONS.includes(v) ? v : DEFAULT_INTERVAL_MS
}

function saveInterval (ms) {
  state.intervalMs = ms
  localStorage.setItem(LIVE_INTERVAL_KEY, String(ms))
}

function notify () {
  listeners.forEach((fn) => fn())
}

function subscribedConfigIds () {
  return [...subscriptionRefs.keys()].filter((id) => (subscriptionRefs.get(id) || 0) > 0)
}

function refreshParams (configIds) {
  const ids = (configIds || []).map(Number).filter((id) => id > 0)
  if (!ids.length) return {}
  if (ids.length === 1) return { config_id: ids[0] }
  return { config_ids: ids.join(',') }
}

function applyHostMetrics (host) {
  if (!host || typeof host !== 'object') return
  state.host = {
    cpu: { percent: host.cpu?.percent ?? null },
    memory: {
      used: host.memory?.used ?? null,
      total: host.memory?.total ?? null,
      percent: host.memory?.percent ?? null
    },
    disk: {
      used: host.disk?.used ?? null,
      total: host.disk?.total ?? null,
      percent: host.disk?.percent ?? null
    }
  }
}

function applyRefreshResult (data, configIds) {
  state.statsAvailable = data.stats_available !== false
  state.lastSyncedAt = data.synced_at || new Date().toISOString()
  failureCount = 0
  state.failed = false
  applyHostMetrics(data.host)

  const peers = data.peers || []
  const targetIds = configIds.length
    ? configIds.map(Number)
    : [...new Set(peers.map((p) => Number(p.config_id)).filter(Boolean))]

  for (const configId of targetIds) {
    const byPublicKey = {}
    for (const peer of peers) {
      if (Number(peer.config_id) !== Number(configId)) continue
      if (!peer.public_key) continue
      byPublicKey[peer.public_key] = {
        endpoint: peer.endpoint ?? null,
        latest_handshake: peer.latest_handshake ?? null,
        latest_handshake_human: peer.latest_handshake_human ?? null,
        transfer_rx: peer.transfer_rx ?? 0,
        transfer_tx: peer.transfer_tx ?? 0,
        online: peer.online ?? null
      }
    }

    state.byConfig[configId] = {
      stats_available: state.statsAvailable,
      by_public_key: byPublicKey
    }
  }

  notify()
}

function applyWsStatsMessage (data) {
  const configId = Number(data.config_id)
  if (!configId) return

  state.statsAvailable = data.stats_available !== false
  if (data.synced_at) state.lastSyncedAt = data.synced_at
  failureCount = 0
  state.failed = false

  const byPublicKey = {}
  const raw = data.by_public_key || {}
  for (const [publicKey, peer] of Object.entries(raw)) {
    if (!publicKey || !peer || typeof peer !== 'object') continue
    byPublicKey[publicKey] = {
      endpoint: peer.endpoint ?? null,
      latest_handshake: peer.latest_handshake ?? null,
      latest_handshake_human: peer.latest_handshake_human ?? null,
      transfer_rx: peer.transfer_rx ?? 0,
      transfer_tx: peer.transfer_tx ?? 0,
      online: peer.online ?? null
    }
  }

  state.byConfig[configId] = {
    stats_available: data.stats_available !== false,
    by_public_key: byPublicKey
  }
  notify()
}

function applyWsHostMessage (data) {
  if (data.synced_at) state.lastSyncedAt = data.synced_at
  applyHostMetrics(data.host)
  failureCount = 0
  state.failed = false
  notify()
}

async function pollOnce () {
  const ids = subscribedConfigIds()
  if (!ids.length) return

  state.polling = true
  try {
    const { data } = await api.post('/api/stats/refresh', null, { params: refreshParams(ids) })
    applyRefreshResult(data, ids)
  } catch {
    failureCount += 1
    if (failureCount >= MAX_FAILURES) {
      state.failed = true
      stopLivePolling()
    }
  } finally {
    state.polling = false
  }
}

function clearPollTimer () {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

function schedulePoll () {
  if (pollTimer) return
  pollTimer = setInterval(() => {
    void pollOnce()
  }, state.intervalMs)
}

function startHttpFallback () {
  clearWsWatchdogs()
  clearWsReconnectTimer()
  closeSocket(true)
  state.transport = 'http'
  wsReconnectAttempts = 0

  if (subscribedConfigIds().length) {
    void pollOnce()
    schedulePoll()
    scheduleWsRetryWhileHttp()
  }
}

function scheduleWsRetryWhileHttp () {
  clearWsHttpRetryTimer()
  if (!subscribedConfigIds().length) return
  wsHttpRetryTimer = setTimeout(() => {
    wsHttpRetryTimer = null
    if (!subscribedConfigIds().length) return
    if (state.transport !== 'http') return
    wsReconnectAttempts = 0
    void connectWebSocket()
  }, WS_RETRY_WHILE_HTTP_MS)
}

function clearWsHttpRetryTimer () {
  if (wsHttpRetryTimer) {
    clearTimeout(wsHttpRetryTimer)
    wsHttpRetryTimer = null
  }
}

function clearWsReconnectTimer () {
  if (wsReconnectTimer) {
    clearTimeout(wsReconnectTimer)
    wsReconnectTimer = null
  }
}

function clearWsWatchdogs () {
  if (wsPingTimer) {
    clearInterval(wsPingTimer)
    wsPingTimer = null
  }
  if (wsStaleTimer) {
    clearInterval(wsStaleTimer)
    wsStaleTimer = null
  }
  awaitingPong = false
}

function wsUrl (token) {
  const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
  return `${proto}//${window.location.host}/ws?token=${encodeURIComponent(token)}`
}

function sendWs (payload) {
  if (!socket || socket.readyState !== WebSocket.OPEN) return false
  try {
    socket.send(JSON.stringify(payload))
    return true
  } catch {
    return false
  }
}

function syncWsSubscriptions () {
  const ids = subscribedConfigIds()
  if (!ids.length) return
  sendWs({ action: 'subscribe', config_ids: ids })
}

function startWsWatchdogs () {
  clearWsWatchdogs()
  lastWsMessageAt = Date.now()
  awaitingPong = false

  wsPingTimer = setInterval(() => {
    if (!socket || socket.readyState !== WebSocket.OPEN) return
    awaitingPong = true
    if (!sendWs({ action: 'ping' })) {
      handleWsFailure()
    }
  }, WS_PING_MS)

  wsStaleTimer = setInterval(() => {
    if (!socket || socket.readyState !== WebSocket.OPEN) return
    if (Date.now() - lastWsMessageAt > WS_STALE_MS) {
      handleWsFailure()
    }
  }, Math.min(WS_STALE_MS, 5000))
}

function closeSocket (intentional = true) {
  clearWsWatchdogs()
  if (socket) {
    intentionalClose = intentional
    const ws = socket
    socket = null
    try {
      ws.close()
    } catch {
      // ignore
    }
  }
  wsConnecting = false
}

function handleWsMessage (event) {
  lastWsMessageAt = Date.now()
  awaitingPong = false
  let data
  try {
    data = JSON.parse(event.data)
  } catch {
    return
  }
  if (!data || typeof data !== 'object') return

  if (data.type === 'pong') {
    return
  }
  if (data.type === 'stats') {
    applyWsStatsMessage(data)
    return
  }
  if (data.type === 'host') {
    applyWsHostMessage(data)
  }
}

function handleWsFailure () {
  if (!subscribedConfigIds().length) {
    teardownTransport()
    return
  }

  clearWsWatchdogs()
  closeSocket(true)

  if (wsReconnectAttempts < MAX_WS_RECONNECT) {
    const attempt = wsReconnectAttempts
    wsReconnectAttempts += 1
    const delay = WS_RECONNECT_BASE_MS * (2 ** attempt)
    clearWsReconnectTimer()
    wsReconnectTimer = setTimeout(() => {
      wsReconnectTimer = null
      void connectWebSocket()
    }, delay)
    return
  }

  startHttpFallback()
}

async function connectWebSocket () {
  if (!subscribedConfigIds().length) return
  if (wsConnecting) return
  if (socket && (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING)) {
    syncWsSubscriptions()
    return
  }

  wsConnecting = true
  clearWsHttpRetryTimer()
  clearWsReconnectTimer()

  try {
    const { data } = await api.get('/api/ws/token')
    const token = data?.token
    if (!token) throw new Error('missing ws token')

    if (!subscribedConfigIds().length) {
      wsConnecting = false
      return
    }

    intentionalClose = false
    const ws = new WebSocket(wsUrl(token))
    socket = ws

    ws.onopen = () => {
      wsConnecting = false
      wsReconnectAttempts = 0
      clearPollTimer()
      clearWsHttpRetryTimer()
      state.transport = 'ws'
      state.failed = false
      failureCount = 0
      syncWsSubscriptions()
      startWsWatchdogs()
    }

    ws.onmessage = handleWsMessage

    ws.onerror = () => {
      // close handler performs reconnect / fallback
    }

    ws.onclose = () => {
      wsConnecting = false
      if (socket === ws) socket = null
      clearWsWatchdogs()

      const wasIntentional = intentionalClose
      intentionalClose = false
      if (wasIntentional) return

      if (!subscribedConfigIds().length) {
        state.transport = 'idle'
        return
      }

      handleWsFailure()
    }
  } catch {
    wsConnecting = false
    handleWsFailure()
  }
}

function ensureLiveTransport () {
  if (!subscribedConfigIds().length) {
    teardownTransport()
    return
  }

  state.active = true
  state.failed = false

  if (state.transport === 'http') {
    void pollOnce()
    schedulePoll()
    return
  }

  if (socket && socket.readyState === WebSocket.OPEN) {
    syncWsSubscriptions()
    return
  }

  void connectWebSocket()
}

function teardownTransport () {
  clearPollTimer()
  clearWsHttpRetryTimer()
  clearWsReconnectTimer()
  closeSocket(true)
  state.transport = 'idle'
  state.active = false
  state.polling = false
  wsReconnectAttempts = 0
}

export async function refreshLiveStats (configIds = []) {
  const ids = configIds.length ? configIds.map(Number).filter(Boolean) : subscribedConfigIds()
  if (!ids.length) {
    return { stats_available: true, peers: [], by_public_key: {} }
  }

  state.polling = true
  try {
    const { data } = await api.post('/api/stats/refresh', null, { params: refreshParams(ids) })
    applyRefreshResult(data, ids)
    return data
  } finally {
    state.polling = false
  }
}

export function startLivePolling (configIds, intervalMs) {
  if (intervalMs) saveInterval(intervalMs)

  subscriptionRefs.clear()
  for (const key of Object.keys(state.byConfig)) {
    delete state.byConfig[key]
  }

  for (const rawId of configIds) {
    const id = Number(rawId)
    if (!id) continue
    subscriptionRefs.set(id, 1)
  }

  failureCount = 0
  state.failed = false
  wsReconnectAttempts = 0
  clearPollTimer()
  clearWsHttpRetryTimer()
  clearWsReconnectTimer()
  closeSocket(true)

  if (subscribedConfigIds().length) {
    state.active = true
    state.transport = 'idle'
    void connectWebSocket()
  } else {
    teardownTransport()
  }
}

export function subscribeLiveStats (configIds) {
  let added = false
  const newlyAdded = []
  for (const rawId of configIds) {
    const id = Number(rawId)
    if (!id) continue
    const next = (subscriptionRefs.get(id) || 0) + 1
    subscriptionRefs.set(id, next)
    if (next === 1) {
      added = true
      newlyAdded.push(id)
    }
  }

  if (!added) return

  state.active = true
  state.failed = false
  failureCount = 0

  if (socket && socket.readyState === WebSocket.OPEN) {
    sendWs({ action: 'subscribe', config_ids: newlyAdded })
    return
  }

  if (state.transport === 'http') {
    void pollOnce()
    schedulePoll()
    return
  }

  ensureLiveTransport()
}

export function stopLivePolling (configIds) {
  const removedIds = []

  if (configIds?.length) {
    for (const rawId of configIds) {
      const id = Number(rawId)
      if (!id) continue
      const next = (subscriptionRefs.get(id) || 0) - 1
      if (next <= 0) {
        subscriptionRefs.delete(id)
        delete state.byConfig[id]
        removedIds.push(id)
      } else {
        subscriptionRefs.set(id, next)
      }
    }
  } else {
    removedIds.push(...subscribedConfigIds())
    subscriptionRefs.clear()
    for (const key of Object.keys(state.byConfig)) {
      delete state.byConfig[key]
    }
  }

  if (removedIds.length && socket && socket.readyState === WebSocket.OPEN) {
    sendWs({ action: 'unsubscribe', config_ids: removedIds })
  }

  if (!subscribedConfigIds().length) {
    teardownTransport()
  }

  notify()
}

export function setLiveInterval (intervalMs) {
  if (!INTERVAL_OPTIONS.includes(intervalMs)) return
  saveInterval(intervalMs)
  if (state.transport === 'http' && subscribedConfigIds().length) {
    clearPollTimer()
    schedulePoll()
  }
}

export function mergeLiveIntoPeers (peers, configId) {
  const live = state.byConfig[configId]?.by_public_key || {}
  return (peers || []).map((peer) => ({
    ...peer,
    ...(live[peer.public_key] || {})
  }))
}

export function isConfigStatsAvailable (configId) {
  const entry = state.byConfig[configId]
  if (!entry) return true
  return entry.stats_available !== false
}

export function onLiveStats (fn) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export function getLiveIntervalOptions () {
  return INTERVAL_OPTIONS.map((ms) => ({
    label: `${ms / 1000} сек`,
    value: ms
  }))
}

export function applyHostFromStats (data) {
  applyHostMetrics(data?.host)
  notify()
}

export function useLiveStats () {
  return {
    state,
    refresh: refreshLiveStats,
    start: startLivePolling,
    stop: stopLivePolling,
    setInterval: setLiveInterval,
    mergeIntoPeers: mergeLiveIntoPeers,
    isConfigStatsAvailable,
    onLiveStats,
    applyHostFromStats,
    intervalOptions: getLiveIntervalOptions()
  }
}

export function useLiveStatsState () {
  return state
}

export const connectLiveStats = refreshLiveStats
export const disconnectLiveStats = () => stopLivePolling()
export const unsubscribeLiveStats = (configIds) => stopLivePolling(configIds)
