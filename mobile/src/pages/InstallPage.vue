<template>
  <q-page class="page-pad">
    <div class="text-h5 text-weight-bold q-mb-md">{{ t('install.title') }}</div>

    <q-banner v-if="!native" rounded class="bg-secondary text-grey-3 q-mb-md">
      {{ t('install.mockHint') }}
    </q-banner>

    <q-form class="q-gutter-md" @submit.prevent="onSubmit">
      <q-input
        v-model="form.host"
        filled
        dark
        :label="t('install.host')"
        :rules="[val => !!String(val || '').trim() || t('install.validationHost')]"
        autocomplete="off"
      />
      <q-input
        v-model.number="form.sshPort"
        filled
        dark
        type="number"
        :label="t('install.port')"
      />
      <q-input
        v-model="form.username"
        filled
        dark
        :label="t('install.username')"
        :rules="[val => !!String(val || '').trim() || t('install.validationUser')]"
        autocomplete="username"
      />
      <q-input
        v-model="form.password"
        filled
        dark
        :type="showPassword ? 'text' : 'password'"
        :label="t('install.password')"
        :rules="[val => !!String(val || '') || t('install.validationPassword')]"
        autocomplete="current-password"
      >
        <template #append>
          <q-icon
            :name="showPassword ? 'visibility_off' : 'visibility'"
            class="cursor-pointer"
            @click="showPassword = !showPassword"
          />
        </template>
      </q-input>

      <q-toggle v-model="form.skipKernel" :label="t('install.skipKernel')" color="primary" />

      <q-btn
        type="submit"
        color="primary"
        unelevated
        class="full-width"
        :label="t('install.submit')"
        :loading="saving"
      />
    </q-form>
  </q-page>
</template>

<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useConnectionStore } from '@/stores/connection'
import { isNativeSsh } from '@/services/ssh'

const { t } = useI18n()
const router = useRouter()
const connection = useConnectionStore()
const native = isNativeSsh()
const saving = ref(false)
const showPassword = ref(false)

const form = reactive({
  host: connection.host || '',
  sshPort: connection.sshPort || 22,
  username: connection.username || 'root',
  password: connection.password || '',
  skipKernel: connection.skipKernel || false
})

async function onSubmit () {
  saving.value = true
  try {
    await connection.setInstallForm(form)
    await router.push({ name: 'terminal' })
  } finally {
    saving.value = false
  }
}
</script>
