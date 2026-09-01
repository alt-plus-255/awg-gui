<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md q-col-gutter-y-sm">
        <div class="col-12 col-md">
          <div class="row items-center no-wrap">
            <div class="col">
              <div class="text-h5">{{ t('configs.title') }}</div>
            </div>
            <div class="col-auto lt-md">
              <q-btn flat dense icon="refresh" :label="t('common.refresh')" @click="load" :loading="loading" />
            </div>
          </div>
        </div>
        <div class="col-12 col-md-auto">
          <div class="row items-center configs-page-actions">
            <q-btn flat icon="refresh" :label="t('common.refresh')" class="q-mr-sm gt-sm" @click="load" :loading="loading" />
            <div class="configs-page-primary-actions">
              <q-btn
                outline
                color="warning"
                icon="restart_alt"
                :label="t('configs.restartAwg')"
                :loading="restartBusy"
                :disable="restartBusy"
                @click="confirmRestartAwg"
              />
              <q-btn color="primary" icon="add" :label="t('configs.createConfig')" @click="openCreate" />
            </div>
          </div>
        </div>
      </div>

      <q-table
        :rows="configs"
        :columns="columns"
        row-key="id"

        flat
        :loading="loading"
        class="bg-transparent q-mb-lg"
        :rows-per-page-options="[10, 25, 0]"
      >
        <template #body="props">
          <q-tr :props="props">
            <q-td auto-width>
              <q-btn
                flat
                dense
                round
                :icon="expandBtnIcon(props)"
                @click="toggleExpand(props)"
              />
            </q-td>
            <q-td key="name" :props="props">{{ props.row.name }}</q-td>
            <q-td key="type" :props="props">
              <div class="config-type-badges column items-start q-gutter-y-xs">
                <q-badge :color="props.row.type === 'virtual_network' ? 'info' : 'primary'">
                  {{ props.row.type === 'virtual_network' ? t('configs.typeVirtualNetwork') : t('configs.typeServer') }}
                </q-badge>
                <q-badge color="grey-7">
                  {{ props.row.protocol_label || protocolLabel(props.row.protocol_version) }}
                </q-badge>
                <q-badge
                  v-if="props.row.type === 'virtual_network'"
                  :color="props.row.vn_policy === 'deny_all' ? 'deep-orange' : 'grey-8'"
                >
                  {{ props.row.vn_policy === 'deny_all' ? t('configs.badgeIsolation') : t('configs.badgeAllowAll') }}
                </q-badge>
                <q-badge
                  v-if="props.row.resolver_enabled"
                  color="deep-purple"
                >
                  {{ t('configs.badgeResolver') }}
                </q-badge>
              </div>
            </q-td>
            <q-td key="iface" :props="props">{{ props.row.iface }}</q-td>
            <q-td key="listen_port" :props="props">{{ props.row.listen_port }}</q-td>
            <q-td key="internal_subnet" :props="props">{{ props.row.internal_subnet }}</q-td>
            <q-td key="peers_count" :props="props">{{ props.row.peers_count }}</q-td>
            <q-td key="enabled" :props="props">
              <q-badge :color="props.row.enabled ? 'positive' : 'grey-8'">
                {{ props.row.enabled ? t('common.on') : t('common.off') }}
              </q-badge>
            </q-td>
            <q-td key="actions" :props="props">
              <q-btn flat dense icon="history" :title="t('configs.handshakeLogs')" @click="openHandshakeLogs(props.row)" />
              <q-btn flat dense icon="restart_alt" :title="t('configs.resetTraffic')" @click="resetConfigTraffic(props.row)" />
              <q-btn flat dense icon="description" :title="t('configs.showConf')" @click="showServerConf(props.row)" />
              <q-btn flat dense icon="edit" :title="t('common.edit')" @click="openEdit(props.row)" />
              <q-btn flat dense color="negative" icon="delete" :title="t('common.delete')" @click="remove(props.row)" />
            </q-td>
          </q-tr>

          <q-tr
            v-show="!$q.screen.lt.md && props.expand"
            :props="props"
            :key="`e_${props.row.id}`"
            class="q-virtual-scroll--with-prev"
          >
            <q-td colspan="100%" class="expanded-cell">
              <div class="q-pa-md">
                <ConfigExpandPanel :config="props.row" />
              </div>
            </q-td>
          </q-tr>
        </template>
      </q-table>

      <div v-if="unattachedClients.length" class="q-mt-lg">
        <div class="text-subtitle1 q-mb-sm">{{ t('configs.unboundPeers') }}</div>
        <div class="text-caption text-grey-5 q-mb-sm">
          {{ t('configs.unboundPeersHint') }}
        </div>
        <q-table
          :rows="unattachedClients"
          :columns="unattachedColumns"
          row-key="id"

          flat
          dense
          class="bg-transparent"
          :rows-per-page-options="[10, 25, 0]"
        >
          <template #body-cell-comment="props">
            <q-td :props="props">{{ props.row.comment || '—' }}</q-td>
          </template>
          <template #body-cell-actions="props">
            <q-td :props="props">
              <q-btn
                flat
                dense
                color="primary"
                icon="link"
                :title="t('configs.attachToConfig')"
                :disable="!configs.length"
                @click="openAttachUnattached(props.row)"
              />
              <q-btn
                flat
                dense
                color="negative"
                icon="delete"
                :title="t('configs.deletePeerFully')"
                @click="deletePeer({ client_id: props.row.id, name: props.row.name })"
              />
            </q-td>
          </template>
        </q-table>
      </div>
    </div>

    <!-- Mobile: peers/rules in fullscreen dialog instead of row expand -->
    <q-dialog v-model="expandModalOpen" v-bind="mobileDialog" @hide="onExpandModalHide">
      <q-card class="surface-panel dialog-card column no-wrap" style="width: min(960px, 95vw); max-width: 95vw;">
        <DialogHeader
          :title="expandModalRow ? t('configs.peersOfConfig', { name: expandModalRow.name }) : ''"
          always-show-close
        />
        <q-card-section class="col dialog-scroll-body">
          <ConfigExpandPanel v-if="expandModalRow" :config="expandModalRow" :show-title="false" />
        </q-card-section>
      </q-card>
    </q-dialog>

    <!-- Pick config to attach unattached peer -->
    <q-dialog v-model="attachPickOpen" v-bind="mobileDialog" persistent>
      <q-card style="width: min(420px, 95vw); max-width: 95vw;" class="surface-panel dialog-card column no-wrap">
        <DialogHeader
          :title="t('configs.attachPeer')"
          :subtitle="attachClient?.name"
        />
        <q-card-section class="col dialog-scroll-body">
          <q-select
            v-model="attachConfigId"
            :options="configs"
            option-value="id"
            option-label="name"
            emit-value
            map-options
            :label="t('dashboard.colConfig')"

            filled
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat :label="t('common.cancel')" v-close-popup />
          <q-btn color="primary" :label="t('common.next')" :disable="!attachConfigId" @click="confirmAttachPick" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Create / Edit config -->
    <q-dialog v-model="formOpen" v-bind="mobileDialog" persistent>
      <q-card
        :style="editingId
          ? 'width: min(820px, 95vw); max-width: 960px; max-height: 90vh;'
          : 'width: min(520px, 95vw); max-width: 95vw; max-height: 90vh;'"
        class="surface-panel dialog-card column no-wrap"
      >
        <DialogHeader :title="editingId ? t('configs.editConfig') : t('configs.newConfig')" />
        <q-card-section class="col dialog-scroll-body">
          <div class="row q-col-gutter-md">
            <div class="col-12 col-md-6">
              <q-input v-model="form.name" :label="t('common.name')" filled class="q-mb-md" />
            </div>
            <div class="col-12 col-md-6">
              <div class="row items-center q-col-gutter-sm q-mb-md">
                <div :class="editingId ? 'col' : 'col-12'">
                  <q-select
                    v-model="form.type"
                    :options="typeOptions"
                    :label="t('common.type')"
                    emit-value
                    map-options

                    filled
                    :disable="!!editingId"
                  />
                </div>
                <div v-if="editingId" class="col-auto">
                  <q-toggle v-model="form.enabled" :label="t('configs.enabledToggle')" color="positive" />
                </div>
              </div>
            </div>
          </div>

          <div v-if="editingId" class="q-mb-md">
            <q-toggle
              v-model="form.handshake_logging_enabled"
              :label="t('configs.handshakeLoggingToggle')"
              color="primary"
            />
            <div class="text-caption text-grey-5 q-mt-xs">{{ t('configs.handshakeLoggingHint') }}</div>
          </div>

          <q-select
            v-model="form.protocol_version"
            :options="protocolVersionOptions"
            :label="t('configs.protocolVersion')"
            emit-value
            map-options
            filled
            class="q-mb-md"
            :hint="editingId
              ? t('configs.protocolVersionEditHint')
              : t('configs.protocolVersionHint')"
            @update:model-value="onProtocolVersionChange"
          />

          <q-select
            v-model="form.client_import_name_style"
            :options="clientImportNameOptions"
            :label="t('configs.clientImportName')"
            :hint="t('configs.clientImportNameHint')"
            emit-value
            map-options
            filled
            class="q-mb-md"
          />

          <q-select
            v-if="form.type === 'virtual_network'"
            v-model="form.vn_policy"
            :options="vnPolicyOptions"
            :label="t('configs.visibilityPolicy')"
            emit-value
            map-options

            filled
            class="q-mb-md"
            :hint="form.vn_policy === 'deny_all'
              ? t('configs.peersIsolatedHint')
              : t('configs.peersAllowAllHint')"
          />

          <div class="row q-col-gutter-md">
            <div :class="editingId ? 'col-12 col-md-4' : 'col-12 col-sm-6'">
              <q-input
                v-model="form.internal_subnet"
                :label="t('configs.internalSubnet')"
                :hint="editingId ? '10.66.66.0/24' : t('configs.subnetFreeHint')"
                :error="!!subnetFieldError"
                :error-message="subnetFieldError || undefined"
                filled
                class="q-mb-md"
              />
            </div>
            <div v-if="editingId" class="col-12 col-md-4">
              <q-input v-model="form.server_address" :label="t('configs.serverAddress')" filled class="q-mb-md" />
            </div>
            <div :class="editingId ? 'col-12 col-md-4' : 'col-12 col-sm-6'">
              <q-input
                v-model.number="form.listen_port"
                type="number"
                :label="t('configs.listenPort')"
                :hint="t('configs.listenPortHint')"
                :error="!!portFieldError"
                :error-message="portFieldError || undefined"
                filled
                class="q-mb-md"
              />
            </div>
          </div>

          <div class="row q-col-gutter-md">
            <div class="col-12 col-md-6">
              <q-input
                v-model="form.peer_dns"
                :label="t('configs.clientDns')"
                :hint="editingRow?.resolver_enabled
                  ? t('configs.dnsOverriddenByResolver')
                  : undefined"

                filled
                class="q-mb-md"
                :disable="!!editingRow?.resolver_enabled"
              />
            </div>
            <div class="col-12 col-md-6">
              <q-input v-model.number="form.persistent_keepalive" type="number" label="PersistentKeepalive" filled class="q-mb-md" />
            </div>
          </div>

          <q-input
            v-if="form.type === 'server'"
            v-model="form.client_allowed_ips"
            :label="t('configs.clientAllowedIps')"
            :hint="editingRow?.resolver_enabled
              ? t('configs.allowedIpsOverriddenByResolver')
              : t('configs.allowedIpsInternetHint')"

            filled
            class="q-mb-md"
            :disable="!!editingRow?.resolver_enabled"
          />
          <q-banner
            v-if="editingRow?.resolver_enabled"
            dense
            rounded
            class="q-mb-md surface-panel-alt"
          >
            {{ t('configs.resolverBanner') }}
          </q-banner>

          <template v-if="editingId && editingRow">
            <div class="text-subtitle2 q-mt-md q-mb-sm">{{ t('configs.configFileOnServer') }}</div>
            <div
              v-for="p in [
                { label: t('configs.onHost'), path: editingRow.host_config_path },
                { label: t('configs.inContainer'), path: editingRow.config_path }
              ]"
              :key="p.label"
              class="row items-center no-wrap q-mb-xs"
            >
              <div class="text-caption text-grey-5 config-path-label">{{ p.label }}</div>
              <div class="config-path-value col">{{ p.path }}</div>
              <q-btn flat dense round size="sm" icon="content_copy" @click="copyPath(p.path)">
                <q-tooltip>{{ t('configs.copyPath') }}</q-tooltip>
              </q-btn>
            </div>
          </template>

          <template v-if="editingId">
            <div class="row items-center q-mt-md q-mb-sm">
              <div class="text-subtitle2">{{ t('configs.awgObfuscation') }}</div>
              <q-space />
              <q-btn
                flat
                dense
                no-caps
                color="primary"
                icon="casino"
                :label="t('configs.generate')"
                :loading="junkGenerating"
                @click="generateJunk"
              />
            </div>
            <div class="row q-col-gutter-sm">
              <div v-if="visibleJunkGroups.jc.length" class="col-12 col-md-3">
                <q-input v-for="k in visibleJunkGroups.jc" :key="k" v-model="form[k]" :label="k.toUpperCase()" filled dense class="q-mb-sm" />
              </div>
              <div v-if="visibleJunkGroups.s.length" class="col-12 col-md-3">
                <q-input v-for="k in visibleJunkGroups.s" :key="k" v-model="form[k]" :label="k.toUpperCase()" filled dense class="q-mb-sm" />
              </div>
              <div v-if="visibleJunkGroups.h.length" class="col-12 col-md-3">
                <q-input v-for="k in visibleJunkGroups.h" :key="k" v-model="form[k]" :label="k.toUpperCase()" filled dense class="q-mb-sm" />
              </div>
            </div>

            <template v-if="visibleJunkGroups.i.length">
              <div class="row items-center q-mt-md q-mb-sm">
                <div class="text-subtitle2">{{ t('configs.cpsTitle') }}</div>
                <q-space />
                <q-select
                  v-model="cpsProtocol"
                  :options="cpsTemplateOptions"
                  emit-value
                  map-options
                  dense
                  filled
                  style="min-width: 160px"
                  class="q-mr-sm"
                  :label="t('configs.cpsTemplate')"
                />
                <q-btn
                  flat
                  dense
                  no-caps
                  color="primary"
                  icon="auto_fix"
                  :label="t('configs.generateCps')"
                  :loading="cpsGenerating"
                  @click="generateCpsOnly"
                />
              </div>
              <div class="text-caption text-grey-5 q-mb-sm">{{ t('configs.cpsHint') }}</div>
              <div
                v-for="k in visibleJunkGroups.i"
                :key="k"
                class="q-mb-sm"
              >
                <q-input
                  v-model="form[k]"
                  :label="k.toUpperCase()"
                  type="textarea"
                  autogrow
                  filled
                  dense
                  class="mono"
                  input-style="font-family: var(--theme-mono); font-size: 12px;"
                  @update:model-value="scheduleCpsValidate"
                />
                <div class="row items-center q-gutter-xs q-mt-xs">
                  <q-badge
                    v-if="cpsFieldStatus[k]"
                    :color="cpsFieldStatus[k].ok ? 'positive' : 'negative'"
                    outline
                  >
                    {{ cpsFieldStatus[k].ok ? t('configs.cpsOk') : t('configs.cpsError') }}
                    <span v-if="cpsFieldStatus[k].length != null"> · {{ cpsFieldStatus[k].length }} B</span>
                  </q-badge>
                  <div
                    v-if="cpsFieldStatus[k]?.errors?.length"
                    class="text-caption text-negative"
                  >
                    {{ cpsFieldStatus[k].errors[0] }}
                  </div>
                </div>
              </div>
            </template>
          </template>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat :label="t('common.cancel')" v-close-popup />
          <q-btn color="primary" :label="t('common.save')" :loading="saving" :disable="!!subnetFieldError || !!portFieldError" @click="saveConfig" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <!-- Add / Edit peer -->
    <q-dialog v-model="peerFormOpen" v-bind="mobileDialog" persistent>
      <q-card style="width: min(460px, 95vw); max-width: 95vw;" class="surface-panel dialog-card column no-wrap">
        <DialogHeader
          :title="editingPeerId ? t('configs.editPeer') : t('configs.addPeer')"
          :subtitle="t('configs.configLabel', { name: activeConfig?.name })"
        />
        <q-card-section class="col dialog-scroll-body">
          <q-btn-toggle
            v-if="!editingPeerId"
            v-model="peerForm.mode"
            :options="[
              { label: t('configs.newPeer'), value: 'new' },
              { label: t('configs.existingPeer'), value: 'existing' }
            ]"
            toggle-color="primary"
            unelevated
            spread
            class="q-mb-md"
          />

          <template v-if="editingPeerId || peerForm.mode === 'new'">
            <q-input v-model="peerForm.name" :label="t('common.name')" filled class="q-mb-md" />
            <q-input v-model="peerForm.comment" :label="t('configs.colComment')" filled class="q-mb-md" />
          </template>

          <q-select
            v-if="!editingPeerId && peerForm.mode === 'existing'"
            v-model="peerForm.vpn_client_id"
            :options="availableClients"
            option-value="id"
            option-label="name"
            emit-value
            map-options
            label="Peer"

            filled
            class="q-mb-md"
          />

          <q-chip v-if="peerForm.address" dense color="primary" class="q-mb-sm">{{ peerForm.address }}</q-chip>

          <template v-if="activeConfig?.type === 'virtual_network'">
            <q-input
              v-model="peerForm.local_subnet"
              :label="t('configs.localSubnetIp')"
              placeholder="192.168.1.0/24"
              :hint="t('configs.lanBehindPeer')"

              filled
              class="q-mb-md"
            />
            <template v-if="activeConfig?.vn_policy !== 'deny_all'">
              <q-select
                v-model="peerForm.excluded_client_ids"
                :options="exclusionOptions"
                multiple
                use-chips
                emit-value
                map-options
                option-value="client_id"
                option-label="name"
                :label="t('configs.excludeNodes')"
                :hint="t('configs.excludeNodesHint')"

                filled
                class="q-mb-md"
              />
              <q-toggle
                v-if="peerForm.excluded_client_ids.length"
                v-model="peerForm.exclusions_mutual"
                :label="t('configs.mutualExclusion')"
                color="primary"
                class="q-mb-md"
              >
                <q-tooltip>
                  {{ t('configs.mutualExclusionHint') }}
                </q-tooltip>
              </q-toggle>
            </template>
            <q-banner v-else dense rounded class="bg-blue-grey-9 text-grey-4 q-mb-md">
              {{ t('configs.peerIsolationHint') }}
            </q-banner>
          </template>
          <template v-else>
            <div class="text-subtitle2 q-mb-sm">{{ t('configs.clientVpnRoutes') }}</div>
            <div
              v-if="!activeConfig?.resolver_enabled"
              class="text-caption text-grey-5 q-mb-sm"
            >
              {{ t('configs.clientVpnRoutesHint') }}
            </div>
            <q-toggle
              v-if="!activeConfig?.resolver_enabled"
              v-model="peerForm.split_tunnel"
              :label="t('configs.peerSplitTunnel')"
              color="primary"
              class="q-mb-sm"
            >
              <q-tooltip>{{ t('configs.peerSplitTunnelHint') }}</q-tooltip>
            </q-toggle>
            <div
              v-else
              class="text-caption text-grey-5 q-mb-sm"
            >
              {{ t('configs.clientVpnRoutesResolverHint') }}
            </div>
            <div v-for="(ip, idx) in peerForm.extra_allowed_ips" :key="idx" class="row q-gutter-sm q-mb-sm items-center">
              <q-input v-model="peerForm.extra_allowed_ips[idx]" label="CIDR" filled dense class="col" />
              <q-btn flat dense icon="close" color="negative" @click="peerForm.extra_allowed_ips.splice(idx, 1)" />
            </div>
            <q-btn flat dense color="primary" icon="add" :label="t('configs.addCidr')" class="q-mb-md" @click="peerForm.extra_allowed_ips.push('')" />

            <q-separator class="q-my-md" />
            <div class="text-subtitle2 q-mb-sm">{{ t('configs.peerForwardFirewall') }}</div>
            <div class="text-caption text-grey-5 q-mb-sm">
              {{ activeConfig?.resolver_enabled ? t('configs.peerForwardFirewallResolverHint') : t('configs.peerForwardFirewallHint') }}
            </div>
            <q-toggle
              v-model="peerForwardRestricted"
              :label="t('configs.peerForwardRestrict')"
              color="primary"
              class="q-mb-sm"
            />
            <template v-if="peerForwardRestricted">
              <div v-for="(ip, idx) in peerForm.forward_allowed_cidrs" :key="'fw-' + idx" class="row q-gutter-sm q-mb-sm items-center">
                <q-input v-model="peerForm.forward_allowed_cidrs[idx]" :label="t('configs.peerForwardDestCidr')" filled dense class="col" />
                <q-btn flat dense icon="close" color="negative" @click="peerForm.forward_allowed_cidrs.splice(idx, 1)" />
              </div>
              <q-btn flat dense color="primary" icon="add" :label="t('configs.addCidr')" class="q-mb-sm" @click="peerForm.forward_allowed_cidrs.push('')" />
              <q-banner v-if="peerForwardRouteWarning" dense rounded class="bg-amber-9 text-grey-2 q-mb-sm">
                {{ peerForwardRouteWarning }}
              </q-banner>
            </template>
          </template>

          <div v-if="peerPreview" class="q-mb-md">
            <div class="text-caption text-grey-5">{{ t('configs.clientConfAllowedIps') }}</div>
            <div class="q-mt-xs">{{ peerPreview }}</div>
          </div>

          <q-toggle v-if="!editingPeerId" v-model="peerForm.use_preshared_key" label="PresharedKey" color="primary" class="q-mb-sm" />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat :label="t('common.cancel')" v-close-popup />
          <q-btn color="primary" :label="editingPeerId ? t('common.save') : t('common.add')" :loading="peerSaving" @click="savePeer" />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <PeerShareDialog
      v-model="shareOpen"
      :config-id="shareTarget.configId"
      :client-id="shareTarget.clientId"
      :peer-name="shareTarget.peerName"
      :config-name="shareTarget.configName"
    />

    <!-- Server .conf -->
    <q-dialog v-model="serverConfOpen" v-bind="mobileDialog" @hide="onServerConfHide">
      <q-card style="width: min(860px, 95vw); max-width: 860px;" class="surface-panel dialog-card column no-wrap">
        <DialogHeader :title="serverConfTitle" />
        <q-card-section class="col dialog-scroll-body">
          <div v-if="serverConfLoading" class="row justify-center q-pa-lg">
            <q-spinner color="primary" size="40px" />
          </div>
          <q-input
            v-else
            v-model="serverConfText"
            type="textarea"
            readonly

            filled
            autogrow
            class="qr-conf"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat :label="t('common.copy')" :disable="!serverConfText" @click="copyServerConf" />
          <q-btn flat :label="t('common.download')" :disable="!serverConfText" @click="downloadServerConf" />
          <q-btn v-if="$q.screen.gt.sm" flat :label="t('common.close')" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="handshakeLogsOpen">
      <q-card
        flat
        style="width: min(720px, 95vw); max-width: 95vw; max-height: 90vh;"
        class="surface-panel dialog-card column no-wrap"
      >
        <DialogHeader :title="handshakeLogsTitle" />
        <q-card-section class="q-pt-none">
          <div class="row items-center q-gutter-sm">
            <div class="text-caption text-grey-5 col">
              {{ t('configs.handshakeLogsSize', {
                used: formatBytes(handshakeLogsMeta.log_bytes),
                limit: formatBytes(handshakeLogsMeta.log_bytes_limit)
              }) }}
            </div>
            <q-btn
              v-if="!handshakeLogsPeer"
              flat
              dense
              color="warning"
              icon="delete_sweep"
              :label="t('configs.handshakeLogsClear')"
              :loading="handshakeLogsClearing"
              @click="clearHandshakeLogs"
            />
            <q-btn flat dense icon="refresh" :loading="handshakeLogsLoading" @click="loadHandshakeLogs(true)" />
          </div>
        </q-card-section>
        <q-card-section class="col dialog-scroll-body q-pt-none">
          <q-table
            :rows="handshakeLogsRows"
            :columns="handshakeLogColumns"
            row-key="id"
            flat
            dense
            :loading="handshakeLogsLoading"
            class="bg-transparent"
            :rows-per-page-options="[0]"
            hide-pagination
            :no-data-label="t('configs.handshakeLogsEmpty')"
          >
            <template #body-cell-handshake_at_human="props">
              <q-td :props="props">
                <template v-if="formatHandshake(props.row.handshake_at_human)">
                  <div class="text-no-wrap">{{ formatHandshake(props.row.handshake_at_human).date }}</div>
                  <div class="text-no-wrap">{{ formatHandshake(props.row.handshake_at_human).time }}</div>
                </template>
                <template v-else>—</template>
              </q-td>
            </template>
          </q-table>
          <div v-if="handshakeLogsMeta.has_more" class="q-mt-sm text-center">
            <q-btn flat dense color="primary" :label="t('configs.handshakeLogsLoadMore')" :loading="handshakeLogsLoading" @click="loadHandshakeLogs(false)" />
          </div>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat :label="t('common.close')" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, onMounted, onUnmounted, provide, reactive, ref } from 'vue'
