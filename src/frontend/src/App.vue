<template>
  <router-view />
</template>

<script setup>
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

watch(
  () => [auth.checked, auth.user],
  ([isChecked]) => {
    if (!isChecked) return

    const needsAuth = route.matched.some((r) => r.meta.requiresAuth)
    const isGuest = route.matched.some((r) => r.meta.guest)

    if (needsAuth && !auth.user) {
      router.replace({ name: 'login', query: { redirect: route.fullPath } })
    } else if (isGuest && auth.user) {
      router.replace(route.query.redirect || { name: 'dashboard' })
    }
  }
)
</script>
