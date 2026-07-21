import { play, resumeAudio } from './player'

const CLICK_SELECTOR = [
  'button',
  'a',
  '.q-btn',
  '[role="button"]',
  '.q-toggle',
  '.q-checkbox',
  '.q-radio'
].join(',')

const TOGGLE_SELECTOR = '.q-toggle, .q-checkbox, .q-radio'

function isDisabled (el) {
  if (!el) return true
  if (el.disabled || el.getAttribute?.('aria-disabled') === 'true') return true
  if (el.classList?.contains('disabled') || el.classList?.contains('q-btn--disabled')) return true
  return false
}

function findInteractive (target) {
  if (!(target instanceof Element)) return null
  return target.closest(CLICK_SELECTOR)
}

const MOBILE_MAX_WIDTH = 1023

function getNotifyPosition () {
  return window.matchMedia(`(max-width: ${MOBILE_MAX_WIDTH}px)`).matches
    ? 'bottom'
    : 'bottom-left'
}

function normalizeNotifyOpts (opts) {
  const base = typeof opts === 'string' ? { message: opts } : { ...opts }
  base.position = getNotifyPosition()
  return base
}

/**
 * @param {{ router: import('vue-router').Router, Notify: { create: Function } }} opts
 */
export function installUiSounds ({ router, Notify }) {
  let navReady = false

  document.addEventListener(
    'pointerdown',
    () => {
      resumeAudio()
    },
    { passive: true, once: true }
  )

  document.addEventListener(
    'click',
    (e) => {
      const el = findInteractive(e.target)
      if (!el || isDisabled(el)) return

      if (el.matches(TOGGLE_SELECTOR) || el.closest(TOGGLE_SELECTOR)) {
        play('toggle')
        return
      }
      play('click')
    },
    true
  )

  router.afterEach(() => {
    if (!navReady) {
      navReady = true
      return
    }
    play('navigate')
  })

  const originalCreate = Notify.create.bind(Notify)
  Notify.create = (opts) => {
    const normalized = normalizeNotifyOpts(opts)
    const type = normalized.type || 'info'
    if (type === 'positive') play('success')
    else if (type === 'negative') play('error')
    return originalCreate(normalized)
  }
}
