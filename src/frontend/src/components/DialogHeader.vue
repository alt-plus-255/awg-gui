<template>
  <q-card-section class="dialog-header row items-center q-pb-sm">
    <div class="col">
      <div class="text-h6">{{ title }}</div>
      <div v-if="subtitle" class="text-caption text-grey-5">{{ subtitle }}</div>
    </div>
    <slot name="extra" />
    <q-btn
      v-if="showClose"
      v-close-popup
      flat
      round
      icon="close"
      class="dialog-header__close"
      :aria-label="t('common.close')"
    />
  </q-card-section>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  alwaysShowClose: { type: Boolean, default: false }
})

const { t } = useI18n()
const $q = useQuasar()

const showClose = computed(() => props.alwaysShowClose || $q.screen.lt.md)
</script>