import {
  mergeLiveIntoPeers,
  onLiveStats,
  refreshLiveStats,
  subscribeLiveStats,
  unsubscribeLiveStats,
  useLiveStatsState
} from '@/composables/useLiveStats'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import { apiErrorMessage } from '@/utils/apiError'
import { copyText } from '@/utils/clipboard'
import { peerConfFilename } from '@/utils/peerConfFilename'
import PeerShareDialog from '@/components/PeerShareDialog.vue'
import DialogHeader from '@/components/DialogHeader.vue'
import ConfigExpandPanel from '@/components/ConfigExpandPanel.vue'
import { useMobileDialog } from '@/composables/useMobileDialog'
import { CONFIG_EXPAND_KEY } from '@/composables/configExpandContext'
import { useSystemStore } from '@/stores/system'

const { t } = useI18n()
const $q = useQuasar()
const mobileDialog = useMobileDialog()
const system = useSystemStore()
const loading = ref(false)
const saving = ref(false)
const restartingAwg = ref(false)
const restartBusy = computed(() => restartingAwg.value || system.restartBusy)
const configs = ref([])
const allClients = ref([])
const protocolVersions = ref([])
const protocolVersionsDefault = ref('3.1')
const cpsProtocol = ref('quic')
const cpsTemplates = ref([])
const cpsFieldStatus = reactive({})
const junkGenerating = ref(false)
const cpsGenerating = ref(false)
let cpsValidateTimer = null
let previousProtocolVersion = '3.1'
let skipProtocolVersionConfirm = false

