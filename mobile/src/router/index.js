import { createRouter, createWebHashHistory } from 'vue-router'
import MainLayout from '@/layouts/MainLayout.vue'

const routes = [
  {
    path: '/',
    component: MainLayout,
    children: [
      { path: '', name: 'faq', component: () => import('@/pages/FaqPage.vue') },
      { path: 'install', name: 'install', component: () => import('@/pages/InstallPage.vue') },
      { path: 'terminal', name: 'terminal', component: () => import('@/pages/TerminalPage.vue') },
      { path: 'panel', name: 'panel', component: () => import('@/pages/PanelPage.vue') }
    ]
  }
]

export default createRouter({
  history: createWebHashHistory(),
  routes
})
