import { computed, onMounted, onUnmounted, ref } from 'vue'

function isStandaloneDisplay () {
  if (typeof window === 'undefined') return false
  if (window.matchMedia('(display-mode: standalone)').matches) return true
  // iOS Safari
  return Boolean(window.navigator.standalone)
}

/**
 * Chrome/Edge/Android install prompt for the panel PWA.
 * Visible only in a secure context when beforeinstallprompt fires.
 */
export function usePwaInstall () {
  const deferredPrompt = ref(null)
  const installed = ref(isStandaloneDisplay())

  const canInstall = computed(() =>
    !installed.value
    && typeof window !== 'undefined'
    && window.isSecureContext
    && deferredPrompt.value != null
  )

  function onBeforeInstallPrompt (event) {
    event.preventDefault()
    deferredPrompt.value = event
  }

  function onAppInstalled () {
    deferredPrompt.value = null
    installed.value = true
  }

  onMounted(() => {
    if (typeof window === 'undefined') return
    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.addEventListener('appinstalled', onAppInstalled)
  })

  onUnmounted(() => {
    if (typeof window === 'undefined') return
    window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.removeEventListener('appinstalled', onAppInstalled)
  })

  async function install () {
    const promptEvent = deferredPrompt.value
    if (!promptEvent) return false
    deferredPrompt.value = null
    promptEvent.prompt()
    const choice = await promptEvent.userChoice
    if (choice?.outcome === 'accepted') {
      installed.value = true
      return true
    }
    return false
  }

  return {
    canInstall,
    installed,
    install
  }
}
