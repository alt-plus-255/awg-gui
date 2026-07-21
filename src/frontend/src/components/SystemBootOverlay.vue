<template>
  <q-dialog :model-value="show" v-bind="mobileDialog" persistent>
    <q-card class="surface-panel dialog-card column no-wrap" style="width: min(520px, 95vw); max-width: 520px;">
      <q-card-section>
        <div class="text-h6">{{ t('common.servicesUnavailable') }}</div>
        <div class="text-caption text-grey-5 q-mt-xs">
          {{ t('common.servicesUnavailableHint') }}
        </div>
      </q-card-section>

      <q-card-section class="col dialog-scroll-body">
        <q-list dense>
          <q-item v-for="(msg, i) in system.messages" :key="i">
            <q-item-section avatar>
              <q-icon name="warning" color="warning" />
            </q-item-section>
            <q-item-section>{{ msg }}</q-item-section>
          </q-item>
        </q-list>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn flat :label="t('common.checkAgain')" :loading="system.checking" @click="system.checkStatus(true)" />
        <q-btn
          outline
          color="warning"
          :label="t('common.restartAwg')"
          :loading="system.restartBusy"
          :disable="system.restartBusy"
          @click="onRestartAwg"
        />
        <q-btn
          color="primary"
          :label="t('common.restartAll')"
          :loading="system.restartBusy"
          :disable="system.restartBusy"
          @click="onRestartAll"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import { useRoute } from 'vue-router'
import { useSystemStore } from '@/stores/system'
import { useMobileDialog } from '@/composables/useMobileDialog'

const { t } = useI18n()
const $q = useQuasar()
const mobileDialog = useMobileDialog()
const route = useRoute()
const system = useSystemStore()

const show = computed(() => {
  if (route.name === 'settings') return false
  return system.blocked
})

async function onRestartAwg () {
  if (system.restartBusy) {
    $q.notify({ type: 'warning', message: t('common.restartAwgInProgress') })
    return
  }

  try {
    const data = await system.restartAwg()
    if (data?.already_restarting) {
      $q.notify({ type: 'warning', message: data.message || t('common.restartAwgInProgress') })
      return
    }
    $q.notify({ type: data.ok ? 'positive' : 'negative', message: data.message || t('common.done') })
  } catch (e) {
    const already = e?.response?.status === 409 || e?.response?.data?.already_restarting
    $q.notify({
      type: already ? 'warning' : 'negative',
      message: e?.response?.data?.message || t('common.restartAwgError')
    })
  }
}

async function onRestartAll () {
  if (system.restartBusy) {
    $q.notify({ type: 'warning', message: t('common.restartInProgress') })
    return
  }

  try {
    const data = await system.restartAll()
    if (data?.already_restarting) {
      $q.notify({ type: 'warning', message: data.message || t('common.restartInProgress') })
      return
    }
    $q.notify({ type: 'info', message: data.message || t('common.restartStarted') })
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('common.restartError') })
  }
}
</script>