const formOpen = ref(false)
const editingId = ref(null)
const editingRow = ref(null)
const junkKeys = ['jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4', 'h1', 'h2', 'h3', 'h4', 'i1', 'i2', 'i3', 'i4', 'i5']
const typeOptions = computed(() => [
  { label: t('configs.typeServer'), value: 'server' },
  { label: t('configs.typeVirtualNetwork'), value: 'virtual_network' }
])
const vnPolicyOptions = computed(() => [
  { label: t('configs.policyAllowAll'), value: 'allow_all' },
  { label: t('configs.policyDenyAll'), value: 'deny_all' }
])
const protocolVersionOptions = computed(() =>
  protocolVersions.value.map((v) => ({
    label: v.label,
    value: v.id
  }))
)

const clientImportNameOptions = computed(() => [
  { label: t('configs.clientImportNamePeer'), value: 'peer_name' },
  { label: t('configs.clientImportNameVersionHost'), value: 'version_host' }
])

const form = reactive({
  name: '',
  type: 'server',
  protocol_version: '3.1',
  client_import_name_style: 'peer_name',
  vn_policy: 'allow_all',
  internal_subnet: '10.66.66.0/24',
  server_address: '',
  listen_port: 51820,
  peer_dns: '1.1.1.1',
  client_allowed_ips: '0.0.0.0/0, ::/0',
  persistent_keepalive: 25,
  enabled: true,
  handshake_logging_enabled: false,
  jc: '', jmin: '', jmax: '',
  s1: '', s2: '', s3: '', s4: '',
  h1: '', h2: '', h3: '', h4: '',
  i1: '', i2: '', i3: '', i4: '', i5: ''
})

const cpsTemplateOptions = computed(() =>
  (cpsTemplates.value.length
    ? cpsTemplates.value
    : [
        { id: 'quic', label: 'QUIC' },
        { id: 'dns', label: 'DNS' },
        { id: 'stun', label: 'STUN' },
        { id: 'sip', label: 'SIP' },
        { id: 'dtls', label: 'DTLS' },
        { id: 'rtp', label: 'RTP' },
        { id: 'random', label: 'Random' }
      ]
  ).map((t) => ({ label: t.label, value: t.id }))
)

