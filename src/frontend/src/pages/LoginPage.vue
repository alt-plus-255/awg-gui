<template>
  <q-layout view="hHh lpR fFf" class="surface-bg">
    <q-page-container>
      <q-page class="flex flex-center relative-position">
        <div class="absolute-top-right q-pa-md row items-center no-wrap q-gutter-sm">
          <LanguageSwitcher />
          <ThemeSwitcher />
        </div>
        <q-card class="q-pa-lg surface-panel login-card">
          <div class="row items-center q-gutter-md q-mb-sm">
            <img src="/icons/logo.png" alt="AWG-GUI" width="40" height="40" class="app-logo" />
            <div class="text-h5">AWG-GUI</div>
          </div>
          <div class="text-caption text-grey-5 q-mb-lg">{{ t('auth.title') }}</div>

          <div v-if="locked" class="lockout-box q-mb-md">
            <div class="text-subtitle2 q-mb-sm">{{ t('auth.lockedTitle') }}</div>
            <div class="text-body2 q-mb-sm">
              {{ t('auth.lockedMessage') }}
            </div>
            <div class="text-h5 text-center mono countdown">{{ countdownLabel }}</div>
            <div class="text-caption text-grey-5 text-center q-mt-sm">
              {{ t('auth.lockedRemaining') }}
            </div>
          </div>

          <q-form v-else @submit.prevent="onSubmit">
            <q-input
              v-model="username"
              :label="t('auth.username')"

              filled
              class="q-mb-md"
              autocomplete="username"
              :disable="loading"
            />
            <q-input
              v-model="password"
              :label="t('auth.password')"
              type="password"

              filled
              class="q-mb-md"
              autocomplete="current-password"
              :disable="loading"
            />

            <div v-if="needTotp" class="q-mb-md">
              <q-input
                v-model="totp"
                :label="t('auth.twoFactorCode')"

                filled
                maxlength="6"
                inputmode="numeric"
                autocomplete="one-time-code"
                :disable="loading"
              />
            </div>

            <div v-if="captchaRequired" class="q-mb-md">
              <div class="text-caption text-grey-5 q-mb-sm">{{ t('auth.captchaHint') }}</div>
              <div class="row items-center q-gutter-sm q-mb-sm">
                <img
                  v-if="captchaImage"
                  :src="captchaImage"
                  :alt="t('auth.captcha')"
                  class="captcha-img"
                />
                <q-btn
                  flat
                  dense
                  round
                  icon="refresh"
                  color="grey-5"
                  :loading="captchaLoading"
                  @click="reloadCaptcha"
                />
              </div>
              <q-input
                v-model="captchaAnswer"
                :label="t('auth.captchaCode')"

                filled
                inputmode="numeric"
                autocomplete="off"
                :disable="loading"
              />
            </div>

            <q-btn
              type="submit"
              color="primary"
              class="full-width"
              :label="t('auth.login')"
              :loading="loading"
            />
          </q-form>
        </q-card>
      </q-page>
    </q-page-container>
  </q-layout>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import { useAuthStore } from '@/stores/auth'
import ThemeSwitcher from '@/components/ThemeSwitcher.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'

const { t } = useI18n()
const username = ref('admin')
const password = ref('')
const totp = ref('')
const captchaAnswer = ref('')
const captchaToken = ref('')
const captchaImage = ref('')
const captchaRequired = ref(false)
const needTotp = ref(false)
const locked = ref(false)
const remainingSeconds = ref(0)
const loading = ref(false)
const captchaLoading = ref(false)
const statusLoading = ref(false)

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const $q = useQuasar()

let countdownTimer = null

const countdownLabel = computed(() => {
  const total = Math.max(0, remainingSeconds.value | 0)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  if (h > 0) {
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
  }
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})

function applyStatus (data) {
  if (!data) return
  locked.value = !!data.locked
  remainingSeconds.value = Number(data.remaining_seconds || 0)
  captchaRequired.value = !!data.captcha_required && !locked.value
  if (locked.value) {
    startCountdown()
  } else {
    stopCountdown()
  }
}

function startCountdown () {
  stopCountdown()
  countdownTimer = setInterval(async () => {
    if (remainingSeconds.value > 0) {
      remainingSeconds.value -= 1
    }
    if (remainingSeconds.value <= 0) {
      stopCountdown()
      locked.value = false
      await refreshStatus()
    }
  }, 1000)
}

function stopCountdown () {
  if (countdownTimer) {
    clearInterval(countdownTimer)
    countdownTimer = null
  }
}

async function refreshStatus () {
  statusLoading.value = true
  try {
    const data = await auth.loginStatus()
    applyStatus(data)
    if (captchaRequired.value && !captchaImage.value) {
      await reloadCaptcha()
    }
  } catch {
    // ignore status errors on login page
  } finally {
    statusLoading.value = false
  }
}

async function reloadCaptcha () {
  captchaLoading.value = true
  try {
    const data = await auth.fetchCaptcha()
    captchaToken.value = data.token || ''
    captchaImage.value = data.image || ''
    captchaAnswer.value = ''
  } catch (e) {
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('auth.captchaLoadError')
    })
  } finally {
    captchaLoading.value = false
  }
}

function applyLoginError (body) {
  if (!body) return
  applyStatus(body)
  const code = body.code
  if (code === 'totp_required' || code === 'totp_invalid') {
    needTotp.value = true
  }
  if ((code === 'captcha_required' || code === 'captcha_invalid' || body.captcha_required) && !locked.value) {
    captchaRequired.value = true
    reloadCaptcha()
  }
}

async function onSubmit () {
  loading.value = true
  try {
    const extras = {}
    if (captchaRequired.value) {
      extras.captcha_token = captchaToken.value
      extras.captcha_answer = captchaAnswer.value
    }
    if (needTotp.value) {
      extras.totp = totp.value
    }
    await auth.login(username.value, password.value, extras)
    router.replace(route.query.redirect || { name: 'dashboard' })
  } catch (e) {
    const body = e?.response?.data
    applyLoginError(body)
    $q.notify({
      type: 'negative',
      message: body?.message || body?.errors?.username?.[0] || t('auth.loginError')
    })
  } finally {
    loading.value = false
  }
}

onMounted(refreshStatus)
onBeforeUnmount(stopCountdown)
</script>

<style scoped>
.login-card {
  width: 360px;
  max-width: 92vw;
}
.app-logo {
  border-radius: var(--surface-radius);
  display: block;
  flex-shrink: 0;
}
.captcha-img {
  height: 56px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
  background: #1c2028;
}
.lockout-box {
  padding: 12px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
  background: var(--surface-bg);
}
.countdown {
  letter-spacing: 0.08em;
}
.mono {
  font-family: var(--theme-mono, ui-monospace, monospace);
}
</style>
