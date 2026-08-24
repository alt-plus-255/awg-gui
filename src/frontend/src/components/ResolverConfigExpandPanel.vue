<template>
  <div v-if="form" class="expand-inner" :class="{ 'expand-inner--modal': !showTitle }">
    <div class="row items-center q-mb-md">
      <div v-if="showTitle" class="col text-subtitle1">{{ t('resolver.settingsFor', { name: config.name }) }}</div>
      <q-space v-else />
      <q-toggle
        v-model="form.resolver_enabled"
        color="primary"
        :label="form.resolver_enabled ? t('resolver.resolverEnabled') : t('resolver.resolverDisabled')"
        :disable="saving || !config.enabled"
      />
    </div>

    <q-banner
      v-if="!config.enabled"
      dense
      rounded
      class="q-mb-md text-warning surface-warn-bg"
    >
      {{ t('resolver.configDisabledBanner') }}
    </q-banner>

    <q-select
      v-model="form.connection_id"
      :options="connectionOptions"
      :label="form.resolver_enabled ? t('resolver.connectionRequired') : t('resolver.connectionOptional')"
      emit-value
      map-options
      filled
      :clearable="!form.resolver_enabled"
      class="q-mb-md"
      :disable="!connectionOptions.length"
      :rules="form.resolver_enabled
        ? [v => !!v || t('resolver.selectConnection')]
        : []"
      lazy-rules
    >
      <template #no-option>
        <q-item>
          <q-item-section class="text-grey-5">
            {{ t('resolver.noConnections') }}
            <router-link :to="{ name: 'resolver-connections' }" class="text-primary">{{ t('resolver.create') }}</router-link>
          </q-item-section>
        </q-item>
      </template>
    </q-select>

    <q-input
      v-model="form.resolver_dns"
      label="DNS (sing-box / upstream)"
      filled
      dense
      class="q-mb-md"
      placeholder="1.1.1.1"
    />

    <q-checkbox
      v-model="form.resolver_reject_quic"
      :label="t('resolver.blockQuic')"
      dense
      class="q-mb-md"
    />

    <div class="row q-col-gutter-sm q-mb-md">
      <div
        v-for="item in selectableLists"
        :key="item.tag"
        class="col-12 col-sm-6 col-md-4 col-lg-3"
      >
        <q-checkbox
          v-model="form.community_lists"
          :val="item.tag"
          :label="item.label"
          dense
          :disable="isListDisabled(config.id, item)"
          @update:model-value="onListsChange(config.id, item)"
        />
      </div>
    </div>

    <div class="row q-col-gutter-md q-mb-md">
      <div class="col-12 col-md-6">
        <TagListInput
          v-model="form.user_domains"
          :label="t('resolver.customDomains')"
          placeholder="example.com"
          :empty-hint="t('resolver.noDomains')"
          :normalize="normalizeDomain"
          :validate="validateDomain"
        />
      </div>
      <div class="col-12 col-md-6">
        <TagListInput
          v-model="form.user_subnets"
          :label="t('resolver.customSubnets')"
          :placeholder="t('resolver.subnetsPlaceholder')"
          :empty-hint="t('resolver.noSubnets')"
          :normalize="normalizeSubnet"
          :validate="validateSubnet"
        />
      </div>
    </div>

    <div v-if="form.resolver_enabled" class="text-caption text-grey-5 q-mb-md mono">
      DNS: {{ config.gateway_ip }} · AllowedIPs: {{ previewAllowed }}
    </div>

    <div class="row q-gutter-sm items-center">
      <q-btn
        color="primary"
        :label="t('common.save')"
        :loading="saving"
        :disable="!dirty"
        @click="$emit('save', config.id)"
      />
      <div v-if="config.resolver_updated_at" class="text-caption text-grey-6">
        {{ t('resolver.appliedAt', { ts: formatTs(config.resolver_updated_at) }) }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import TagListInput from '@/components/TagListInput.vue'

defineProps({
  config: { type: Object, required: true },
  form: { type: Object, default: null },
  connectionOptions: { type: Array, default: () => [] },
  selectableLists: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  dirty: { type: Boolean, default: false },
  previewAllowed: { type: String, default: '' },
  showTitle: { type: Boolean, default: true },
  isListDisabled: { type: Function, required: true },
  onListsChange: { type: Function, required: true },
  normalizeDomain: { type: Function, required: true },
  validateDomain: { type: Function, required: true },
  normalizeSubnet: { type: Function, required: true },
  validateSubnet: { type: Function, required: true },
  formatTs: { type: Function, required: true }
})

defineEmits(['save'])

const { t } = useI18n()
</script>

<style scoped>
.expand-inner {
  border-left: 2px solid var(--surface-border);
  margin-left: 8px;
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
}
.expand-inner :deep(.q-banner),
.expand-inner :deep(.q-banner__content) {
  min-width: 0;
  max-width: 100%;
  white-space: normal;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.expand-inner--modal {
  border-left: none;
  margin-left: 0;
}
</style>
