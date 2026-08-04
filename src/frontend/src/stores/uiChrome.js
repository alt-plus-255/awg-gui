import { reactive } from 'vue'
import { defineStore } from 'pinia'

export const UI_CHROME_STORAGE_KEY = 'awg-nav-chrome'

const DEFAULTS = {
  showLanguage: true,
  showTheme: true,
  showInstallApp: true,
  showUsername: true,
  showLogout: true
}

function readStored () {
  try {
    const raw = localStorage.getItem(UI_CHROME_STORAGE_KEY)
    if (!raw) return { ...DEFAULTS }
    const parsed = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object') return { ...DEFAULTS }

    // Migrate legacy showUserMenu → showUsername + showLogout
    const legacyUserMenu = parsed.showUserMenu !== false
    const showUsername = 'showUsername' in parsed
      ? parsed.showUsername !== false
      : legacyUserMenu
    const showLogout = 'showLogout' in parsed
      ? parsed.showLogout !== false
      : legacyUserMenu

    return {
      showLanguage: parsed.showLanguage !== false,
      showTheme: parsed.showTheme !== false,
      showInstallApp: parsed.showInstallApp !== false,
      showUsername,
      showLogout
    }
  } catch {
    return { ...DEFAULTS }
  }
}

function persist (prefs) {
  try {
    localStorage.setItem(UI_CHROME_STORAGE_KEY, JSON.stringify({
      showLanguage: !!prefs.showLanguage,
      showTheme: !!prefs.showTheme,
      showInstallApp: !!prefs.showInstallApp,
      showUsername: !!prefs.showUsername,
      showLogout: !!prefs.showLogout
    }))
  } catch {
    // ignore
  }
}

export const useUiChromeStore = defineStore('uiChrome', () => {
  const prefs = reactive(readStored())

  function setPref (key, value) {
    if (!(key in DEFAULTS)) return
    prefs[key] = !!value
    persist(prefs)
  }

  function init () {
    Object.assign(prefs, readStored())
  }

  return { prefs, setPref, init }
})
