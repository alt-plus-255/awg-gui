import { watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSystemStore } from '@/stores/system'
import { useSettingsStore } from '@/stores/settings'
import { useProjectUpdateStore } from '@/stores/projectUpdate'

export function useAppBootstrap () {
  const auth = useAuthStore()
  const system = useSystemStore()
  const settings = useSettingsStore()
  const projectUpdate = useProjectUpdateStore()

  async function bootstrap () {
    await Promise.all([
      system.checkStatus(),
      settings.fetch()
    ])
    void projectUpdate.checkForUpdates({ silent: true })
  }

  watch(
    () => auth.checked && !!auth.user,
    (ready) => {
      if (ready) void bootstrap()
    },
    { immediate: true }
  )
}
