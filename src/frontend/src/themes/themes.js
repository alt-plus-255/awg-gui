export const STYLE_STORAGE_KEY = 'awg-style'
export const LEGACY_THEME_STORAGE_KEY = 'awg-theme'
export const COLOR_MODE_STORAGE_KEY = 'awg-color-mode'
export const AUTO_HOURS_STORAGE_KEY = 'awg-auto-hours'

export const DEFAULT_AUTO_HOURS = { from: '07:00', to: '21:00' }

export const COLOR_MODES = ['dark', 'light', 'system', 'auto']
export const SCHEMES = ['dark', 'light']

export const colorModeOptions = [
  { label: 'Тёмная', value: 'dark' },
  { label: 'Светлая', value: 'light' },
  { label: 'Как в системе', value: 'system' },
  { label: 'Автоматически по времени', value: 'auto' }
]

export const styles = {
  classic: {
    id: 'classic',
    label: 'Classic',
    fontFamily: 'Roboto, Helvetica, Arial, sans-serif',
    monoFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
    scanlines: false,
    dark: {
      brand: {
        primary: '#1f6feb',
        secondary: '#243447',
        accent: '#58a6ff',
        dark: '#0d1117',
        positive: '#3fb950',
        negative: '#f85149',
        info: '#58a6ff',
        warning: '#d29922'
      },
      surface: {
        bg: '#0d1117',
        panel: '#161b22',
        panelAlt: '#1c2128',
        hover: '#21262d',
        border: '#30363d',
        text: '#e6edf3',
        textMuted: '#8b949e',
        textSoft: '#c9d1d9',
        highlight: '#79b8ff',
        highlightBg: 'rgba(33, 150, 243, 0.18)',
        warnBg: '#2a2010',
        glow: 'transparent',
        radius: '8px'
      },
      graph: {
        server: '#3d7bd9',
        online: '#21ba45',
        offline: '#6b7280',
        disabled: '#3a3f47',
        peerLink: '#39d0d8',
        peerLinkHover: '#5fe6ee',
        tunnelOnline: '#2ea043',
        tunnelOffline: '#3d444d',
        tunnelDisabled: '#2a2f36',
        label: '#c9d1d9',
        zoneFill: 'rgba(31, 111, 235, 0.06)',
        zoneStroke: 'rgba(48, 54, 61, 0.9)',
        zoneHeaderFill: 'rgba(22, 27, 34, 0.92)'
      }
    },
    light: {
      brand: {
        primary: '#0969da',
        secondary: '#eaeef2',
        accent: '#218bff',
        dark: '#ffffff',
        positive: '#1a7f37',
        negative: '#cf222e',
        info: '#0969da',
        warning: '#9a6700'
      },
      surface: {
        bg: '#ffffff',
        panel: '#f6f8fa',
        panelAlt: '#eaeef2',
        hover: '#d0d7de',
        border: '#d0d7de',
        text: '#1f2328',
        textMuted: '#656d76',
        textSoft: '#424a53',
        highlight: '#0969da',
        highlightBg: 'rgba(9, 105, 218, 0.12)',
        warnBg: '#fff8c5',
        glow: 'transparent',
        radius: '8px'
      },
      graph: {
        server: '#0969da',
        online: '#1a7f37',
        offline: '#8c959f',
        disabled: '#d0d7de',
        peerLink: '#0969da',
        peerLinkHover: '#218bff',
        tunnelOnline: '#1a7f37',
        tunnelOffline: '#8c959f',
        tunnelDisabled: '#d0d7de',
        label: '#424a53',
        zoneFill: 'rgba(9, 105, 218, 0.06)',
        zoneStroke: 'rgba(208, 215, 222, 0.9)',
        zoneHeaderFill: 'rgba(246, 248, 250, 0.92)'
      }
    }
  },

  crt: {
    id: 'crt',
    label: 'CRT Terminal',
    fontFamily: '"VT323", monospace',
    monoFamily: '"VT323", monospace',
    scanlines: true,
    dark: {
      brand: {
        primary: '#2aad55',
        secondary: '#0a1a0f',
        accent: '#2ec4a0',
        dark: '#010a05',
        positive: '#2aad55',
        negative: '#ff3355',
        info: '#2ec4a0',
        warning: '#d4a017'
      },
      surface: {
        bg: '#010a05',
        panel: '#06140b',
        panelAlt: '#0a1a0f',
        hover: '#0f2416',
        border: '#1a3d28',
        text: '#b8ffc8',
        textMuted: '#4a9a62',
        textSoft: '#7dcc94',
        highlight: '#2ec4a0',
        highlightBg: 'rgba(42, 173, 85, 0.18)',
        warnBg: '#1a1808',
        glow: 'rgba(42, 173, 85, 0.3)',
        radius: '0px'
      },
      graph: {
        server: '#2ec4a0',
        online: '#2aad55',
        offline: '#3a6b4a',
        disabled: '#1a2e22',
        peerLink: '#2ec4a0',
        peerLinkHover: '#5fe0c0',
        tunnelOnline: '#2aad55',
        tunnelOffline: '#2a4a35',
        tunnelDisabled: '#152018',
        label: '#7dcc94',
        zoneFill: 'rgba(42, 173, 85, 0.08)',
        zoneStroke: 'rgba(26, 61, 40, 0.95)',
        zoneHeaderFill: 'rgba(6, 20, 11, 0.92)'
      }
    },
    light: {
      brand: {
        primary: '#1a8a3f',
        secondary: '#e8f5ec',
        accent: '#148f6a',
        dark: '#f4fff7',
        positive: '#1a8a3f',
        negative: '#c62828',
        info: '#148f6a',
        warning: '#b8860b'
      },
      surface: {
        bg: '#f4fff7',
        panel: '#e8f5ec',
        panelAlt: '#d4eeda',
        hover: '#c2e6cb',
        border: '#7dcc94',
        text: '#0a3d18',
        textMuted: '#2a6b3a',
        textSoft: '#1a5530',
        highlight: '#148f6a',
        highlightBg: 'rgba(26, 138, 63, 0.14)',
        warnBg: '#fff8e1',
        glow: 'rgba(26, 138, 63, 0.15)',
        radius: '0px'
      },
      graph: {
        server: '#148f6a',
        online: '#1a8a3f',
        offline: '#7dcc94',
        disabled: '#c2e6cb',
        peerLink: '#148f6a',
        peerLinkHover: '#1a8a3f',
        tunnelOnline: '#1a8a3f',
        tunnelOffline: '#7dcc94',
        tunnelDisabled: '#c2e6cb',
        label: '#1a5530',
        zoneFill: 'rgba(26, 138, 63, 0.08)',
        zoneStroke: 'rgba(125, 204, 148, 0.9)',
        zoneHeaderFill: 'rgba(232, 245, 236, 0.92)'
      }
    }
  },

  ds: {
    id: 'ds',
    label: 'DS',
    fontFamily: '"Exo 2", Roboto, sans-serif',
    monoFamily: '"Share Tech Mono", ui-monospace, monospace',
    scanlines: false,
    dark: {
      brand: {
        primary: '#4db8c4',
        secondary: '#2a3238',
        accent: '#8ecfd8',
        dark: '#0c0e10',
        positive: '#6ba872',
        negative: '#c45c4a',
        info: '#5eb8cc',
        warning: '#c9a227'
      },
      surface: {
        bg: '#0c0e10',
        panel: '#1a1e22',
        panelAlt: '#222830',
        hover: '#2a3038',
        border: '#3a4550',
        text: '#d4cfc4',
        textMuted: '#8a8578',
        textSoft: '#b8b3a8',
        highlight: '#5eb8cc',
        highlightBg: 'rgba(77, 184, 196, 0.15)',
        warnBg: '#2a2418',
        glow: 'rgba(77, 184, 196, 0.25)',
        radius: '2px'
      },
      graph: {
        server: '#5eb8cc',
        online: '#6ba872',
        offline: '#5a6068',
        disabled: '#3a4048',
        peerLink: '#4db8c4',
        peerLinkHover: '#7dd4e0',
        tunnelOnline: '#6ba872',
        tunnelOffline: '#4a5058',
        tunnelDisabled: '#323840',
        label: '#b8b3a8',
        zoneFill: 'rgba(77, 184, 196, 0.08)',
        zoneStroke: 'rgba(58, 69, 80, 0.9)',
        zoneHeaderFill: 'rgba(26, 30, 34, 0.92)'
      }
    },
    light: {
      brand: {
        primary: '#2a8a96',
        secondary: '#e8eef0',
        accent: '#4db8c4',
        dark: '#f0f4f6',
        positive: '#4a8a52',
        negative: '#b5453a',
        info: '#2a8a96',
        warning: '#9a7a15'
      },
      surface: {
        bg: '#eef2f4',
        panel: '#f8fafb',
        panelAlt: '#e4eaed',
        hover: '#d4dde2',
        border: '#b8c5cc',
        text: '#1a2228',
        textMuted: '#5a6870',
        textSoft: '#3a4850',
        highlight: '#2a8a96',
        highlightBg: 'rgba(42, 138, 150, 0.12)',
        warnBg: '#faf3e0',
        glow: 'rgba(42, 138, 150, 0.12)',
        radius: '2px'
      },
      graph: {
        server: '#2a8a96',
        online: '#4a8a52',
        offline: '#8a959c',
        disabled: '#d4dde2',
        peerLink: '#2a8a96',
        peerLinkHover: '#4db8c4',
        tunnelOnline: '#4a8a52',
        tunnelOffline: '#8a959c',
        tunnelDisabled: '#d4dde2',
        label: '#3a4850',
        zoneFill: 'rgba(42, 138, 150, 0.08)',
        zoneStroke: 'rgba(184, 197, 204, 0.9)',
        zoneHeaderFill: 'rgba(248, 250, 251, 0.92)'
      }
    }
  },

  sa: {
    id: 'sa',
    label: 'SA',
    fontFamily: '"Oswald", Roboto, sans-serif',
    monoFamily: '"Roboto Mono", ui-monospace, monospace',
    scanlines: false,
    dark: {
      brand: {
        primary: '#6abf2a',
        secondary: '#1a1408',
        accent: '#ff9933',
        dark: '#0f0c08',
        positive: '#6abf2a',
        negative: '#cc3333',
        info: '#66ccff',
        warning: '#ff9933'
      },
      surface: {
        bg: '#0f0c08',
        panel: '#1a1610',
        panelAlt: '#231e16',
        hover: '#2e281c',
        border: '#4a4028',
        text: '#f0e6d0',
        textMuted: '#9a9078',
        textSoft: '#c8bca0',
        highlight: '#8fd42a',
        highlightBg: 'rgba(106, 191, 42, 0.18)',
        warnBg: '#2a1810',
        glow: 'rgba(255, 153, 51, 0.25)',
        radius: '4px'
      },
      graph: {
        server: '#66ccff',
        online: '#6abf2a',
        offline: '#6a6050',
        disabled: '#3a3428',
        peerLink: '#ff9933',
        peerLinkHover: '#ffbb66',
        tunnelOnline: '#6abf2a',
        tunnelOffline: '#4a4438',
        tunnelDisabled: '#2a2418',
        label: '#c8bca0',
        zoneFill: 'rgba(255, 153, 51, 0.08)',
        zoneStroke: 'rgba(74, 64, 40, 0.9)',
        zoneHeaderFill: 'rgba(26, 22, 16, 0.92)'
      }
    },
    light: {
      brand: {
        primary: '#5a9a22',
        secondary: '#f5f0e6',
        accent: '#e67e22',
        dark: '#faf8f4',
        positive: '#5a9a22',
        negative: '#b52a2a',
        info: '#2980b9',
        warning: '#e67e22'
      },
      surface: {
        bg: '#faf8f4',
        panel: '#f5f0e6',
        panelAlt: '#ebe4d4',
        hover: '#ddd4c0',
        border: '#c8bca0',
        text: '#2a2418',
        textMuted: '#6a6050',
        textSoft: '#4a4030',
        highlight: '#5a9a22',
        highlightBg: 'rgba(90, 154, 34, 0.12)',
        warnBg: '#fff3e0',
        glow: 'rgba(230, 126, 34, 0.15)',
        radius: '4px'
      },
      graph: {
        server: '#2980b9',
        online: '#5a9a22',
        offline: '#9a9078',
        disabled: '#ddd4c0',
        peerLink: '#e67e22',
        peerLinkHover: '#f39c12',
        tunnelOnline: '#5a9a22',
        tunnelOffline: '#9a9078',
        tunnelDisabled: '#ddd4c0',
        label: '#4a4030',
        zoneFill: 'rgba(230, 126, 34, 0.08)',
        zoneStroke: 'rgba(200, 188, 160, 0.9)',
        zoneHeaderFill: 'rgba(245, 240, 230, 0.92)'
      }
    }
  }
}