function supportedParamsForVersion (versionId) {
  const found = protocolVersions.value.find((v) => v.id === versionId)
  if (found?.supported_params?.length) return found.supported_params
  if (editingRow.value?.supported_params?.length && editingRow.value.protocol_version === versionId) {
    return editingRow.value.supported_params
  }
  // Fallback until API loads: assume latest full set
  return junkKeys
}

const formSupportedParams = computed(() => supportedParamsForVersion(form.protocol_version))

const visibleJunkGroups = computed(() => {
  const supported = new Set(formSupportedParams.value)
  return {
    jc: ['jc', 'jmin', 'jmax'].filter((k) => supported.has(k)),
    s: ['s1', 's2', 's3', 's4'].filter((k) => supported.has(k)),
    h: ['h1', 'h2', 'h3', 'h4'].filter((k) => supported.has(k)),
    i: ['i1', 'i2', 'i3', 'i4', 'i5'].filter((k) => supported.has(k))
  }
})

function protocolLabel (versionId) {
  const found = protocolVersions.value.find((v) => v.id === versionId)
  return found?.label || (versionId ? `AmneziaWG ${versionId}` : 'AmneziaWG')
}
const columns = computed(() => [
  { name: 'expand', label: '', field: 'expand', align: 'left' },
  { name: 'name', label: t('configs.colName'), field: 'name', align: 'left', sortable: true },
  { name: 'type', label: t('configs.colType'), field: (row) => row.type === 'virtual_network' ? t('configs.typeVirtualNetwork') : t('configs.typeServer'), align: 'left' },
  { name: 'iface', label: 'Interface', field: 'iface', align: 'left' },
  { name: 'listen_port', label: t('configs.colPort'), field: 'listen_port', align: 'left' },
  { name: 'internal_subnet', label: t('configs.colSubnet'), field: 'internal_subnet', align: 'left' },
  { name: 'peers_count', label: t('configs.colPeers'), field: 'peers_count', align: 'right' },
  { name: 'enabled', label: t('configs.colStatus'), field: 'enabled', align: 'left' },
  { name: 'actions', label: t('configs.colActions'), field: 'actions', align: 'right' }
])

const peerColumns = computed(() => [
  { name: 'name', label: 'Peer', field: 'name', align: 'left', sortable: true },
  { name: 'address', label: 'Tunnel IP', field: 'address', align: 'left' },
  { name: 'client_allowed_ips', label: t('configs.colAllowedIps'), field: 'client_allowed_ips', align: 'left' },
  { name: 'online', label: t('configs.colStatus'), field: 'online', align: 'left' },
  { name: 'latest_handshake_human', label: 'Handshake', field: (row) => row.latest_handshake_human || '—', align: 'left' },
  { name: 'transfer_rx', label: 'RX', field: 'transfer_rx', align: 'right' },
  { name: 'transfer_tx', label: 'TX', field: 'transfer_tx', align: 'right' },
  { name: 'traffic_rx_total', label: t('configs.colTotalRx'), field: 'traffic_rx_total', align: 'right' },
  { name: 'traffic_tx_total', label: t('configs.colTotalTx'), field: 'traffic_tx_total', align: 'right' },
  { name: 'enabled', label: t('configs.colEnabled'), field: 'enabled', align: 'left' },
  { name: 'actions', label: t('configs.colActions'), field: 'actions', align: 'right' }
])

const handshakeLogColumns = computed(() => [
  { name: 'peer_name', label: 'Peer', field: (row) => row.peer_name || '—', align: 'left' },
  { name: 'handshake_at_human', label: t('configs.colHandshakeAt'), field: 'handshake_at_human', align: 'left' },
  { name: 'endpoint', label: t('configs.colEndpoint'), field: (row) => row.endpoint || '—', align: 'left' }
])

const unattachedColumns = computed(() => [
  { name: 'name', label: 'Peer', field: 'name', align: 'left', sortable: true },
  { name: 'comment', label: t('configs.colComment'), field: 'comment', align: 'left' },
  { name: 'actions', label: t('configs.colActions'), field: 'actions', align: 'right' }
])

// peersState[configId] = { loading, liveLoading, peers, statsAvailable }
const peersState = reactive({})
const liveState = useLiveStatsState()
const subscribedLiveConfigs = reactive(new Set())
const peerToggling = reactive(new Set())
// zonesState[configId] = { rules, baseline, dirty, saving }
const zonesState = reactive({})
const expandedIds = reactive(new Set())
const expandModalOpen = ref(false)
const expandModalId = ref(null)
const expandModalRow = computed(() =>
  expandModalId.value == null
    ? null
    : (configs.value.find(c => c.id === expandModalId.value) || null)
)
let liveOff = null

const peerFormOpen = ref(false)
const peerSaving = ref(false)
const editingPeerId = ref(null)
const activeConfig = ref(null)
const peerForm = reactive({
  mode: 'new',
  vpn_client_id: null,
  name: '',
  comment: '',
  address: '',
  extra_allowed_ips: [],
  local_subnet: '',
  excluded_client_ids: [],
  exclusions_mutual: false,
  forward_policy: 'allow_all',
  forward_allowed_cidrs: [],
  split_tunnel: false,
  use_preshared_key: true
})

const shareOpen = ref(false)
const shareTarget = ref({
  configId: null,
  clientId: null,
  peerName: '',
  configName: ''
})

const serverConfOpen = ref(false)
const serverConfTitle = ref('')
const serverConfText = ref('')
const serverConfFilename = ref('awg.conf')
const serverConfLoading = ref(false)

const handshakeLogsOpen = ref(false)
const handshakeLogsLoading = ref(false)
const handshakeLogsClearing = ref(false)
const handshakeLogsConfig = ref(null)
const handshakeLogsPeer = ref(null)
const handshakeLogsRows = ref([])
const handshakeLogsMeta = reactive({
  log_bytes: 0,
  log_bytes_limit: 10 * 1024 * 1024,
  has_more: false,
  logging_enabled: false
})

const handshakeLogsTitle = computed(() => {
  const config = handshakeLogsConfig.value
  const peer = handshakeLogsPeer.value
  if (!config) return t('configs.handshakeLogs')
  if (peer) {
    return t('configs.handshakeLogsPeerTitle', {
      peer: peer.name || `peer #${peer.client_id}`,
      config: config.name
    })
  }
  return t('configs.handshakeLogsTitle', { name: config.name })
})

const availableClients = computed(() => {
  if (!activeConfig.value) return []
  const attached = new Set((peersState[activeConfig.value.id]?.peers || []).map((p) => p.client_id))
  return allClients.value.filter((c) => !attached.has(c.id))
})

const unattachedClients = computed(() =>
  allClients.value.filter((c) => !(c.memberships || []).length)
)

const attachPickOpen = ref(false)
const attachClient = ref(null)
const attachConfigId = ref(null)

const exclusionOptions = computed(() => {
  if (!activeConfig.value) return []
  const ownId = editingPeerId.value || peerForm.vpn_client_id
  return (peersState[activeConfig.value.id]?.peers || [])
    .filter((p) => p.client_id !== ownId)
    .map((p) => ({ client_id: p.client_id, name: p.name || `peer #${p.client_id}` }))
})

const peerForwardRestricted = computed({
  get () {
    return peerForm.forward_policy === 'restricted'
  },
  set (v) {
    peerForm.forward_policy = v ? 'restricted' : 'allow_all'
    if (v && !peerForm.forward_allowed_cidrs.length) {
      peerForm.forward_allowed_cidrs.push('')
    }
  }
})

const peerForwardRouteWarning = computed(() => {
  if (peerForm.forward_policy !== 'restricted') return ''
  if (!peerForm.split_tunnel) return ''
  const extras = (peerForm.extra_allowed_ips || []).map((x) => String(x || '').trim()).filter(Boolean)
  const fw = (peerForm.forward_allowed_cidrs || []).map((x) => String(x || '').trim()).filter(Boolean)
  if (!fw.length) return ''
  if (!extras.length) return t('configs.peerForwardNoClientRoutes')
  const uncovered = fw.filter((c) => !firewallCidrCoveredByRoutes(c, extras))
  if (!uncovered.length) return ''
  return t('configs.peerForwardRouteMismatch')
})

function configRules (config) {
  const state = zonesState[config.id]
  if (state) return state.rules
  return config.vn_zones?.rules || []
}

// направление правила: 'forward' (own→other), 'reply' (other→own), null
function ruleDirection (rules, ownId, otherId) {
  let forward = false
  let reply = false
  for (const rule of rules || []) {
    const src = rule.src_client_ids || []
    const dest = rule.dest_client_ids || []
    if (src.includes(ownId) && dest.includes(otherId)) forward = true
    if (src.includes(otherId) && dest.includes(ownId)) reply = true
  }
  if (forward) return 'forward'
  if (reply) return 'reply'
  return null
}

