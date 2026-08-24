<template>
  <q-page class="page-pad">
    <div class="text-h5 text-weight-bold q-mb-md">{{ t('panel.title') }}</div>

    <template v-if="!connection.hasPanel">
      <q-banner rounded class="bg-secondary text-grey-3">
        {{ t('panel.missing') }}
      </q-banner>
      <q-btn
        class="q-mt-md"
        color="primary"
        unelevated
        :label="t('faq.cta')"
        :to="{ name: 'install' }"
      />
    </template>

    <template v-else>
      <q-card flat bordered class="bg-dark q-mb-md">
        <q-card-section>
          <div class="text-caption text-grey-5">URL</div>
          <div class="text-body1">{{ connection.panelBaseUrl }}</div>
          <div class="text-caption text-grey-5 q-mt-sm">Login</div>
          <div class="text-body1">{{ connection.panelUsername }}</div>
        </q-card-section>
      </q-card>

      <q-banner v-if="!native" rounded class="bg-secondary text-grey-3 q-mb-md">
        {{ t('panel.webHint') }}
      </q-banner>

      <q-banner v-if="message" rounded class="bg-secondary text-grey-3 q-mb-md">
        {{ message }}
      </q-banner>

      <q-btn
        color="primary"
        unelevated
        class="full-width"
        :loading="opening"
        :label="t('panel.openNative')"
        @click="openPanel"
      />

      <q-btn
        flat
        color="negative"
        class="full-width q-mt-sm"
        :label="t('panel.disconnect')"
        @click="disconnect"
      />
    </template>
  </q-page>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useConnectionStore } from '@/stores/connection'
import { closePanelBrowser, isNativePanel, openPanelWithAutoLogin } from '@/services/panelAuth'

const { t } = useI18n()
const router = useRouter()
const connection = useConnectionStore()
const native = isNativePanel()
const opening = ref(false)
const message = ref('')

async function openPanel () {
  opening.value = true
  message.value = t('panel.opening')
  try {
    await openPanelWithAutoLogin({
      url: connection.panelBaseUrl,
      username: connection.panelUsername,
      password: connection.panelPassword
    })
  } catch (err) {
    message.value = err?.message || String(err)
  } finally {
    opening.value = false
  }
}

async function disconnect () {
  await closePanelBrowser()
  await connection.clear()
  await router.replace({ name: 'faq' })
}

onMounted(() => {
  if (connection.hasPanel) {
    void openPanel()
  }
})
</script>
