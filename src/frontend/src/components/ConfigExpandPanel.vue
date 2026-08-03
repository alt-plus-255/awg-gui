<template>
  <div>
    <div class="row items-center q-mb-sm">
      <div v-if="showTitle" class="text-subtitle1 col">{{ t('configs.peersOfConfig', { name: config.name }) }}</div>
      <q-space v-else />
      <q-btn
        flat
        dense
        icon="refresh"
        :label="t('common.refresh')"
        class="q-mr-sm"
        :loading="peerState?.liveLoading"
        @click="ctx.refreshConfigLive(config.id)"
      />
      <q-btn color="primary" dense icon="add" :label="t('configs.addPeer')" @click="ctx.openAddPeer(config)" />
    </div>

    <div v-if="config.type === 'virtual_network'" class="text-caption text-grey-5 q-mb-sm">
      <template v-if="config.vn_policy === 'deny_all'">
        {{ t('configs.isolationModeHint') }}
      </template>
      <template v-else>
        {{ t('configs.lanRouterHint') }}
      </template>
    </div>

    <q-banner
      v-if="peerState?.statsAvailable === false"
      dense
      rounded
      class="bg-warning text-dark q-mb-sm"
    >
      {{ t('configs.statsUnavailable') }}
    </q-banner>

    <q-table
      :rows="peerState?.peers || []"
      :columns="peerColumns"
      row-key="membership_id"
      flat
      dense
      :loading="peerState?.loading"
      class="bg-transparent"
      :rows-per-page-options="[10, 25, 0]"
      :no-data-label="t('configs.noPeers')"
    >
      <template #body-cell-client_allowed_ips="peerProps">
        <q-td :props="peerProps">
          <div class="ellipsis" style="max-width: 320px;" :title="peerProps.row.client_allowed_ips">
            {{ peerProps.row.client_allowed_ips }}
          </div>
        </q-td>
      </template>
      <template #body-cell-online="peerProps">
        <q-td :props="peerProps">
          <q-badge :color="peerProps.row.online ? 'positive' : 'grey-8'">
            {{ peerProps.row.online != null ? (peerProps.row.online ? t('common.online') : t('common.offline')) : '—' }}
          </q-badge>
        </q-td>
      </template>
      <template #body-cell-latest_handshake_human="peerProps">
        <q-td :props="peerProps">
          <template v-if="ctx.formatHandshake(peerProps.row.latest_handshake_human)">
            <div class="text-no-wrap">{{ ctx.formatHandshake(peerProps.row.latest_handshake_human).date }}</div>
            <div class="text-no-wrap">{{ ctx.formatHandshake(peerProps.row.latest_handshake_human).time }}</div>
          </template>
          <template v-else>—</template>
        </q-td>
      </template>
      <template #body-cell-transfer_rx="peerProps">
        <q-td :props="peerProps">{{ peerProps.row.transfer_rx != null ? ctx.formatBytes(peerProps.row.transfer_rx) : '—' }}</q-td>
      </template>
      <template #body-cell-transfer_tx="peerProps">
        <q-td :props="peerProps">{{ peerProps.row.transfer_tx != null ? ctx.formatBytes(peerProps.row.transfer_tx) : '—' }}</q-td>
      </template>
      <template #body-cell-traffic_rx_total="peerProps">
        <q-td :props="peerProps">{{ ctx.formatBytes(peerProps.row.traffic_rx_total) }}</q-td>
      </template>
      <template #body-cell-traffic_tx_total="peerProps">
        <q-td :props="peerProps">{{ ctx.formatBytes(peerProps.row.traffic_tx_total) }}</q-td>
      </template>
      <template #body-cell-enabled="peerProps">
        <q-td :props="peerProps">
          <div class="row items-center no-wrap q-gutter-xs">
            <q-toggle
              :model-value="peerProps.row.enabled"
              dense
              color="positive"
              :disable="ctx.isPeerToggling(config.id, peerProps.row.client_id)"
              @update:model-value="(v) => ctx.togglePeer(config, peerProps.row, v)"
            />
            <q-spinner-dots
              v-if="ctx.isPeerToggling(config.id, peerProps.row.client_id)"
              size="20px"
              color="primary"
            />
          </div>
        </q-td>
      </template>
      <template #body-cell-actions="peerProps">
        <q-td :props="peerProps">
          <q-btn flat dense icon="history" :title="t('configs.handshakeLogs')" @click="ctx.openHandshakeLogs(config, peerProps.row)" />
          <q-btn flat dense icon="restart_alt" :title="t('configs.resetTraffic')" @click="ctx.resetPeerTraffic(config, peerProps.row)" />
          <q-btn flat dense icon="edit" :title="t('common.edit')" @click="ctx.openEditPeer(config, peerProps.row)" />
          <q-btn flat dense icon="qr_code_2" title="QR" @click="ctx.openShare(config, peerProps.row)" />
          <q-btn flat dense icon="download" :title="t('dashboard.configTooltip')" @click="ctx.downloadConf(config, peerProps.row)" />
          <q-btn flat dense color="warning" icon="link_off" :title="t('configs.detachFromConfig')" @click="ctx.detachPeer(config, peerProps.row)" />
          <q-btn flat dense color="negative" icon="delete" :title="t('configs.deletePeerFully')" @click="ctx.deletePeer(peerProps.row)" />
        </q-td>
      </template>
    </q-table>

    <div v-if="config.type === 'virtual_network' && config.vn_policy === 'deny_all'" class="q-mt-lg">
      <div class="row items-center q-mb-xs">
        <div class="text-subtitle1 col">{{ t('configs.accessRules') }}</div>
        <q-btn flat dense icon="add" :label="t('configs.addRule')" class="q-mr-sm" @click="ctx.addRule(config.id)" />
        <q-btn
          color="primary"
          dense
          icon="save"
          :label="t('configs.saveRules')"
          :loading="zoneState?.saving"
          :disable="!zoneState?.dirty"
          @click="ctx.saveZones(config)"
        />
      </div>
      <div class="text-caption text-grey-5 q-mb-sm">
        {{ t('configs.rulesHintLeftToRight') }} <br>
        {{ t('configs.rulesHintTunnelOnly') }} <br>
        {{ t('configs.rulesHintMasquerade') }} <br>
        {{ t('configs.peerOutsideRules') }}
      </div>

      <q-banner
        v-if="ctx.isolatedPeers(config.id).length"
        dense
        rounded
        class="bg-blue-grey-9 text-grey-4 q-mb-sm"
      >
        {{ t('configs.isolatedOutsideRules', { names: ctx.isolatedPeers(config.id).join(', ') }) }}
      </q-banner>

      <div v-if="!(zoneState?.rules || []).length" class="text-caption text-grey-6 q-mb-sm">
        {{ t('configs.noRulesYet') }}
      </div>

      <div
        v-for="(rule, rIdx) in zoneState?.rules || []"
        :key="rIdx"
        class="row q-col-gutter-sm items-center q-mb-sm"
      >
        <div class="col">
          <q-select
            v-model="rule.src_client_ids"
            :options="ctx.ruleMemberOptions(config.id, rule.dest_client_ids)"
            multiple
            use-chips
            emit-value
            map-options
            option-value="client_id"
            option-label="name"
            :label="t('configs.whoWalks')"
            filled
            dense
            @update:model-value="ctx.markZonesDirty(config.id)"
          />
        </div>
        <div class="col-auto text-h6 text-grey-5">→</div>
        <div class="col">
          <q-select
            v-model="rule.dest_client_ids"
            :options="ctx.ruleMemberOptions(config.id, rule.src_client_ids)"
            multiple
            use-chips
            emit-value
            map-options
            option-value="client_id"
            option-label="name"
            :label="t('configs.wherePeerSubnet')"
            filled
            dense
            @update:model-value="ctx.markZonesDirty(config.id)"
          />
        </div>
        <div class="col-auto">
          <q-btn flat dense icon="close" color="negative" :title="t('configs.deleteRule')" @click="ctx.removeRule(config.id, rIdx)" />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, inject, unref } from 'vue'
import { useI18n } from 'vue-i18n'
import { CONFIG_EXPAND_KEY } from '@/composables/configExpandContext'

const props = defineProps({
  config: { type: Object, required: true },
  showTitle: { type: Boolean, default: true }
})

const { t } = useI18n()
const ctx = inject(CONFIG_EXPAND_KEY)

const peerState = computed(() => ctx.peersState[props.config.id])
const zoneState = computed(() => ctx.zonesState[props.config.id])
const peerColumns = computed(() => unref(ctx.peerColumns))
</script>