const peerPreview = computed(() => {
  if (!activeConfig.value) return ''

  // Server without resolver: split-tunnel preview when enabled
  if (activeConfig.value.type === 'server' && !activeConfig.value.resolver_enabled && peerForm.split_tunnel) {
    const cidrs = (peerForm.extra_allowed_ips || [])
      .map((x) => String(x || '').trim())
      .filter((x) => x && x !== '0.0.0.0/0' && x !== '::/0')
    const ips = []
    // Prefer network-aligned tunnel subnet (Android rejects 10.66.66.1/24 in AllowedIPs)
    let tunnel = String(activeConfig.value.internal_subnet || '').trim()
    if (!tunnel) {
      tunnel = String(activeConfig.value.server_address || '').trim()
      // Best-effort: x.x.x.1/24 → x.x.x.0/24 for common /24 case
      const m = tunnel.match(/^(\d+\.\d+\.\d+)\.(\d+)\/(\d+)$/)
      if (m) {
        const prefix = Number(m[3])
        if (prefix === 24) tunnel = `${m[1]}.0/24`
        else if (prefix === 32) tunnel = `${m[1]}.${m[2]}/32`
        else if (prefix === 16) tunnel = `${m[1].split('.').slice(0, 2).join('.')}.0.0/16`
        else if (prefix === 8) tunnel = `${m[1].split('.')[0]}.0.0.0/8`
      }
    }
    if (tunnel && tunnel !== '0.0.0.0/0' && tunnel !== '::/0') {
      ips.push(tunnel)
    }
    cidrs.forEach((cidr) => {
      if (!ips.includes(cidr)) ips.push(cidr)
    })
    return ips.join(', ')
  }

  if (activeConfig.value.type === 'server' && !activeConfig.value.resolver_enabled) {
    const full = String(activeConfig.value.client_allowed_ips || '').trim()
    return full || '0.0.0.0/0, ::/0'
  }

  if (activeConfig.value.type !== 'virtual_network') return ''
  const denyAll = activeConfig.value.vn_policy === 'deny_all'
  const rules = denyAll ? configRules(activeConfig.value) : null
  const ips = []
  if (peerForm.address) ips.push(peerForm.address)
  const ownId = editingPeerId.value || peerForm.vpn_client_id
  const excluded = peerForm.excluded_client_ids || []
  const peers = peersState[activeConfig.value.id]?.peers || []
  peers.forEach((p) => {
    if (p.client_id === ownId) return
    if (!p.enabled) return
    if (denyAll) {
      if (!ownId) return
      const direction = ruleDirection(rules, ownId, p.client_id)
      if (direction === 'forward') {
        ;(p.extra_allowed_ips || []).forEach((cidr) => {
          if (cidr && !ips.includes(cidr)) ips.push(cidr)
        })
      } else if (direction === 'reply') {
        if (p.address && !ips.includes(p.address)) ips.push(p.address)
      }
      return
    }
    if (excluded.includes(p.client_id)) return
    // взаимное исключение со стороны другого пира
    if (p.exclusions_mutual && ownId && (p.excluded_client_ids || []).includes(ownId)) return
    ;(p.extra_allowed_ips || []).forEach((cidr) => {
      if (cidr && !ips.includes(cidr)) ips.push(cidr)
    })
  })
  return ips.join(', ')
})

function formatBytes (n) {
  const v = Number(n) || 0
  if (v < 1024) return `${v} B`
  if (v < 1024 ** 2) return `${(v / 1024).toFixed(1)} KB`
  if (v < 1024 ** 3) return `${(v / 1024 ** 2).toFixed(1)} MB`
  return `${(v / 1024 ** 3).toFixed(2)} GB`
}

function formatHandshake (iso) {
  if (!iso || iso === '—') return null
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}:\d{2})(.*)$/)
  if (!m) return { date: String(iso), time: '' }
  return { date: `${m[3]}.${m[2]}.${m[1]}`, time: `${m[4]} ${m[5]}`.trim() }
}

function openHandshakeLogs (config, peer = null) {
  handshakeLogsConfig.value = config
  handshakeLogsPeer.value = peer
  handshakeLogsRows.value = []
  handshakeLogsMeta.log_bytes = Number(config.handshake_log_bytes) || 0
  handshakeLogsMeta.log_bytes_limit = Number(config.handshake_log_bytes_limit) || (10 * 1024 * 1024)
  handshakeLogsMeta.has_more = false
  handshakeLogsMeta.logging_enabled = !!config.handshake_logging_enabled
  handshakeLogsOpen.value = true
  loadHandshakeLogs(true)
}

async function loadHandshakeLogs (reset) {
  const config = handshakeLogsConfig.value
  if (!config) return
  handshakeLogsLoading.value = true
  try {
    const params = { per_page: 50 }
    if (!reset && handshakeLogsRows.value.length) {
      params.before_id = handshakeLogsRows.value[handshakeLogsRows.value.length - 1].id
    }
    const peer = handshakeLogsPeer.value
    const url = peer
      ? `/api/configs/${config.id}/peers/${peer.client_id}/handshake-logs`
      : `/api/configs/${config.id}/handshake-logs`
    const { data } = await api.get(url, { params })
    const rows = data.logs || []
    handshakeLogsRows.value = reset ? rows : [...handshakeLogsRows.value, ...rows]
    handshakeLogsMeta.log_bytes = Number(data.log_bytes) || 0
    handshakeLogsMeta.log_bytes_limit = Number(data.log_bytes_limit) || (10 * 1024 * 1024)
    handshakeLogsMeta.has_more = !!data.has_more
    handshakeLogsMeta.logging_enabled = !!data.logging_enabled
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.handshakeLogsLoadError') })
  } finally {
    handshakeLogsLoading.value = false
  }
}

function clearHandshakeLogs () {
  const config = handshakeLogsConfig.value
  if (!config) return
  $q.dialog({
    title: t('configs.handshakeLogsClear'),
    message: t('configs.handshakeLogsClearConfirm'),
    cancel: true,
    persistent: true
  }).onOk(async () => {
    handshakeLogsClearing.value = true
    try {
      await api.delete(`/api/configs/${config.id}/handshake-logs`)
      handshakeLogsRows.value = []
      handshakeLogsMeta.log_bytes = 0
      handshakeLogsMeta.has_more = false
      config.handshake_log_bytes = 0
      $q.notify({ type: 'positive', message: t('configs.handshakeLogsCleared') })
    } catch (e) {
      $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.handshakeLogsClearError') })
    } finally {
      handshakeLogsClearing.value = false
    }
  })
}

function resetPeerTraffic (config, peer) {
  $q.dialog({
    title: t('configs.resetTraffic'),
    message: t('configs.resetTrafficConfirm', { name: peer.name || `peer #${peer.client_id}` }),
    cancel: true,
    persistent: true
  }).onOk(async () => {
    try {
      const { data } = await api.post(`/api/configs/${config.id}/peers/${peer.client_id}/reset-traffic`)
      const membership = data.membership
      const list = peersState[config.id]?.peers || []
      const idx = list.findIndex((p) => p.client_id === peer.client_id)
      if (idx >= 0 && membership) {
        list[idx] = { ...list[idx], ...membership }
      }
      $q.notify({ type: 'positive', message: data.message || t('configs.resetTrafficDone') })
    } catch (e) {
      $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.resetTrafficError') })
    }
  })
}

function resetConfigTraffic (config) {
  $q.dialog({
    title: t('configs.resetTraffic'),
    message: t('configs.resetTrafficConfirmConfig', { name: config.name }),
    cancel: true,
    persistent: true
  }).onOk(async () => {
    try {
      const { data } = await api.post(`/api/configs/${config.id}/reset-traffic`)
      if (peersState[config.id]?.peers) {
        peersState[config.id].peers = peersState[config.id].peers.map((p) => ({
          ...p,
          traffic_rx_total: 0,
          traffic_tx_total: 0,
          traffic_reset_at: new Date().toISOString()
        }))
      }
      $q.notify({ type: 'positive', message: data.message || t('configs.resetTrafficDone') })
    } catch (e) {
      $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.resetTrafficError') })
    }
  })
}

async function load () {
  loading.value = true
  try {
    const [cfgRes, clientsRes, versionsRes, cpsRes] = await Promise.all([
      api.get('/api/configs'),
      api.get('/api/clients'),
      api.get('/api/awg-protocol-versions'),
      api.get('/api/cps/templates').catch(() => ({ data: {} }))
    ])
    configs.value = cfgRes.data.configs || []
    allClients.value = clientsRes.data.clients || []
    protocolVersions.value = versionsRes.data.versions || []
    protocolVersionsDefault.value = versionsRes.data.default || protocolVersions.value.at(-1)?.id || '3.1'
    cpsTemplates.value = cpsRes.data.templates || []
    if (cpsRes.data.default) cpsProtocol.value = cpsRes.data.default
    syncZonesStates()
  } finally {
    loading.value = false
  }
}

function confirmRestartAwg () {
  if (restartBusy.value) {
    $q.notify({
      type: 'warning',
      message: t('common.restartAwgInProgress')
    })
    return
  }

  $q.dialog({
    title: t('configs.restartAwgTitle'),
    message: t('configs.restartAwgConfirm'),
    cancel: { label: t('common.cancel'), flat: true },
    ok: { label: t('configs.restart'), color: 'warning' },
    persistent: true,
  }).onOk(() => {
    restartAwg()
  })
}

async function restartAwg () {
  if (restartBusy.value) return

  restartingAwg.value = true
  if (system.status) {
    system.status = { ...system.status, awg_restarting: true }
  }

  try {
    const { data } = await api.post('/api/settings/restart-awg')
    $q.notify({
      type: data.ok ? 'positive' : 'negative',
      message: data.message || (data.ok ? t('configs.awgRestarted') : t('configs.restartError'))
    })
  } catch (e) {
    const already = e?.response?.status === 409 || e?.response?.data?.already_restarting
    $q.notify({
      type: already ? 'warning' : 'negative',
      message: e?.response?.data?.message || t('configs.restartFailed')
    })
  } finally {
    restartingAwg.value = false
    await system.checkStatus(true)
  }
}

async function reloadConfigs () {
  const [cfgRes, clientsRes] = await Promise.all([
    api.get('/api/configs'),
    api.get('/api/clients')
  ])
  configs.value = cfgRes.data.configs || []
  allClients.value = clientsRes.data.clients || []
  syncZonesStates()
}

async function reloadClients () {
  const { data } = await api.get('/api/clients')
  allClients.value = data.clients || []
}

