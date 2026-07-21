import { useQuasar } from 'quasar'
import { useI18n } from 'vue-i18n'

const LONG_WAIT_MS = 18000

function buildScenarios (t) {
  return {
    'resolver-save': {
      title: t('resolver.progressSaveResolver'),
      steps: [
        t('resolver.progressSavingSettings'),
        t('resolver.progressUpdateAwg'),
        t('resolver.progressBuildSingbox'),
        t('resolver.progressReloadSingbox')
      ],
      stepIntervalMs: 3000
    },
    'connection-save-proxy': {
      title: t('connections.progressSaveConnection'),
      steps: [
        t('connections.progressSavingSettings'),
        t('connections.progressBuildSingbox'),
        t('connections.progressReloadSingbox')
      ],
      stepIntervalMs: 2500
    },
    'connection-save-subscription': {
      title: t('connections.progressSaveSubscription'),
      steps: [
        t('connections.progressLoadSubscriptionNodes'),
        t('connections.progressSavingSettings'),
        t('connections.progressBuildSingbox'),
        t('connections.progressReloadSingbox')
      ],
      stepIntervalMs: 4000
    },
    'connection-apply': {
      title: t('connections.progressApplyStrategy'),
      steps: [
        t('connections.progressApplyNodePick'),
        t('connections.progressBuildSingbox'),
        t('connections.progressReloadSingbox')
      ],
      stepIntervalMs: 2500
    },
    'connection-refresh': {
      title: t('connections.progressRefreshSubscription'),
      steps: [
        t('connections.progressLoadNodesFromServer'),
        t('connections.progressBuildSingbox'),
        t('connections.progressReloadSingbox')
      ],
      stepIntervalMs: 4000
    }
  }
}

function buildMessage (scenario, stepIndex, showLongHint, longWaitHint) {
  const step = scenario.steps[Math.min(stepIndex, scenario.steps.length - 1)]
  const lines = [`<div class="text-subtitle1 q-mb-sm">${scenario.title}</div>`, `<div>${step}</div>`]
  if (showLongHint) {
    lines.push(`<div class="text-caption text-grey-4 q-mt-md">${longWaitHint}</div>`)
  }
  return lines.join('')
}

export function useApplyProgress () {
  const $q = useQuasar()
  const { t } = useI18n()

  async function withApplyProgress (scenarioId, operation) {
    const scenario = buildScenarios(t)[scenarioId]
    if (!scenario) {
      return operation()
    }

    let stepIndex = 0
    let showLongHint = false
    const startedAt = Date.now()
    const longWaitHint = t('common.longWaitHint')

    const updateOverlay = () => {
      $q.loading.show({
        message: buildMessage(scenario, stepIndex, showLongHint, longWaitHint),
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
