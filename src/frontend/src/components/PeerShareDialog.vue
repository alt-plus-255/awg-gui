<template>
  <q-dialog :model-value="modelValue" v-bind="mobileDialog" @update:model-value="$emit('update:modelValue', $event)" @hide="onHide">
    <q-card style="width: min(520px, 95vw); max-width: 520px;" class="share-dialog surface-panel dialog-card column no-wrap">
      <q-card-section class="text-h6 q-pb-sm">
        {{ t('configs.shareTitle', { name: peerName }) }}
      </q-card-section>

      <q-card-section v-if="loading" class="row justify-center q-pa-xl col dialog-scroll-body">
        <q-spinner color="primary" size="40px" />
      </q-card-section>

      <template v-else>
        <q-tabs
          v-model="tab"
          dense
          align="justify"
          class="share-tabs"
          active-color="primary"
          indicator-color="primary"
        >
          <q-tab name="qr" :label="t('configs.shareTabQr')" />
          <q-tab name="conf" :label="t('configs.shareTabConf')" />
        </q-tabs>

        <q-tab-panels v-model="tab" animated class="share-panels col dialog-scroll-body">
          <q-tab-panel name="qr" class="q-pt-md">
            <div class="text-center">
              <div v-if="qrUrl" class="qr-wrap" @click="fullscreenOpen = true">
                <img
                  :src="qrUrl"
                  alt="QR"
                  class="qr-img cursor-pointer"
                  :title="t('configs.qrClickToEnlarge')"
                />
              </div>
              <div class="row justify-center q-gutter-sm q-mt-sm">
                <q-btn flat dense color="primary" icon="fullscreen" :label="t('configs.fullscreen')" @click="fullscreenOpen = true" />
                <q-btn flat dense color="primary" icon="download" :label="t('configs.downloadPng')" :loading="pngDownloading" @click="downloadPng" />
              </div>
              <div class="text-caption text-muted-theme q-mt-sm">
                {{ t('configs.qrHint') }}
              </div>
            </div>

            <div class="q-mt-md">
              <div class="text-subtitle2 q-mb-xs">{{ t('configs.amneziaKey') }}</div>
              <q-input
                v-model="vpnUri"
                type="textarea"
                readonly
                filled
                autogrow
                class="theme-field vpn-uri-input"
                input-class="text-no-wrap"
              >
                <template #append>
                  <q-btn
                    flat
                    round
                    dense
                    icon="content_copy"
                    color="primary"
                    :title="t('configs.copyKey')"
                    @click="copyVpnUri"
                  />
                </template>
              </q-input>
              <div class="text-caption text-muted-theme q-mt-sm">
                {{ t('configs.amneziaPasteHint') }}
              </div>
            </div>
          </q-tab-panel>

          <q-tab-panel name="conf" class="q-pt-md">
            <q-input
              v-model="confText"
              type="textarea"
              readonly
              filled
              autogrow
              class="theme-field conf-input"
            >
              <template #append>
                <q-btn
                  flat
                  round
                  dense
                  icon="content_copy"
                  color="primary"
                  :title="t('configs.copyConfig')"
                  @click="copyConf"
                />
              </template>
            </q-input>
            <div class="row q-gutter-sm q-mt-sm">
              <q-btn flat color="primary" icon="download" :label="t('configs.downloadConf')" @click="downloadConf" />
            </div>
            <div class="text-caption text-muted-theme q-mt-sm">
              {{ t('configs.confFallbackHint') }}
            </div>
          </q-tab-panel>
        </q-tab-panels>
      </template>

      <q-card-actions align="right">
        <q-btn flat color="primary" :label="t('common.close')" v-close-popup />
      </q-card-actions>
    </q-card>
  </q-dialog>

  <q-dialog v-model="fullscreenOpen" v-bind="mobileDialog">
    <q-card class="share-dialog surface-panel dialog-card column no-wrap" flat>
      <q-card-section class="text-center q-pa-md col dialog-scroll-body flex flex-center">
        <div class="qr-wrap qr-wrap-fullscreen">
          <img v-if="qrUrl" :src="qrUrl" alt="QR" class="qr-img-fullscreen" />
        </div>
        <div class="text-caption text-muted-theme q-mt-md">{{ peerName }}</div>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn flat color="primary" :label="t('common.close')" v-close-popup />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import { copyText } from '@/utils/clipboard'
import { useMobileDialog } from '@/composables/useMobileDialog'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  configId: { type: [Number, String], default: null },
  clientId: { type: [Number, String], default: null },
  peerName: { type: String, default: '' },
  configName: { type: String, default: '' }
})

defineEmits(['update:modelValue'])

const { t } = useI18n()
const $q = useQuasar()
const mobileDialog = useMobileDialog()

const loading = ref(false)
const tab = ref('qr')
const qrUrl = ref('')
const vpnUri = ref('')
const confText = ref('')
const fullscreenOpen = ref(false)
const pngDownloading = ref(false)

