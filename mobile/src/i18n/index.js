import { createI18n } from 'vue-i18n'
import ru from './locales/ru.json'
import en from './locales/en.json'

export const LOCALE_STORAGE_KEY = 'awg-mobile-locale'
export const DEFAULT_LOCALE = 'ru'
export const SUPPORTED_LOCALES = ['ru', 'en']

export function isValidLocale (value) {
  return SUPPORTED_LOCALES.includes(value)
}

export function createAppI18n (locale = DEFAULT_LOCALE) {
  return createI18n({
    legacy: false,
    locale: isValidLocale(locale) ? locale : DEFAULT_LOCALE,
    fallbackLocale: 'en',
    messages: { ru, en }
  })
}
