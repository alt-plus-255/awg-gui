import { useQuasar } from 'quasar'
import { useI18n } from 'vue-i18n'
import api from '@/boot/axios'
import { useApplyProgress } from '@/composables/useApplyProgress'

/**
 * First visit after install: community lists may still be empty while the
 * scheduler has not finished. Auto-download once with an informative overlay.
 */
export function useResolverListsBootstrap () {
  const $q = useQuasar()
  const { t } = useI18n()
  const { withApplyProgress } = useApplyProgress()

  async function ensureListsReady ({ settingsData = null, notifyOnSuccess = false } = {}) {
    let payload = settingsData
    if (!payload) {
      const { data } = await api.get('/api/resolver/settings')
      payload = data
    }

    if (!payload?.needs_initial_sync) {
      return payload
    }

    try {
      const synced = await withApplyProgress('resolver-lists-initial', async () => {
        const { data } = await api.post('/api/resolver/settings/sync-lists')
        return data
      })
      if (notifyOnSuccess) {
        $q.notify({
          type: synced?.ok === false ? 'warning' : 'positive',
          message: synced?.message || t('resolver.listsDownloaded')
        })
      }
      return synced
    } catch (e) {
      if (e?.response?.data?.lists) {
        payload = e.response.data
      }
      $q.notify({
        type: 'negative',
        message: e?.response?.data?.message || t('resolver.downloadError'),
        timeout: 10000
      })
      return payload
    }
  }

  return { ensureListsReady }
}
