<template>
  <q-page class="page-pad column">
    <div class="row items-center q-mb-sm">
      <div class="text-h6 text-weight-bold">{{ t('terminal.title') }}</div>
      <q-space />
      <q-badge :color="badgeColor">{{ statusLabel }}</q-badge>
    </div>

    <div ref="logEl" class="terminal-log col">{{ logText }}</div>

    <div class="q-mt-md q-gutter-sm">
      <q-btn
        v-if="status === 'done'"
        color="primary"
        unelevated
        class="full-width"
        :label="t('terminal.openPanel')"
        :to="{ name: 'panel' }"
      />
      <q-btn
        v-if="status === 'failed'"
        outline
        color="grey-5"
        class="full-width"
        :label="t('terminal.retry')"
        :to="{ name: 'install' }"
      />
    </div>
  </q-page>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useConnectionStore } from '@/stores/connection'
import { withSshSession, isNativeSsh } from '@/services/ssh'
import { pollPanelReady, runRemoteInstall } from '@/services/installer'

const { t } = useI18n()
const router = useRouter()
const connection = useConnectionStore()

const status = ref('connecting')
const logText = ref('')
const logEl = ref(null)

const statusLabel = computed(() => {
  const map = {
    connecting: t('terminal.statusConnecting'),
    running: t('terminal.statusRunning'),
    waitingPanel: t('terminal.statusWaitingPanel'),
    readingCreds: t('terminal.statusReadingCreds'),
    done: t('terminal.statusDone'),
    failed: t('terminal.statusFailed')
  }
  return map[status.value] || status.value
})

const badgeColor = computed(() => {
  if (status.value === 'done') return 'positive'
  if (status.value === 'failed') return 'negative'
  return 'info'
})

function appendLog (chunk) {
  logText.value += chunk
  nextTick(() => {
    if (logEl.value) {
      logEl.value.scrollTop = logEl.value.scrollHeight
    }
  })
}

onMounted(async () => {
  if (!connection.host || !connection.password) {
    status.value = 'failed'
    appendLog('Missing SSH credentials. Go back to the install form.\n')
    return
  }

  try {
    status.value = 'connecting'
    appendLog(`ssh ${connection.username}@${connection.host}:${connection.sshPort}\n`)

    const parsed = await withSshSession(
      {
        host: connection.host,
        port: connection.sshPort,
        username: connection.username,
        password: connection.password
      },
      (event) => {
        appendLog(event?.data || '')
      },
      async (ssh) => runRemoteInstall(ssh, {
        skipKernel: connection.skipKernel,
        onStatus: (s) => {
          if (s === 'running') status.value = 'running'
          if (s === 'readingCreds') status.value = 'readingCreds'
        }
      })
    )

    const panelPort = parsed.panelPort || 8877
    const url = parsed.url || `http://${connection.host}:${panelPort}`
    await connection.setPanelCredentials({
      panelPort,
      username: parsed.username || 'admin',
      password: parsed.password,
      url
    })

    if (!parsed.password) {
      throw new Error('Admin password was not found in installer output or .env')
    }

    status.value = 'waitingPanel'
    appendLog(`\nWaiting for panel at ${url} …\n`)
    if (isNativeSsh()) {
      await pollPanelReady(url, { timeoutMs: 180000 })
      appendLog('Panel is ready.\n')
    } else {
      appendLog('Mock/web mode: skipping panel readiness poll.\n')
    }

    status.value = 'done'
    await router.replace({ name: 'panel' })
  } catch (err) {
    status.value = 'failed'
    appendLog(`\n[error] ${err?.message || err}\n`)
  }
})
</script>
