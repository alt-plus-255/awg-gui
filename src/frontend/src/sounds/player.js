import { getSoundPack } from './packs'
import { useThemeStore } from '@/stores/theme'
import { useSoundStore } from './store'

const CLICK_DEBOUNCE_MS = 80

let audioCtx = null
let lastClickAt = 0

function getContext () {
  if (!audioCtx) {
    const Ctx = window.AudioContext || window.webkitAudioContext
    if (!Ctx) return null
    audioCtx = new Ctx()
  }
  return audioCtx
}

export async function resumeAudio () {
  const ctx = getContext()
  if (ctx && ctx.state === 'suspended') {
    try {
      await ctx.resume()
    } catch {
      // ignore
    }
  }
  return ctx
}

/**
 * @param {'click'|'navigate'|'success'|'error'|'toggle'|'theme'} event
 */
export function play (event) {
  const soundStore = useSoundStore()
  if (!soundStore.enabled) return

  if (event === 'click') {
    const now = performance.now()
    if (now - lastClickAt < CLICK_DEBOUNCE_MS) return
    lastClickAt = now
  }

  const ctx = getContext()
  if (!ctx) return

  if (ctx.state === 'suspended') {
    ctx.resume().catch(() => {})
  }

  const styleId = useThemeStore().styleId
  const pack = getSoundPack(styleId)
  const fn = pack[event]
  if (typeof fn !== 'function') return

  try {
    fn(ctx)
  } catch {
    // ignore synthesis errors
  }
}
