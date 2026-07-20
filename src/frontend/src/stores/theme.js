import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { setCssVar, Dark } from 'quasar'
import {
  STYLE_STORAGE_KEY,
  LEGACY_THEME_STORAGE_KEY,
  COLOR_MODE_STORAGE_KEY,
  AUTO_HOURS_STORAGE_KEY,
  DEFAULT_AUTO_HOURS,
  colorModeOptions,
  getStyle,
  isValidStyleId,
  isValidColorMode,
  normalizeAutoHours,
  resolveScheme,
  styleList
} from '@/themes/themes'

function readStoredStyleId () {
  try {
    let stored = localStorage.getItem(STYLE_STORAGE_KEY)
    if (!stored) {
      stored = localStorage.getItem(LEGACY_THEME_STORAGE_KEY)
      if (stored && isValidStyleId(stored)) {
        localStorage.setItem(STYLE_STORAGE_KEY, stored)
        localStorage.removeItem(LEGACY_THEME_STORAGE_KEY)
      }
    }
    if (isValidStyleId(stored)) return stored
    if (stored) localStorage.setItem(STYLE_STORAGE_KEY, 'classic')
  } catch {
    // ignore
  }
  return 'classic'
}

function readStoredColorMode () {
  try {
    const stored = localStorage.getItem(COLOR_MODE_STORAGE_KEY)
    if (isValidColorMode(stored)) return stored
    if (stored) localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'dark')
  } catch {
    // ignore
  }
  return 'dark'
}

function readStoredAutoHours () {
  try {
    const raw = localStorage.getItem(AUTO_HOURS_STORAGE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw)
      return normalizeAutoHours(parsed)
    }
  } catch {
    // ignore
  }
  return { ...DEFAULT_AUTO_HOURS }
}

function applySurfaceVars (surface) {
  const root = document.documentElement
  root.style.setProperty('--surface-bg', surface.bg)
  root.style.setProperty('--surface-panel', surface.panel)
  root.style.setProperty('--surface-panel-alt', surface.panelAlt)
  root.style.setProperty('--surface-hover', surface.hover)
  root.style.setProperty('--surface-border', surface.border)
  root.style.setProperty('--surface-text', surface.text)
  root.style.setProperty('--surface-text-muted', surface.textMuted)
  root.style.setProperty('--surface-text-soft', surface.textSoft)
  root.style.setProperty('--surface-highlight', surface.highlight)
  root.style.setProperty('--surface-highlight-bg', surface.highlightBg)
  root.style.setProperty('--surface-warn-bg', surface.warnBg)
  root.style.setProperty('--surface-glow', surface.glow)
  root.style.setProperty('--surface-radius', surface.radius)
}

function applyGraphVars (graph) {
  const root = document.documentElement
  root.style.setProperty('--graph-server', graph.server)
  root.style.setProperty('--graph-online', graph.online)
  root.style.setProperty('--graph-offline', graph.offline)
  root.style.setProperty('--graph-disabled', graph.disabled)
  root.style.setProperty('--graph-peer-link', graph.peerLink)
  root.style.setProperty('--graph-peer-link-hover', graph.peerLinkHover)
  root.style.setProperty('--graph-tunnel-online', graph.tunnelOnline)
  root.style.setProperty('--graph-tunnel-offline', graph.tunnelOffline)
  root.style.setProperty('--graph-tunnel-disabled', graph.tunnelDisabled)
  root.style.setProperty('--graph-label', graph.label)
  root.style.setProperty('--graph-zone-fill', graph.zoneFill)
  root.style.setProperty('--graph-zone-stroke', graph.zoneStroke)
  root.style.setProperty('--graph-zone-header-fill', graph.zoneHeaderFill)
}

