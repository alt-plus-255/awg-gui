import { createApp } from 'vue'
import { Quasar, Notify, Dialog, Loading, Dark } from 'quasar'
import quasarLang from 'quasar/lang/ru'
import iconSet from 'quasar/icon-set/material-icons'

import '@quasar/extras/material-icons/material-icons.css'
import '@quasar/extras/roboto-font/roboto-font.css'
import 'quasar/src/css/index.sass'

import App from './App.vue'
import router from './router'
import { createPinia } from 'pinia'
import { useThemeStore } from '@/stores/theme'
import { useAuthStore } from '@/stores/auth'
import { useSoundStore } from '@/sounds/store'
import { installUiSounds } from '@/sounds/install'
import './css/app.scss'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)
app.use(Quasar, {
  plugins: { Notify, Dialog, Loading, Dark },
  lang: quasarLang,
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
      position: 'top-right'
    }
  }
})

useThemeStore(pinia).init()
useSoundStore(pinia).init()
installUiSounds({ router, Notify })
useAuthStore(pinia).fetchMe()
app.mount('#q-app')