export const styleList = Object.values(styles)

/** Boot-time bg map for FOUC prevention (inline script in index.html) */
export const bootSurfaceBg = {
  classic: { dark: '#0d1117', light: '#ffffff' },
  crt: { dark: '#010a05', light: '#f4fff7' },
  ds: { dark: '#0c0e10', light: '#eef2f4' },
  sa: { dark: '#0f0c08', light: '#faf8f4' }
}

export function parseTimeToMinutes (time) {
  const parts = String(time || '00:00').split(':')
  const h = Number.parseInt(parts[0], 10) || 0
  const m = Number.parseInt(parts[1], 10) || 0
  return h * 60 + m
}

export function resolveScheme (colorMode, autoHours = DEFAULT_AUTO_HOURS, now = new Date()) {
  if (colorMode === 'dark') return 'dark'
  if (colorMode === 'light') return 'light'

  if (colorMode === 'system') {
    if (typeof window !== 'undefined' && window.matchMedia) {
      return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
    }
    return 'dark'
  }

  if (colorMode === 'auto') {
    const from = parseTimeToMinutes(autoHours.from)
    const to = parseTimeToMinutes(autoHours.to)
    const current = now.getHours() * 60 + now.getMinutes()
    if (from === to) return 'dark'
    if (from < to) {
      return current >= from && current < to ? 'light' : 'dark'
    }
    return current >= from || current < to ? 'light' : 'dark'
  }

  return 'dark'
}

export function getStyle (id, scheme = 'dark') {
  const style = styles[id] || styles.classic
  const palette = style[scheme] || style.dark
  return {
    id: style.id,
    label: style.label,
    fontFamily: style.fontFamily,
    monoFamily: style.monoFamily,
    scanlines: style.scanlines,
    scheme,
    brand: palette.brand,
    surface: palette.surface,
    graph: palette.graph
  }
}

export function isValidStyleId (id) {
  return Boolean(styles[id])
}

export function isValidColorMode (mode) {
  return COLOR_MODES.includes(mode)
}

export function normalizeAutoHours (hours) {
  const from = hours?.from && /^\d{2}:\d{2}$/.test(hours.from) ? hours.from : DEFAULT_AUTO_HOURS.from
  const to = hours?.to && /^\d{2}:\d{2}$/.test(hours.to) ? hours.to : DEFAULT_AUTO_HOURS.to
  return { from, to }
}

// Backward-compatible aliases
export const THEME_STORAGE_KEY = STYLE_STORAGE_KEY
export const themes = styles
export const themeList = styleList

export function getTheme (id, scheme = 'dark') {
  return getStyle(id, scheme)
}

export function isValidThemeId (id) {
  return isValidStyleId(id)
}