function applyThemeToDom (theme) {
  const root = document.documentElement
  root.setAttribute('data-theme', theme.id)
  root.setAttribute('data-scheme', theme.scheme)
  root.classList.toggle('theme-scanlines', theme.scanlines)
  root.style.setProperty('--theme-font', theme.fontFamily)
  root.style.setProperty('--theme-mono', theme.monoFamily)
  root.style.background = theme.surface.bg
  root.style.color = theme.surface.text

  Object.entries(theme.brand).forEach(([key, value]) => {
    setCssVar(key, value)
  })

  applySurfaceVars(theme.surface)
  applyGraphVars(theme.graph)

  Dark.set(theme.scheme === 'dark')
  // Inline color beats Quasar body.body--dark { color: #fff } on light scheme
  document.body.style.color = theme.surface.text

  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.setAttribute('content', theme.brand.dark)
}

let autoTimer = null
let systemMedia = null
let systemMediaHandler = null
let visibilityHandler = null

function clearListeners () {
  if (autoTimer) {
    clearInterval(autoTimer)
    autoTimer = null
  }
  if (systemMedia && systemMediaHandler) {
    systemMedia.removeEventListener('change', systemMediaHandler)
    systemMedia = null
    systemMediaHandler = null
  }
  if (visibilityHandler) {
    document.removeEventListener('visibilitychange', visibilityHandler)
    visibilityHandler = null
  }
}

export const useThemeStore = defineStore('theme', () => {
  const styleId = ref(readStoredStyleId())
  const colorMode = ref(readStoredColorMode())
  const autoHours = ref(readStoredAutoHours())

  const resolvedScheme = computed(() =>
    resolveScheme(colorMode.value, autoHours.value)
  )

  const current = computed(() => getStyle(styleId.value, resolvedScheme.value))
  const styleOptions = styleList.map((t) => ({ label: t.label, value: t.id }))
  const colorModeOptionList = colorModeOptions

  const isDark = computed(() => resolvedScheme.value === 'dark')

  // Backward-compatible aliases
  const themeId = styleId
  const options = styleOptions

  function apply () {
    applyThemeToDom(getStyle(styleId.value, resolvedScheme.value))
  }

  function setupListeners () {
    clearListeners()

    if (colorMode.value === 'system') {
      systemMedia = window.matchMedia('(prefers-color-scheme: dark)')
      systemMediaHandler = () => apply()
      systemMedia.addEventListener('change', systemMediaHandler)
    }

    if (colorMode.value === 'auto') {
      autoTimer = setInterval(() => apply(), 60_000)
      visibilityHandler = () => {
        if (document.visibilityState === 'visible') apply()
      }
      document.addEventListener('visibilitychange', visibilityHandler)
    }
  }

  function setStyle (id) {
    if (!isValidStyleId(id)) return
    const changed = styleId.value !== id
    styleId.value = id
    try {
      localStorage.setItem(STYLE_STORAGE_KEY, id)
    } catch {
      // ignore
    }
    apply()
    if (changed) {
      import('@/sounds/player').then(({ play }) => play('theme')).catch(() => {})
    }
  }

  function setColorMode (mode) {
    if (!isValidColorMode(mode)) return
    colorMode.value = mode
    try {
      localStorage.setItem(COLOR_MODE_STORAGE_KEY, mode)
    } catch {
      // ignore
    }
    apply()
    setupListeners()
  }

  function setAutoHours (hours) {
    autoHours.value = normalizeAutoHours(hours)
    try {
      localStorage.setItem(AUTO_HOURS_STORAGE_KEY, JSON.stringify(autoHours.value))
    } catch {
      // ignore
    }
    if (colorMode.value === 'auto') apply()
  }

  /** @deprecated use setStyle */
  function setTheme (id) {
    setStyle(id)
  }

  function init () {
    apply()
    setupListeners()
  }

  return {
    styleId,
    colorMode,
    autoHours,
    resolvedScheme,
    current,
    styleOptions,
    colorModeOptionList,
    isDark,
    themeId,
    options,
    setStyle,
    setColorMode,
    setAutoHours,
    setTheme,
    init,
    apply
  }
})
