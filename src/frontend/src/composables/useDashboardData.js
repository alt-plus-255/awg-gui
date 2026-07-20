import { ref } from 'vue'
import api from '@/boot/axios'
import { applyHostFromStats } from '@/composables/useLiveStats'

export function useDashboardData () {
  const loading = ref(false)
  const peers = ref([])
  const links = ref([])
  const configs = ref([])
  const summary = ref({
    clients_total: 0,
    memberships_total: 0,
    memberships_enabled: 0,
    online: 0
  })

  async function load (configId = null) {
    loading.value = true
    try {
      const [statsRes, configsRes] = await Promise.all([
        api.get('/api/stats', {
          params: {
            include_links: true,
            ...(configId ? { config_id: configId } : {})
          }
        }),
        configs.value.length ? Promise.resolve({ data: { configs: configs.value } }) : api.get('/api/configs')
      ])

      peers.value = statsRes.data.peers || []
      links.value = statsRes.data.links || []
      summary.value = statsRes.data.summary || summary.value
      applyHostFromStats(statsRes.data)
      if (!configs.value.length) {
        configs.value = configsRes.data.configs || []
      }
    } finally {
      loading.value = false
    }
  }

  return {
    loading,
    peers,
    links,
    configs,
    summary,
    load
  }
}
