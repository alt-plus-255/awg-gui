import { configure } from 'quasar/wrappers'

export default configure((ctx) => {
  return {
    boot: ['axios'],
    css: ['app.scss'],
    extras: ['material-icons', 'roboto-font'],
    build: {
      target: {
        browser: ['es2022', 'firefox115', 'chrome115', 'safari14'],
        node: 'node20'
      },
      vueRouterMode: 'history',
      env: {
        API_BASE: ''
      },
      vitePlugins: []
    },
    devServer: {
      open: false,
      port: 9000,
      proxy: {
        '/api': 'http://localhost:8000',
        '/sanctum': 'http://localhost:8000'
      }
    },
    framework: {
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
      },
      plugins: ['Notify', 'Dialog', 'Loading']
    },
    animations: [],
    ssr: { pwa: false },
    pwa: { workboxMode: 'GenerateSW' },
    cordova: {},
    capacitor: {},
    electron: {},
    bex: {}
  }
})