async function reloadConfigsOnly () {
  const { data } = await api.get('/api/configs')
  configs.value = data.configs || []
  syncZonesStates()
}

function cloneZonesRules (rules) {
  return (rules || []).map((r) => ({
    src_client_ids: [...(r.src_client_ids || [])],
    dest_client_ids: [...(r.dest_client_ids || [])]
  }))
}

// нормализованный снимок для сравнения: порядок id внутри стороны не важен
function snapshotZonesRules (rules) {
  return JSON.stringify((rules || []).map((r) => ({
    src_client_ids: [...(r.src_client_ids || [])].map(Number).sort((a, b) => a - b),
    dest_client_ids: [...(r.dest_client_ids || [])].map(Number).sort((a, b) => a - b)
  })))
}

function initZonesState (config) {
  const rules = cloneZonesRules(config.vn_zones?.rules)
  zonesState[config.id] = {
    rules,
    baseline: snapshotZonesRules(rules),
    dirty: false,
    saving: false
  }
}

// подтягивает свежие правила с сервера, не затирая несохранённые правки
function syncZonesStates () {
  configs.value.forEach((config) => {
    if (config.type !== 'virtual_network') return
    if (!zonesState[config.id] || !zonesState[config.id].dirty) {
      initZonesState(config)
    }
  })
}

function prepareExpand (row) {
  const id = row.id
  expandedIds.add(id)
  void loadPeers(id).then(() => {
    if (!subscribedLiveConfigs.has(id)) {
      subscribedLiveConfigs.add(id)
      subscribeLiveStats([id])
    }
  })
  if (row.type === 'virtual_network' && !zonesState[id]) {
    initZonesState(row)
  }
}

function collapseExpand (id) {
  expandedIds.delete(id)
  if (subscribedLiveConfigs.has(id)) {
    unsubscribeLiveStats([id])
    subscribedLiveConfigs.delete(id)
  }
}

function expandBtnIcon (props) {
  if ($q.screen.lt.md) {
    return expandModalOpen.value && expandModalId.value === props.row.id ? 'close' : 'open_in_full'
  }
  return props.expand ? 'expand_less' : 'expand_more'
}

function toggleExpand (props) {
  if ($q.screen.lt.md) {
    prepareExpand(props.row)
    expandModalId.value = props.row.id
    expandModalOpen.value = true
    return
  }

  props.expand = !props.expand
  const id = props.row.id
  if (props.expand) {
    prepareExpand(props.row)
  } else {
    collapseExpand(id)
  }
}

function onExpandModalHide () {
  if (expandModalId.value != null) {
    collapseExpand(expandModalId.value)
    expandModalId.value = null
  }
}

function applyLiveToConfigPeers (configId) {
  const state = peersState[configId]
  if (!state?.basePeers) return
  state.peers = mergeLiveIntoPeers(state.basePeers, configId)
  if (liveState.byConfig[configId]) {
    state.statsAvailable = liveState.byConfig[configId].stats_available !== false
  }
}

function remergeAllExpandedLive () {
  expandedIds.forEach((id) => applyLiveToConfigPeers(id))
}

async function loadPeers (configId, silent = false) {
  if (!peersState[configId]) {
    peersState[configId] = { loading: false, liveLoading: false, peers: [], basePeers: [], statsAvailable: true }
  }
  const state = peersState[configId]
  if (!silent) state.loading = true
  try {
    const { data } = await api.get(`/api/configs/${configId}/peers`)
    state.basePeers = data.peers || []
    state.peers = mergeLiveIntoPeers(state.basePeers, configId)
  } finally {
    if (!silent) state.loading = false
  }
}

async function refreshConfigLive (configId) {
  if (!peersState[configId]) {
    peersState[configId] = { loading: false, liveLoading: false, peers: [], basePeers: [], statsAvailable: true }
  }
  const state = peersState[configId]
  state.liveLoading = true
  try {
    const data = await refreshLiveStats([configId])
    applyLiveToConfigPeers(configId)
    state.statsAvailable = data.stats_available !== false
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.refreshStatsError') })
  } finally {
    state.liveLoading = false
  }
}

// варианты для поля правила: все пиры конфига, кроме уже выбранных в противоположном поле
function ruleMemberOptions (configId, oppositeIds) {
  const taken = new Set(oppositeIds || [])
  return (peersState[configId]?.peers || [])
    .filter((p) => !taken.has(p.client_id))
    .map((p) => ({ client_id: p.client_id, name: p.name || `peer #${p.client_id}` }))
}

function isolatedPeers (configId) {
  const rules = zonesState[configId]?.rules || []
  const inRules = new Set(rules.flatMap((r) => [...(r.src_client_ids || []), ...(r.dest_client_ids || [])]))
  return (peersState[configId]?.peers || [])
    .filter((p) => !inRules.has(p.client_id))
    .map((p) => p.name || `peer #${p.client_id}`)
}

function updateZonesDirty (configId) {
  const state = zonesState[configId]
  if (!state) return
  state.dirty = snapshotZonesRules(state.rules) !== state.baseline
}

function markZonesDirty (configId) {
  updateZonesDirty(configId)
}

function addRule (configId) {
  const state = zonesState[configId]
  if (!state) return
  state.rules.push({ src_client_ids: [], dest_client_ids: [] })
  updateZonesDirty(configId)
}

function removeRule (configId, index) {
  const state = zonesState[configId]
  if (!state) return
  state.rules.splice(index, 1)
  updateZonesDirty(configId)
}

async function saveZones (config) {
  const state = zonesState[config.id]
  if (!state) return
  for (const rule of state.rules) {
    if (!(rule.src_client_ids || []).length || !(rule.dest_client_ids || []).length) {
      $q.notify({ type: 'negative', message: t('configs.ruleSidesRequired') })
      return
    }
    if (rule.src_client_ids.some((id) => rule.dest_client_ids.includes(id))) {
      $q.notify({ type: 'negative', message: t('configs.peerBothSidesError') })
      return
    }
  }

  state.saving = true
  try {
    await api.put(`/api/configs/${config.id}/zones`, {
      rules: state.rules.map((r) => ({
        src_client_ids: r.src_client_ids || [],
        dest_client_ids: r.dest_client_ids || []
      }))
    })
    state.baseline = snapshotZonesRules(state.rules)
    state.dirty = false
    $q.notify({ type: 'positive', message: t('configs.rulesSaved') })
    await Promise.all([loadPeers(config.id, true), reloadConfigsOnly()])
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.rulesSaveError') })
  } finally {
    state.saving = false
  }
}

function parseCidr (cidr) {
  const m = String(cidr || '').trim().match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/)
  if (!m) return null
  const octets = [Number(m[1]), Number(m[2]), Number(m[3]), Number(m[4])]
  const mask = Number(m[5])
  if (octets.some((o) => o > 255) || mask < 0 || mask > 32) return null
  let ip = ((octets[0] << 24) >>> 0) + (octets[1] << 16) + (octets[2] << 8) + octets[3]
  const maskBits = mask === 0 ? 0 : (~0 << (32 - mask)) >>> 0
  ip = (ip & maskBits) >>> 0
  const network = `${(ip >>> 24) & 255}.${(ip >>> 16) & 255}.${(ip >>> 8) & 255}.${ip & 255}`
  return { ip, mask, key: `${network}/${mask}` }
}

/** True when route CIDR covers all addresses in fw CIDR (e.g. 192.168.1.0/24 contains 192.168.1.13/32). */
function cidrRouteCoversFirewall (routeCidr, fwCidr) {
  const route = parseCidr(routeCidr)
  const fw = parseCidr(fwCidr)
  if (!route || !fw) return false
  if (route.key === fw.key) return true
  if (route.mask > fw.mask) return false
  const routeMaskBits = route.mask === 0 ? 0 : (~0 << (32 - route.mask)) >>> 0
  return ((fw.ip & routeMaskBits) >>> 0) === route.ip
}

function firewallCidrCoveredByRoutes (fwCidr, routeCidrs) {
  const fw = String(fwCidr || '').trim()
  if (!fw) return true
  return routeCidrs.some((route) => {
    const r = String(route || '').trim()
    if (!r) return false
    if (r === fw) return true
    return cidrRouteCoversFirewall(r, fw)
  })
}

function usedSubnetKeys (excludeId = null) {
  const keys = new Set()
  for (const c of configs.value) {
    if (excludeId != null && c.id === excludeId) continue
    const parsed = parseCidr(c.internal_subnet)
    if (parsed) keys.add(parsed.key)
  }
  return keys
}

const PORT_MIN = 51820
const PORT_MAX = 51839

function usedPorts (excludeId = null) {
  const ports = new Set()
  for (const c of configs.value) {
    if (excludeId != null && c.id === excludeId) continue
    const p = Number(c.listen_port)
    if (Number.isInteger(p)) ports.add(p)
  }
  return ports
}

function nextFreeSubnet () {
  const used = usedSubnetKeys()
  for (let third = 66; third <= 254; third++) {
    const cidr = `10.66.${third}.0/24`
    if (!used.has(cidr)) return cidr
  }
  for (let second = 67; second <= 254; second++) {
    for (let third = 0; third <= 254; third++) {
      const cidr = `10.${second}.${third}.0/24`
      if (!used.has(cidr)) return cidr
    }
  }
  return '10.66.66.0/24'
}

function nextFreePort () {
  const used = usedPorts()
  for (let p = PORT_MIN; p <= PORT_MAX; p++) {
    if (!used.has(p)) return p
  }
  return PORT_MIN
}

function subnetValidationError (cidr, excludeId = null) {
  const parsed = parseCidr(cidr)
  if (!parsed) return t('configs.invalidSubnet')
  if (usedSubnetKeys(excludeId).has(parsed.key)) {
    return t('configs.subnetTaken', { subnet: parsed.key })
  }
  return null
}

