import { createI18n } from 'vue-i18n'

import commonRu from './locales/ru/common.json'
import navRu from './locales/ru/nav.json'
import authRu from './locales/ru/auth.json'
import dashboardRu from './locales/ru/dashboard.json'
import configsRu from './locales/ru/configs.json'
import connectionsRu from './locales/ru/connections.json'
import resolverRu from './locales/ru/resolver.json'
import settingsRu from './locales/ru/settings.json'
import diagnosticsRu from './locales/ru/diagnostics.json'
import themeRu from './locales/ru/theme.json'

import commonEn from './locales/en/common.json'
import navEn from './locales/en/nav.json'
import authEn from './locales/en/auth.json'
import dashboardEn from './locales/en/dashboard.json'
import configsEn from './locales/en/configs.json'
import connectionsEn from './locales/en/connections.json'
import resolverEn from './locales/en/resolver.json'
import settingsEn from './locales/en/settings.json'
import diagnosticsEn from './locales/en/diagnostics.json'
import themeEn from './locales/en/theme.json'

export const SUPPORTED_LOCALES = ['ru', 'en']
export const DEFAULT_LOCALE = 'ru'
export const LOCALE_STORAGE_KEY = 'awg-locale'

export const LOCALE_LABELS = {
  ru: 'Русский',
  en: 'English'
}

const messages = {
  ru: {
    common: commonRu,
    nav: navRu,
    auth: authRu,
    dashboard: dashboardRu,
    configs: configsRu,
    connections: connectionsRu,
    resolver: resolverRu,
    settings: settingsRu,
    diagnostics: diagnosticsRu,
    theme: themeRu
  },
  en: {
    common: commonEn,
    nav: navEn,
    auth: authEn,
    dashboard: dashboardEn,
    configs: configsEn,
    connections: connectionsEn,
    resolver: resolverEn,
    settings: settingsEn,
    diagnostics: diagnosticsEn,
    theme: themeEn
  }
}

export function isValidLocale (code) {
  return SUPPORTED_LOCALES.includes(code)
}

export function createAppI18n (locale = DEFAULT_LOCALE) {
  return createI18n({
    legacy: false,
    locale: isValidLocale(locale) ? locale : DEFAULT_LOCALE,
    fallbackLocale: 'en',
    messages,
    missingWarn: false,
    fallbackWarn: false
  })
}

export function bcp47Locale (code) {
  return code === 'en' ? 'en-US' : 'ru-RU'
}
