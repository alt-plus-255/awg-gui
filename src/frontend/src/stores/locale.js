import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { Lang } from 'quasar'
import quasarRu from 'quasar/lang/ru'
import quasarEn from 'quasar/lang/en-US'
import {
  DEFAULT_LOCALE,
  LOCALE_LABELS,
  LOCALE_STORAGE_KEY,
  SUPPORTED_LOCALES,
  isValidLocale
} from '@/i18n'

let i18nInstance = null

export function setI18nInstance (i18n) {
  i18nInstance = i18n
}

export function translate (key, named) {
  if (!i18nInstance) return key
  return i18nInstance.global.t(key, named)
}

function readStoredLocale () {
  try {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
    if (isValidLocale(stored)) return stored
    if (stored) localStorage.setItem(LOCALE_STORAGE_KEY, DEFAULT_LOCALE)
  } catch {
    // ignore
  }
  return DEFAULT_LOCALE
}

function applyQuasarLang (code) {
  Lang.set(code === 'en' ? quasarEn : quasarRu)
}

function applyDocumentLang (code) {
  document.documentElement.lang = code === 'en' ? 'en' : 'ru'
}

export const useLocaleStore = defineStore('locale', () => {
  const locale = ref(DEFAULT_LOCALE)

  const localeOptions = computed(() =>
    SUPPORTED_LOCALES.map((value) => ({
      value,
      label: LOCALE_LABELS[value]
    }))
  )

  const currentLabel = computed(() => LOCALE_LABELS[locale.value] || LOCALE_LABELS[DEFAULT_LOCALE])

  function applyLocale (code) {
    const next = isValidLocale(code) ? code : DEFAULT_LOCALE
    locale.value = next
    if (i18nInstance) {
      i18nInstance.global.locale.value = next
    }
    applyQuasarLang(next)
    applyDocumentLang(next)
  }

  function setLocale (code) {
    const next = isValidLocale(code) ? code : DEFAULT_LOCALE
    try {
      localStorage.setItem(LOCALE_STORAGE_KEY, next)
    } catch {
      // ignore
    }
    applyLocale(next)
  }

  function init () {
    applyLocale(readStoredLocale())
  }

  return {
    locale,
    localeOptions,
    currentLabel,
    setLocale,
    init
  }
})
