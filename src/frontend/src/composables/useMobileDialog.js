import { computed } from 'vue'
import { useQuasar } from 'quasar'

/**
 * Props for q-dialog: fullscreen + slide on mobile (<1024px), centered + scale on desktop.
 * Usage: <q-dialog v-bind="mobileDialog" ...>
 */
export function useMobileDialog () {
  const $q = useQuasar()

  return computed(() => {
    const maximized = $q.screen.lt.md
    return {
      maximized,
      transitionShow: maximized ? 'slide-up' : 'scale',
      transitionHide: maximized ? 'slide-down' : 'scale'
    }
  })
}
