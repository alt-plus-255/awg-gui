import { watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useSystemStore } from '@/stores/system'
import { useSettingsStore } from '@/stores/settings'

export function useAppBootstrap () {
  const auth = useAuthStore()
  const system = useSystemStore()
  const settings = useSettingsStore()

  async function bootstrap () {
    await Promise.all([
      system.checkStatus(),
      settings.fetch()
    ])
  }

  watch(
    () => auth.checked && !!auth.user,
    (ready) => {
      if (ready) void bootstrap()
    },
    { immediate: true }
  )
}