watch(
  () => [props.modelValue, props.configId, props.clientId],
  async ([open, configId, clientId]) => {
    if (open && configId && clientId) {
      await loadShareData(configId, clientId)
    }
  }
)

async function loadShareData (configId, clientId) {
  loading.value = true
  tab.value = 'qr'
  vpnUri.value = ''
  confText.value = ''
  revokeQrUrl()

  try {
    const [qrRes, uriRes, confRes] = await Promise.all([
      api.get(`/api/configs/${configId}/peers/${clientId}/qr`, { responseType: 'blob' }),
      api.get(`/api/configs/${configId}/peers/${clientId}/vpn-uri`, { responseType: 'text' }),
      api.get(`/api/configs/${configId}/peers/${clientId}/config`, { responseType: 'text' })
    ])

    qrUrl.value = URL.createObjectURL(qrRes.data)
    vpnUri.value = typeof uriRes.data === 'string' ? uriRes.data : String(uriRes.data ?? '')
    confText.value = typeof confRes.data === 'string' ? confRes.data : String(confRes.data ?? '')
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.loadShareError') })
  } finally {
    loading.value = false
  }
}

function revokeQrUrl () {
  if (qrUrl.value) {
    URL.revokeObjectURL(qrUrl.value)
    qrUrl.value = ''
  }
}

function onHide () {
  fullscreenOpen.value = false
  vpnUri.value = ''
  confText.value = ''
  revokeQrUrl()
}

async function copyVpnUri () {
  if (!vpnUri.value) return
  try {
    await copyText(vpnUri.value)
    $q.notify({ type: 'positive', message: t('configs.vpnKeyCopied') })
  } catch {
    $q.notify({ type: 'negative', message: t('common.copyFailed') })
  }
}

async function copyConf () {
  if (!confText.value) return
  try {
    await copyText(confText.value)
    $q.notify({ type: 'positive', message: t('configs.configCopied') })
  } catch {
    $q.notify({ type: 'negative', message: t('common.copyFailed') })
  }
}

function downloadFilename () {
  const peer = props.peerName || 'peer'
  if (props.configName) {
    return `${peer}-${props.configName}.conf`
  }
  return `${peer}.conf`
}

function downloadConf () {
  if (!confText.value) return
  const blob = new Blob([confText.value], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = downloadFilename()
  a.click()
  URL.revokeObjectURL(url)
}

async function downloadPng () {
  if (!props.configId || !props.clientId) return
  pngDownloading.value = true
  try {
    const res = await api.get(
      `/api/configs/${props.configId}/peers/${props.clientId}/qr`,
      { params: { format: 'png' }, responseType: 'blob' }
    )
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `${props.peerName || 'peer'}-qr.png`
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    $q.notify({ type: 'negative', message: t('configs.pngDownloadError') })
  } finally {
    pngDownloading.value = false
  }
}
</script>

<style scoped>
.share-dialog {
  color: var(--surface-text);
}

.share-tabs :deep(.q-tab) {
  color: var(--surface-text-muted);
}

.share-tabs :deep(.q-tab--active) {
  color: var(--surface-text);
}

.share-panels {
  background: transparent !important;
}

.share-panels :deep(.q-tab-panel) {
  background: transparent !important;
  color: var(--surface-text);
}

.theme-field :deep(.q-field__control) {
  background: var(--surface-panel-alt) !important;
  color: var(--surface-text);
}

.theme-field :deep(.q-field__control:before) {
  border-color: var(--surface-border) !important;
}

.theme-field :deep(textarea),
.theme-field :deep(input) {
  color: var(--surface-text) !important;
  caret-color: var(--surface-text);
}

.theme-field :deep(.q-field__append) {
  align-self: flex-start;
  padding-top: 8px;
}

.qr-wrap {
  background: #ffffff !important;
  padding: 12px;
  border-radius: 8px;
  display: inline-block;
  box-shadow: none;
  border: 1px solid rgba(0, 0, 0, 0.08);
}

.qr-wrap-fullscreen {
  padding: 24px;
}

.qr-img {
  user-select: none;
  display: block;
  width: 260px;
  max-width: 100%;
  height: auto;
  image-rendering: pixelated;
  image-rendering: crisp-edges;
  background: #ffffff;
}

.qr-img-fullscreen {
  user-select: none;
  display: block;
  width: min(90vw, 480px);
  max-width: 100%;
  height: auto;
  image-rendering: pixelated;
  image-rendering: crisp-edges;
  background: #ffffff;
}

.cursor-pointer {
  cursor: pointer;
}

.vpn-uri-input :deep(textarea) {
  font-family: var(--theme-mono, monospace);
  font-size: 11px;
  word-break: break-all;
}

.conf-input :deep(textarea) {
  font-family: var(--theme-mono, monospace);
  font-size: 12px;
}
</style>