function portValidationError (port, excludeId = null) {
  const p = Number(port)
  if (!Number.isInteger(p) || p < PORT_MIN || p > PORT_MAX) {
    return t('configs.invalidPort', { min: PORT_MIN, max: PORT_MAX })
  }
  if (usedPorts(excludeId).has(p)) {
    return t('configs.portTaken', { port: p })
  }
  return null
}

const subnetFieldError = computed(() => {
  if (!formOpen.value) return null
  return subnetValidationError(form.internal_subnet, editingId.value)
})

const portFieldError = computed(() => {
  if (!formOpen.value) return null
  return portValidationError(form.listen_port, editingId.value)
})

function resetForm () {
  form.name = ''
  form.type = 'server'
  form.protocol_version = protocolVersionsDefault.value
  previousProtocolVersion = form.protocol_version
  form.client_import_name_style = 'peer_name'
  form.vn_policy = 'allow_all'
  form.internal_subnet = nextFreeSubnet()
  form.server_address = ''
  form.listen_port = nextFreePort()
  form.peer_dns = '1.1.1.1'
  form.client_allowed_ips = '0.0.0.0/0, ::/0'
  form.persistent_keepalive = 25
  form.enabled = true
  form.handshake_logging_enabled = false
  generateJunk()
}

function openCreate () {
  editingId.value = null
  editingRow.value = null
  resetForm()
  formOpen.value = true
}

function onProtocolVersionChange (next) {
  if (skipProtocolVersionConfirm) {
    skipProtocolVersionConfirm = false
    previousProtocolVersion = form.protocol_version
    return
  }
  const prev = previousProtocolVersion
  if (!editingId.value) {
    previousProtocolVersion = form.protocol_version
    generateJunk()
    return
  }
  if (next === prev) return
  $q.dialog({
    title: t('configs.protocolVersionChangeTitle'),
    message: t('configs.protocolVersionChangeConfirm'),
    cancel: { label: t('common.cancel'), flat: true },
    ok: { label: t('configs.protocolVersionChangeOk'), color: 'warning' },
    persistent: true
  }).onOk(() => {
    previousProtocolVersion = form.protocol_version
    generateJunk()
  }).onCancel(() => {
    skipProtocolVersionConfirm = true
    form.protocol_version = prev
  })
}

function randInt (min, max) {
  const range = max - min + 1
  const buf = new Uint32Array(1)
  // rejection sampling, чтобы не было смещения при делении по модулю
  const limit = Math.floor(0x100000000 / range) * range
  do {
    crypto.getRandomValues(buf)
  } while (buf[0] >= limit)
  return min + (buf[0] % range)
}

async function generateCpsOnly (opts = {}) {
  const silent = !!opts.silent
  const supported = new Set(supportedParamsForVersion(form.protocol_version))
  if (!supported.has('i1')) {
    ;['i1', 'i2', 'i3', 'i4', 'i5'].forEach((k) => { form[k] = '' })
    return
  }
  cpsGenerating.value = true
  try {
    const { data } = await api.post('/api/cps/generate', {
      protocol: cpsProtocol.value || 'quic',
      s1: form.s1,
      s2: form.s2,
      s3: form.s3,
      s4: form.s4,
      allow_d: form.protocol_version === '2.0' || form.protocol_version === '3.1'
    })
    form.i1 = data.i1 || ''
    form.i2 = data.i2 || ''
    form.i3 = data.i3 || ''
    form.i4 = data.i4 || ''
    form.i5 = data.i5 || ''
    await validateCpsNow()
    if (!silent) {
      $q.notify({ type: 'info', message: t('configs.cpsGenerated') })
    }
  } catch (e) {
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.cpsGenerateError') })
  } finally {
    cpsGenerating.value = false
  }
}

function scheduleCpsValidate () {
  if (cpsValidateTimer) clearTimeout(cpsValidateTimer)
  cpsValidateTimer = setTimeout(() => { validateCpsNow() }, 400)
}

async function validateCpsNow () {
  const supported = new Set(supportedParamsForVersion(form.protocol_version))
  if (!supported.has('i1')) {
    ;['i1', 'i2', 'i3', 'i4', 'i5'].forEach((k) => { delete cpsFieldStatus[k] })
    return true
  }
  try {
    const { data } = await api.post('/api/cps/validate', {
      i1: form.i1,
      i2: form.i2,
      i3: form.i3,
      i4: form.i4,
      i5: form.i5,
      s1: form.s1,
      s2: form.s2,
      s3: form.s3,
      s4: form.s4,
      allow_d: form.protocol_version === '2.0' || form.protocol_version === '3.1'
    })
    ;['i1', 'i2', 'i3', 'i4', 'i5'].forEach((k) => {
      cpsFieldStatus[k] = data.fields?.[k] || { ok: true, length: 0 }
    })
    return !!data.valid
  } catch {
    return true
  }
}

// Правила: https://docs.amnezia.org/ru/documentation/amnezia-wg/
async function generateJunk () {
  junkGenerating.value = true
  try {
    const supported = new Set(supportedParamsForVersion(form.protocol_version))

    form.jc = String(randInt(1, 10))
    const jmin = randInt(64, 1023)
    form.jmin = String(jmin)
    form.jmax = String(randInt(jmin + 1, 1024))

    const s1 = randInt(0, 64)
    let s2
    do {
      s2 = randInt(0, 64)
    } while (s1 + 56 === s2) // иначе Init(148+S1) совпадает по размеру с Response(92+S2)
    form.s1 = String(s1)
    form.s2 = String(s2)
    form.s3 = supported.has('s3') ? String(randInt(0, 64)) : '0'
    form.s4 = supported.has('s4') ? String(randInt(0, 32)) : '0'

    const hs = new Set()
    while (hs.size < 4) {
      hs.add(randInt(1, 2147483647))
    }
    ;[form.h1, form.h2, form.h3, form.h4] = [...hs].map(String)

    if (supported.has('i1')) {
      await generateCpsOnly({ silent: true })
    } else {
      ;['i1', 'i2', 'i3', 'i4', 'i5'].forEach((k) => { form[k] = '' })
      ;['i1', 'i2', 'i3', 'i4', 'i5'].forEach((k) => { delete cpsFieldStatus[k] })
    }
    $q.notify({
      type: 'info',
      message: t('configs.obfuscationGenerated')
    })
  } finally {
    junkGenerating.value = false
  }
}

function openEdit (row) {
  editingId.value = row.id
  editingRow.value = row
  Object.keys(form).forEach((k) => {
    if (row[k] !== undefined && row[k] !== null) {
      form[k] = row[k]
    } else if (junkKeys.includes(k)) {
      form[k] = ''
    }
  })
  previousProtocolVersion = form.protocol_version
  formOpen.value = true
  scheduleCpsValidate()
}

async function copyPath (path) {
  if (!path) return
  try {
    await copyText(path)
    $q.notify({ type: 'positive', message: t('configs.pathCopied') })
  } catch {
    $q.notify({ type: 'negative', message: t('common.copyFailed') })
  }
}

async function saveConfig () {
  const subnetErr = subnetValidationError(form.internal_subnet, editingId.value)
  if (subnetErr) {
    $q.notify({ type: 'warning', message: subnetErr })
    return
  }
  const portErr = portValidationError(form.listen_port, editingId.value)
  if (portErr) {
    $q.notify({ type: 'warning', message: portErr })
    return
  }
  if (editingId.value && visibleJunkGroups.value.i.length) {
    const ok = await validateCpsNow()
    if (!ok) {
      $q.notify({ type: 'warning', message: t('configs.cpsInvalidSave') })
      return
    }
  }
  saving.value = true
  try {
    const payload = { ...form, cps_protocol: cpsProtocol.value }
    if (editingId.value) {
      await api.put(`/api/configs/${editingId.value}`, payload)
      $q.notify({ type: 'positive', message: t('configs.configUpdated') })
    } else {
      await api.post('/api/configs', payload)
      $q.notify({ type: 'positive', message: t('configs.configCreated') })
    }
    formOpen.value = false
    await load()
  } catch (e) {
    const errors = e?.response?.data?.errors
    const fieldMsg = errors?.internal_subnet?.[0] || errors?.listen_port?.[0] || errors?.i1?.[0]
    $q.notify({ type: 'negative', message: fieldMsg || e?.response?.data?.message || t('common.saveError') })
  } finally {
    saving.value = false
  }
}

async function remove (row) {
  $q.dialog({
    title: t('configs.deleteConfigTitle'),
    message: t('configs.deleteConfigConfirm', { name: row.name }),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    await api.delete(`/api/configs/${row.id}`)
    expandedIds.delete(row.id)
    delete peersState[row.id]
    delete zonesState[row.id]
    await load()
  })
}

function openAddPeer (config) {
  activeConfig.value = config
  editingPeerId.value = null
  peerForm.mode = 'new'
  peerForm.vpn_client_id = null
  peerForm.name = ''
  peerForm.comment = ''
  peerForm.address = ''
  peerForm.extra_allowed_ips = ['']
  peerForm.local_subnet = ''
  peerForm.excluded_client_ids = []
  peerForm.exclusions_mutual = false
  peerForm.forward_policy = 'allow_all'
  peerForm.forward_allowed_cidrs = []
  peerForm.split_tunnel = false
  peerForm.use_preshared_key = true
  peerFormOpen.value = true
}

function beginAttachToConfig (config, client) {
  openAddPeer(config)
  peerForm.mode = 'existing'
  peerForm.vpn_client_id = client.id
}

