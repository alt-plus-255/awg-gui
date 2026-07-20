import { ref } from 'vue'
import { defineStore } from 'pinia'

export const SOUND_STORAGE_KEY = 'awg-ui-sounds'

function readStoredEnabled () {
  try {
    const stored = localStorage.getItem(SOUND_STORAGE_KEY)
    if (stored === '0' || stored === 'false') return false
    if (stored === '1' || stored === 'true') return true
  } catch {
    // ignore
  }
  return true
}

export const useSoundStore = defineStore('sounds', () => {
  const enabled = ref(readStoredEnabled())

  function setEnabled (value) {
    enabled.value = !!value
    try {
      localStorage.setItem(SOUND_STORAGE_KEY, enabled.value ? '1' : '0')
    } catch {
      // ignore
    }
  }

  function init () {
    enabled.value = readStoredEnabled()
  }

  return { enabled, setEnabled, init }
})
