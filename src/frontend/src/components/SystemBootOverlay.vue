<template>
  <q-dialog :model-value="show" v-bind="mobileDialog" persistent>
    <q-card class="surface-panel dialog-card column no-wrap" style="width: min(520px, 95vw); max-width: 520px;">
      <q-card-section>
        <div class="text-h6">Службы недоступны</div>
        <div class="text-caption text-grey-5 q-mt-xs">
          Проверьте состояние контейнеров AWG-GUI и попробуйте перезапустить службы.
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
        <q-btn flat label="Проверить снова" :loading="system.checking" @click="system.checkStatus(true)" />
        <q-btn
          outline
          color="warning"
          label="Перезапустить AWG"
          :loading="system.restartBusy"
          :disable="system.restartBusy"
          @click="onRestartAwg"
        />
        <q-btn
          color="primary"
          label="Перезапустить всё"
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
import { useQuasar } from 'quasar'
import { useRoute } from 'vue-router'
import { useSystemStore } from '@/stores/system'
import { useMobileDialog } from '@/composables/useMobileDialog'

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
    $q.notify({ type: 'warning', message: 'Перезапуск AWG уже выполняется' })
    return
  }

  try {
    const data = await system.restartAwg()
    if (data?.already_restarting) {
      $q.notify({ type: 'warning', message: data.message || 'Перезапуск AWG уже выполняется' })
      return
    }
    $q.notify({ type: data.ok ? 'positive' : 'negative', message: data.message || 'Готово' })
  } catch (e) {
    const already = e?.response?.status === 409 || e?.response?.data?.already_restarting
    $q.notify({
      type: already ? 'warning' : 'negative',
      message: e?.response?.data?.message || 'Ошибка перезапуска AWG'
    })
  }
}

async function onRestartAll () {
  if (system.restartBusy) {
    $q.notify({ type: 'warning', message: 'Перезапуск уже выполняется' })
    return
  }

  try {
    const data = await system.restartAll()
    if (data?.already_restarting) {
      $q.notify({ type: 'warning', message: data.message || 'Перезапуск уже выполняется' })
      return
    }
    $q.notify({ type: 'info', message: data.message || 'Перезапуск запущен' })
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || 'Ошибка перезапуска' })
  }
}
</script>