function openAttachUnattached (client) {
  if (!configs.value.length) {
    $q.notify({ type: 'warning', message: t('configs.createConfigFirst') })
    return
  }
  attachClient.value = client
  if (configs.value.length === 1) {
    beginAttachToConfig(configs.value[0], client)
    return
  }
  attachConfigId.value = null
  attachPickOpen.value = true
}

function confirmAttachPick () {
  const config = configs.value.find((c) => c.id === attachConfigId.value)
  if (!config || !attachClient.value) return
  attachPickOpen.value = false
  beginAttachToConfig(config, attachClient.value)
}

function openEditPeer (config, row) {
  activeConfig.value = config
  editingPeerId.value = row.client_id
  peerForm.mode = 'existing'
  peerForm.vpn_client_id = row.client_id
  peerForm.name = row.name || ''
  peerForm.comment = allClients.value.find((c) => c.id === row.client_id)?.comment || ''
  peerForm.address = row.address
  peerForm.extra_allowed_ips = [...(row.extra_allowed_ips || [])]
  if (!peerForm.extra_allowed_ips.length) peerForm.extra_allowed_ips.push('')
  peerForm.local_subnet = (row.extra_allowed_ips || [])[0] || ''
  peerForm.excluded_client_ids = [...(row.excluded_client_ids || [])]
  peerForm.exclusions_mutual = !!row.exclusions_mutual
  peerForm.forward_policy = row.forward_policy || 'allow_all'
  peerForm.forward_allowed_cidrs = [...(row.forward_allowed_cidrs || [])]
  peerForm.split_tunnel = !!row.split_tunnel
  if (!peerForm.forward_allowed_cidrs.length) peerForm.forward_allowed_cidrs.push('')
  peerFormOpen.value = true
}

async function savePeer () {
  const config = activeConfig.value
  if (!config) return
  peerSaving.value = true
  try {
    let extraAllowedIps
    if (config.type === 'virtual_network') {
      const subnet = String(peerForm.local_subnet).trim()
      if (!subnet) {
        $q.notify({ type: 'negative', message: t('configs.specifyLocalSubnetIp') })
        return
      }
      extraAllowedIps = [subnet]
    } else {
      extraAllowedIps = peerForm.extra_allowed_ips.filter((x) => String(x).trim())
    }
    const membershipPayload = {
      extra_allowed_ips: extraAllowedIps,
      use_preshared_key: peerForm.use_preshared_key
    }
    if (config.type === 'virtual_network') {
      membershipPayload.excluded_client_ids = peerForm.excluded_client_ids || []
      membershipPayload.exclusions_mutual = peerForm.exclusions_mutual
    } else {
      membershipPayload.split_tunnel = !!peerForm.split_tunnel
      membershipPayload.forward_policy = peerForm.forward_policy || 'allow_all'
      if (peerForm.forward_policy === 'restricted') {
        const fwCidrs = peerForm.forward_allowed_cidrs.filter((x) => String(x).trim())
        if (!fwCidrs.length) {
          $q.notify({ type: 'negative', message: t('configs.peerForwardCidrsRequired') })
          return
        }
        membershipPayload.forward_allowed_cidrs = fwCidrs
      } else {
        membershipPayload.forward_allowed_cidrs = []
      }
    }

    if (editingPeerId.value) {
      await api.put(`/api/clients/${editingPeerId.value}`, {
        name: peerForm.name,
        comment: peerForm.comment || null
      })
      await api.put(`/api/configs/${config.id}/peers/${editingPeerId.value}`, membershipPayload)
      $q.notify({ type: 'positive', message: t('configs.peerUpdated') })
    } else if (peerForm.mode === 'new') {
      if (!peerForm.name.trim()) {
        $q.notify({ type: 'negative', message: t('configs.specifyPeerName') })
        return
      }
      const { data } = await api.post('/api/clients', {
        name: peerForm.name.trim(),
        comment: peerForm.comment || null
      })
      await api.post(`/api/configs/${config.id}/peers`, {
        ...membershipPayload,
        vpn_client_id: data.client.id
      })
      $q.notify({ type: 'positive', message: t('configs.peerCreatedAndAttached') })
    } else {
      if (!peerForm.vpn_client_id) {
        $q.notify({ type: 'negative', message: t('configs.selectPeer') })
        return
      }
      await api.post(`/api/configs/${config.id}/peers`, {
        ...membershipPayload,
        vpn_client_id: peerForm.vpn_client_id
      })
      $q.notify({ type: 'positive', message: t('configs.peerAttached') })
    }

    peerFormOpen.value = false
    const tasks = [loadPeers(config.id), reloadClients()]
    if (!editingPeerId.value) {
      tasks.push(reloadConfigsOnly())
    }
    await Promise.all(tasks)
  } catch (e) {
    $q.notify({ type: 'negative', message: apiErrorMessage(e, t('common.serverError')) })
  } finally {
    peerSaving.value = false
  }
}

function peerToggleKey (configId, clientId) {
  return `${configId}:${clientId}`
}

function isPeerToggling (configId, clientId) {
  return peerToggling.has(peerToggleKey(configId, clientId))
}

async function togglePeer (config, row, enabled) {
  const key = peerToggleKey(config.id, row.client_id)
  if (peerToggling.has(key)) return

  const prev = row.enabled
  row.enabled = enabled
  peerToggling.add(key)

  try {
    await api.put(`/api/configs/${config.id}/peers/${row.client_id}`, { enabled })
    loadPeers(config.id, true)
  } catch (e) {
    row.enabled = prev
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.togglePeerError') })
  } finally {
    peerToggling.delete(key)
  }
}

async function detachPeer (config, row) {
  $q.dialog({
    title: t('configs.detachPeerTitle'),
    message: t('configs.detachPeerConfirm', { name: row.name, config: config.name }),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    await api.delete(`/api/configs/${config.id}/peers/${row.client_id}`)
    await Promise.all([loadPeers(config.id), reloadConfigsOnly(), reloadClients()])
  })
}

async function deletePeer (row) {
  $q.dialog({
    title: t('configs.deletePeerTitle'),
    message: t('configs.deletePeerConfirm', { name: row.name }),
    cancel: true,
    persistent: true,
  }).onOk(async () => {
    await api.delete(`/api/clients/${row.client_id}`)
    await reloadClients()
    for (const id of [...expandedIds]) {
      await loadPeers(id, true)
    }
  })
}

async function downloadConf (config, row) {
  const { data } = await api.get(`/api/configs/${config.id}/peers/${row.client_id}/config`, { responseType: 'text' })
  const text = typeof data === 'string' ? data : String(data ?? '')
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = peerConfFilename(text, `${row.name}-${config.name}.conf`)
  a.click()
  URL.revokeObjectURL(url)
}

function openShare (config, peer) {
  shareTarget.value = {
    configId: config.id,
    clientId: peer.client_id,
    peerName: peer.name || `peer #${peer.client_id}`,
    configName: config.name || ''
  }
  shareOpen.value = true
}

async function showServerConf (row) {
  serverConfTitle.value = `${row.name} (${row.iface || 'awg'}.conf)`
  serverConfFilename.value = `${row.iface || 'awg'}.conf`
  serverConfText.value = ''
  serverConfLoading.value = true
  serverConfOpen.value = true
  try {
    const { data } = await api.get(`/api/configs/${row.id}/server-config`, { responseType: 'text' })
    serverConfText.value = typeof data === 'string' ? data : String(data ?? '')
  } catch (e) {
    serverConfOpen.value = false
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('configs.loadConfigError') })
  } finally {
    serverConfLoading.value = false
  }
}

async function copyServerConf () {
  if (!serverConfText.value) return
  try {
    await copyText(serverConfText.value)
    $q.notify({ type: 'positive', message: t('configs.configCopied') })
  } catch {
    $q.notify({ type: 'negative', message: t('common.copyFailed') })
  }
}

function downloadServerConf () {
  if (!serverConfText.value) return
  const blob = new Blob([serverConfText.value], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = serverConfFilename.value
  a.click()
  URL.revokeObjectURL(url)
}

function onServerConfHide () {
  serverConfText.value = ''
  serverConfTitle.value = ''
  serverConfFilename.value = 'awg.conf'
}

onMounted(() => {
  load()
  liveOff = onLiveStats(() => remergeAllExpandedLive())
})
onUnmounted(() => {
  if (liveOff) liveOff()
  if (subscribedLiveConfigs.size) {
    unsubscribeLiveStats([...subscribedLiveConfigs])
    subscribedLiveConfigs.clear()
  }
})

provide(CONFIG_EXPAND_KEY, {
  peersState,
  zonesState,
  peerColumns,
  formatBytes,
  formatHandshake,
  isPeerToggling,
  isolatedPeers,
  ruleMemberOptions,
  markZonesDirty,
  refreshConfigLive,
  openAddPeer,
  togglePeer,
  openHandshakeLogs,
  resetPeerTraffic,
  openEditPeer,
  openShare,
  downloadConf,
  detachPeer,
  deletePeer,
  addRule,
  saveZones,
  removeRule
})
</script>

<style scoped>
.expanded-cell {
  background: rgba(255, 255, 255, 0.03);
}

.config-path-label {
  width: 96px;
  flex-shrink: 0;
}

.config-path-value {
  font-family: var(--theme-mono);
  font-size: 12px;
  word-break: break-all;
}

.configs-page-primary-actions {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.config-type-badges :deep(.q-badge) {
  white-space: normal;
  height: auto;
  line-height: 1.25;
  padding-top: 4px;
  padding-bottom: 4px;
}

@media (max-width: 1023px) {
  .configs-page-actions {
    width: 100%;
    flex-wrap: wrap;
    row-gap: 8px;
  }

  .configs-page-primary-actions {
    display: flex;
    width: 100%;
    flex: 1 1 100%;
  }

  .configs-page-primary-actions > .q-btn {
    flex: 1 1 0;
    min-width: 0;
  }
}
</style>
