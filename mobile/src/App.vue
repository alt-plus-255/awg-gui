<template>
  <router-view />
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useConnectionStore } from '@/stores/connection'
import { pollPanelReady } from '@/services/installer'

const router = useRouter()
const connection = useConnectionStore()

onMounted(async () => {
  await connection.hydrate()
  if (!connection.hasPanel) return
  try {
    const ready = await pollPanelReady(connection.panelBaseUrl, { timeoutMs: 8000, intervalMs: 2000 })
    if (ready) {
      await router.replace({ name: 'panel' })
    }
  } catch {
    // stay on FAQ / install
  }
})
</script>
