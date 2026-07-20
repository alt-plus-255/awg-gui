import { useQuasar } from 'quasar'

const SCENARIOS = {
  'resolver-save': {
    title: 'Сохранение резолвера',
    steps: [
      'Сохранение настроек…',
      'Обновление AWG-конфига…',
      'Сборка sing-box…',
      'Перезагрузка sing-box…'
    ],
    stepIntervalMs: 3000
  },
  'connection-save-proxy': {
    title: 'Сохранение подключения',
    steps: [
      'Сохранение настроек…',
      'Сборка sing-box…',
      'Перезагрузка sing-box…'
    ],
    stepIntervalMs: 2500
  },
  'connection-save-subscription': {
    title: 'Сохранение подписки',
    steps: [
      'Загрузка узлов подписки…',
      'Сохранение настроек…',
      'Сборка sing-box…',
      'Перезагрузка sing-box…'
    ],
    stepIntervalMs: 4000
  },
  'connection-apply': {
    title: 'Применение стратегии',
    steps: [
      'Применение выбора узла…',
      'Сборка sing-box…',
      'Перезагрузка sing-box…'
    ],
    stepIntervalMs: 2500
  },
  'connection-refresh': {
    title: 'Обновление подписки',
    steps: [
      'Загрузка узлов с сервера…',
      'Сборка sing-box…',
      'Перезагрузка sing-box…'
    ],
    stepIntervalMs: 4000
  }
}

const LONG_WAIT_HINT = 'При большом числе узлов это может занять до минуты — не закрывайте страницу.'
const LONG_WAIT_MS = 18000

function buildMessage (scenario, stepIndex, showLongHint) {
  const step = scenario.steps[Math.min(stepIndex, scenario.steps.length - 1)]
  const lines = [`<div class="text-subtitle1 q-mb-sm">${scenario.title}</div>`, `<div>${step}</div>`]
  if (showLongHint) {
    lines.push(`<div class="text-caption text-grey-4 q-mt-md">${LONG_WAIT_HINT}</div>`)
  }
  return lines.join('')
}

export function useApplyProgress () {
  const $q = useQuasar()

  async function withApplyProgress (scenarioId, operation) {
    const scenario = SCENARIOS[scenarioId]
    if (!scenario) {
      return operation()
    }

    let stepIndex = 0
    let showLongHint = false
    const startedAt = Date.now()

    const updateOverlay = () => {
      $q.loading.show({
        message: buildMessage(scenario, stepIndex, showLongHint),
        html: true,
        boxClass: 'bg-dark text-white',
        spinnerColor: 'primary'
      })
    }

    updateOverlay()

    const stepTimer = setInterval(() => {
      if (stepIndex < scenario.steps.length - 1) {
        stepIndex += 1
      }
      if (!showLongHint && Date.now() - startedAt >= LONG_WAIT_MS) {
        showLongHint = true
      }
      updateOverlay()
    }, scenario.stepIntervalMs)

    try {
      return await operation()
    } finally {
      clearInterval(stepTimer)
      $q.loading.hide()
    }
  }

  return { withApplyProgress }
}
