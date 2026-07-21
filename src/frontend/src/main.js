import { createApp } from 'vue'
import { Quasar, Notify, Dialog, Loading, Dark } from 'quasar'
import quasarRu from 'quasar/lang/ru'
import quasarEn from 'quasar/lang/en-US'
import iconSet from 'quasar/icon-set/material-icons'

import '@quasar/extras/material-icons/material-icons.css'
import '@quasar/extras/roboto-font/roboto-font.css'
import 'quasar/src/css/index.sass'

import App from './App.vue'
import router from './router'
import { createPinia } from 'pinia'
import { createAppI18n, DEFAULT_LOCALE, LOCALE_STORAGE_KEY, isValidLocale } from '@/i18n'
import { useThemeStore } from '@/stores/theme'
import { useLocaleStore, setI18nInstance } from '@/stores/locale'
import { useAuthStore } from '@/stores/auth'
import { useSoundStore } from '@/sounds/store'
import { installUiSounds } from '@/sounds/install'
import './css/app.scss'

function readInitialLocale () {
  try {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
    if (isValidLocale(stored)) return stored
  } catch {
    // ignore
  }
  return DEFAULT_LOCALE
}

const initialLocale = readInitialLocale()
const i18n = createAppI18n(initialLocale)
setI18nInstance(i18n)

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)
app.use(i18n)
app.use(Quasar, {
  plugins: { Notify, Dialog, Loading, Dark },
  lang: initialLocale === 'en' ? quasarEn : quasarRu,
  iconSet,
  config: {
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
    notify: {
      position: 'bottom-left'
    }
  }
})

useLocaleStore(pinia).init()
useThemeStore(pinia).init()
useSoundStore(pinia).init()
installUiSounds({ router, Notify })
useAuthStore(pinia).fetchMe()
app.mount('#q-app')
