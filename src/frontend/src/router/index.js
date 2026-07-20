import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/pages/LoginPage.vue'),
    meta: { guest: true }
  },
  {
    path: '/',
    component: () => import('@/layouts/MainLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue') },
      { path: 'configs', name: 'configs', component: () => import('@/pages/ConfigsPage.vue') },
      { path: 'resolver', name: 'resolver', component: () => import('@/pages/ResolverPage.vue') },
      { path: 'resolver/connections', name: 'resolver-connections', component: () => import('@/pages/ConnectionsPage.vue') },
      { path: 'resolver/settings', name: 'resolver-settings', component: () => import('@/pages/ResolverSettingsPage.vue') },
      { path: 'diagnostics', name: 'diagnostics', component: () => import('@/pages/DiagnosticsPage.vue') },
      { path: 'settings', name: 'settings', component: () => import('@/pages/SettingsPage.vue') },
      { path: 'peers', redirect: { name: 'configs' } },
      { path: 'clients', redirect: { name: 'configs' } }
    ]
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.checked) {
    await auth.fetchMe()
  }

  const needsAuth = to.matched.some((r) => r.meta.requiresAuth)
  const isGuest = to.matched.some((r) => r.meta.guest)

  if (needsAuth && !auth.user) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (isGuest && auth.user) {
    return { name: 'dashboard' }
  }
})

export default router
