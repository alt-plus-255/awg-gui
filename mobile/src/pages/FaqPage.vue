<template>
  <q-page class="page-pad">
    <div class="text-h5 text-weight-bold q-mb-sm">{{ t('faq.title') }}</div>
    <p class="text-body2 text-grey-4 q-mb-lg">{{ t('faq.intro') }}</p>

    <q-card flat bordered class="faq-section bg-dark">
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">{{ t('faq.reqTitle') }}</div>
        <div class="text-body2 text-grey-4 q-mt-xs">{{ t('faq.reqBody') }}</div>
      </q-card-section>
    </q-card>

    <q-card flat bordered class="faq-section bg-dark">
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">{{ t('faq.portsTitle') }}</div>
        <div class="text-body2 text-grey-4 q-mt-xs">{{ t('faq.portsBody') }}</div>
      </q-card-section>
    </q-card>

    <q-card flat bordered class="faq-section bg-dark">
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">{{ t('faq.cmdTitle') }}</div>
        <pre class="terminal-log q-mt-sm" style="min-height: auto; max-height: none">{{ t('faq.cmdBody') }}</pre>
      </q-card-section>
    </q-card>

    <q-card flat bordered class="faq-section bg-dark">
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">{{ t('faq.kernelTitle') }}</div>
        <div class="text-body2 text-grey-4 q-mt-xs">{{ t('faq.kernelBody') }}</div>
      </q-card-section>
    </q-card>

    <q-card flat bordered class="faq-section bg-dark">
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">{{ t('faq.securityTitle') }}</div>
        <div class="text-body2 text-grey-4 q-mt-xs">{{ t('faq.securityBody') }}</div>
      </q-card-section>
    </q-card>

    <q-card flat bordered class="faq-section bg-dark">
      <q-card-section>
        <div class="text-subtitle1 text-weight-medium">{{ t('faq.afterTitle') }}</div>
        <div class="text-body2 text-grey-4 q-mt-xs">{{ t('faq.afterBody') }}</div>
      </q-card-section>
    </q-card>

    <q-btn
      class="q-mt-lg full-width"
      color="primary"
      unelevated
      :label="t('faq.cta')"
      :to="{ name: 'install' }"
    />
  </q-page>
</template>

<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
