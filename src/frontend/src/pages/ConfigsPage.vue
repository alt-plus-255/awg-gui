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
                :icon="props.expand ? 'expand_less' : 'expand_more'"
                @click="toggleExpand(props)"
              />
            </q-td>
            <q-td key="name" :props="props">{{ props.row.name }}</q-td>
            <q-td key="type" :props="props">
              <q-badge :color="props.row.type === 'virtual_network' ? 'info' : 'primary'">
                {{ props.row.type === 'virtual_network' ? t('configs.typeVirtualNetwork') : t('configs.typeServer') }}
              </q-badge>
              <q-badge
                v-if="props.row.type === 'virtual_network'"
                :color="props.row.vn_policy === 'deny_all' ? 'deep-orange' : 'grey-8'"
                class="q-ml-xs"
              >
                {{ props.row.vn_policy === 'deny_all' ? t('configs.badgeIsolation') : t('configs.badgeAllowAll') }}
              </q-badge>
              <q-badge
                v-if="props.row.resolver_enabled"
                color="deep-purple"
                class="q-ml-xs"
              >
                {{ t('configs.badgeResolver') }}
              </q-badge>
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
              <q-btn flat dense icon="description" :title="t('configs.showConf')" @click="showServerConf(props.row)" />
              <q-btn flat dense icon="edit" :title="t('common.edit')" @click="openEdit(props.row)" />
              <q-btn flat dense color="negative" icon="delete" :title="t('common.delete')" @click="remove(props.row)" />
            </q-td>
          </q-tr>

          <q-tr v-show="props.expand" :props="props" :key="`e_${props.row.id}`" class="q-virtual-scroll--with-prev">
            <q-td colspan="100%" class="expanded-cell">
              <div class="q-pa-md">
                <div class="row items-center q-mb-sm">
                  <div class="text-subtitle1 col">{{ t('configs.peersOfConfig', { name: props.row.name }) }}</div>
                  <q-btn flat dense icon="refresh" :label="t('common.refresh')" class="q-mr-sm" @click="refreshConfigLive(props.row.id)" :loading="peersState[props.row.id]?.liveLoading" />
                  <q-btn color="primary" dense icon="add" :label="t('configs.addPeer')" @click="openAddPeer(props.row)" />
                </div>

                <div v-if="props.row.type === 'virtual_network'" class="text-caption text-grey-5 q-mb-sm">
                  <template v-if="props.row.vn_policy === 'deny_all'">
                    {{ t('configs.isolationModeHint') }}
                  </template>
                  <template v-else>
                    {{ t('configs.lanRouterHint') }}
                  </template>
                </div>

                <q-banner
                  v-if="peersState[props.row.id]?.statsAvailable === false"
                  dense
                  rounded
                  class="bg-warning text-dark q-mb-sm"
                >
                  {{ t('configs.statsUnavailable') }}
                </q-banner>

                <q-table
                  :rows="peersState[props.row.id]?.peers || []"
                  :columns="peerColumns"
                  row-key="membership_id"

                  flat
                  dense
                  :loading="peersState[props.row.id]?.loading"
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
                      <template v-if="formatHandshake(peerProps.row.latest_handshake_human)">
                        <div class="text-no-wrap">{{ formatHandshake(peerProps.row.latest_handshake_human).date }}</div>
                        <div class="text-no-wrap">{{ formatHandshake(peerProps.row.latest_handshake_human).time }}</div>
                      </template>
                      <template v-else>—</template>
                    </q-td>
                  </template>
                  <template #body-cell-transfer_rx="peerProps">
                    <q-td :props="peerProps">{{ peerProps.row.transfer_rx != null ? formatBytes(peerProps.row.transfer_rx) : '—' }}</q-td>
                  </template>
                  <template #body-cell-transfer_tx="peerProps">
                    <q-td :props="peerProps">{{ peerProps.row.transfer_tx != null ? formatBytes(peerProps.row.transfer_tx) : '—' }}</q-td>
                  </template>
                  <template #body-cell-enabled="peerProps">
                    <q-td :props="peerProps">
                      <div class="row items-center no-wrap q-gutter-xs">
                        <q-toggle
                          :model-value="peerProps.row.enabled"
                          dense
                          color="positive"
                          :disable="isPeerToggling(props.row.id, peerProps.row.client_id)"
                          @update:model-value="(v) => togglePeer(props.row, peerProps.row, v)"
                        />
                        <q-spinner-dots
                          v-if="isPeerToggling(props.row.id, peerProps.row.client_id)"
                          size="20px"
                          color="primary"
                        />
                      </div>
                    </q-td>
                  </template>
                  <template #body-cell-actions="peerProps">
                    <q-td :props="peerProps">
                      <q-btn flat dense icon="edit" :title="t('common.edit')" @click="openEditPeer(props.row, peerProps.row)" />
                      <q-btn flat dense icon="qr_code_2" title="QR" @click="openShare(props.row, peerProps.row)" />
                      <q-btn flat dense icon="download" :title="t('dashboard.configTooltip')" @click="downloadConf(props.row, peerProps.row)" />
                      <q-btn flat dense color="warning" icon="link_off" :title="t('configs.detachFromConfig')" @click="detachPeer(props.row, peerProps.row)" />
                      <q-btn flat dense color="negative" icon="delete" :title="t('configs.deletePeerFully')" @click="deletePeer(peerProps.row)" />
                    </q-td>
                  </template>
                </q-table>

                <!-- access rules (isolation mode) -->
                <div v-if="props.row.type === 'virtual_network' && props.row.vn_policy === 'deny_all'" class="q-mt-lg">
                  <div class="row items-center q-mb-xs">
                    <div class="text-subtitle1 col">{{ t('configs.accessRules') }}</div>
                    <q-btn flat dense icon="add" :label="t('configs.addRule')" class="q-mr-sm" @click="addRule(props.row.id)" />
                    <q-btn
                      color="primary"
                      dense
                      icon="save"
                      :label="t('configs.saveRules')"
                      :loading="zonesState[props.row.id]?.saving"
                      :disable="!zonesState[props.row.id]?.dirty"
                      @click="saveZones(props.row)"
                    />
                  </div>
                  <div class="text-caption text-grey-5 q-mb-sm">
                    {{ t('configs.rulesHintLeftToRight') }} <br>
                    {{ t('configs.rulesHintTunnelOnly') }} <br>
                    {{ t('configs.rulesHintMasquerade') }} <br>
                    {{ t('configs.peerOutsideRules') }}
                  </div>

                  <q-banner
                    v-if="isolatedPeers(props.row.id).length"
                    dense
                    rounded
                    class="bg-blue-grey-9 text-grey-4 q-mb-sm"
                  >
                    {{ t('configs.isolatedOutsideRules', { names: isolatedPeers(props.row.id).join(', ') }) }}
                  </q-banner>

                  <div v-if="!(zonesState[props.row.id]?.rules || []).length" class="text-caption text-grey-6 q-mb-sm">
                    {{ t('configs.noRulesYet') }}
                  </div>

                  <div
                    v-for="(rule, rIdx) in zonesState[props.row.id]?.rules || []"
                    :key="rIdx"
                    class="row q-col-gutter-sm items-center q-mb-sm"
                  >
                    <div class="col">
                      <q-select
                        v-model="rule.src_client_ids"
                        :options="ruleMemberOptions(props.row.id, rule.dest_client_ids)"
                        multiple
                        use-chips
                        emit-value
                        map-options
                        option-value="client_id"
                        option-label="name"
                        :label="t('configs.whoWalks')"

                        filled
                        dense
                        @update:model-value="markZonesDirty(props.row.id)"
                      />
                    </div>
                    <div class="col-auto text-h6 text-grey-5">→</div>
                    <div class="col">
                      <q-select
                        v-model="rule.dest_client_ids"
                        :options="ruleMemberOptions(props.row.id, rule.src_client_ids)"
                        multiple
                        use-chips
                        emit-value
                        map-options
                        option-value="client_id"
                        option-label="name"
                        :label="t('configs.wherePeerSubnet')"

                        filled
                        dense
                        @update:model-value="markZonesDirty(props.row.id)"
                      />
                    </div>
                    <div class="col-auto">
                      <q-btn flat dense icon="close" color="negative" :title="t('configs.deleteRule')" @click="removeRule(props.row.id, rIdx)" />
                    </div>
                  </div>
                </div>
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
          : 'width: min(480px, 95vw); max-width: 95vw; max-height: 90vh;'"
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
                @click="generateJunk"
              />
            </div>
            <div class="row q-col-gutter-sm">
              <div class="col-12 col-md-3">
                <q-input v-for="k in ['jc', 'jmin', 'jmax']" :key="k" v-model="form[k]" :label="k.toUpperCase()" filled dense class="q-mb-sm" />
              </div>
              <div class="col-12 col-md-3">
                <q-input v-for="k in ['s1', 's2', 's3', 's4']" :key="k" v-model="form[k]" :label="k.toUpperCase()" filled dense class="q-mb-sm" />
              </div>
              <div class="col-12 col-md-3">
                <q-input v-for="k in ['h1', 'h2', 'h3', 'h4']" :key="k" v-model="form[k]" :label="k.toUpperCase()" filled dense class="q-mb-sm" />
              </div>
              <div class="col-12 col-md-3">
                <q-input v-for="k in ['i1', 'i2', 'i3', 'i4', 'i5']" :key="k" v-model="form[k]" :label="k.toUpperCase()" filled dense class="q-mb-sm" />
              </div>
            </div>
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
            <div class="text-subtitle2 q-mb-sm">{{ t('configs.serverToPeerAllowedIps') }}</div>
            <div v-for="(ip, idx) in peerForm.extra_allowed_ips" :key="idx" class="row q-gutter-sm q-mb-sm items-center">
              <q-input v-model="peerForm.extra_allowed_ips[idx]" label="CIDR" filled dense class="col" />
              <q-btn flat dense icon="close" color="negative" @click="peerForm.extra_allowed_ips.splice(idx, 1)" />
            </div>
            <q-btn flat dense color="primary" icon="add" :label="t('configs.addCidr')" class="q-mb-md" @click="peerForm.extra_allowed_ips.push('')" />
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
  </q-page>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
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
import { copyText } from '@/utils/clipboard'
import PeerShareDialog from '@/components/PeerShareDialog.vue'
import DialogHeader from '@/components/DialogHeader.vue'
import { useMobileDialog } from '@/composables/useMobileDialog'
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

const form = reactive({
  name: '',
  type: 'server',
  vn_policy: 'allow_all',
  internal_subnet: '10.66.66.0/24',
  server_address: '',
  listen_port: 51820,
  peer_dns: '1.1.1.1',
  client_allowed_ips: '0.0.0.0/0, ::/0',
  persistent_keepalive: 25,
  enabled: true,
  jc: '', jmin: '', jmax: '',
  s1: '', s2: '', s3: '', s4: '',
  h1: '', h2: '', h3: '', h4: '',
  i1: '', i2: '', i3: '', i4: '', i5: ''
})

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
  { name: 'enabled', label: t('configs.colEnabled'), field: 'enabled', align: 'left' },
  { name: 'actions', label: t('configs.colActions'), field: 'actions', align: 'right' }
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
  if (!activeConfig.value || activeConfig.value.type !== 'virtual_network') return ''
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

async function load () {
  loading.value = true
  try {
    const [cfgRes, clientsRes] = await Promise.all([
      api.get('/api/configs'),
      api.get('/api/clients')
    ])
    configs.value = cfgRes.data.configs || []
    allClients.value = clientsRes.data.clients || []
    syncZonesStates()
  } finally {
    loading.value = false
  }
}

function confirmRestartAwg () {
  if (restartBusy.value) {
    $q.notify({
      type: 'warning',
      position: 'top-right',
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
      position: 'top-right',
      message: data.message || (data.ok ? t('configs.awgRestarted') : t('configs.restartError'))
    })
  } catch (e) {
    const already = e?.response?.status === 409 || e?.response?.data?.already_restarting
    $q.notify({
      type: already ? 'warning' : 'negative',
      position: 'top-right',
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

function toggleExpand (props) {
  props.expand = !props.expand
  const id = props.row.id
  if (props.expand) {
    expandedIds.add(id)
    void loadPeers(id).then(() => {
      if (!subscribedLiveConfigs.has(id)) {
        subscribedLiveConfigs.add(id)
        subscribeLiveStats([id])
      }
    })
    if (props.row.type === 'virtual_network' && !zonesState[id]) {
      initZonesState(props.row)
    }
  } else {
    expandedIds.delete(id)
    if (subscribedLiveConfigs.has(id)) {
      unsubscribeLiveStats([id])
      subscribedLiveConfigs.delete(id)
    }
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
  form.vn_policy = 'allow_all'
  form.internal_subnet = nextFreeSubnet()
  form.server_address = ''
  form.listen_port = nextFreePort()
  form.peer_dns = '1.1.1.1'
  form.client_allowed_ips = '0.0.0.0/0, ::/0'
  form.persistent_keepalive = 25
  form.enabled = true
  generateJunk()
}

function openCreate () {
  editingId.value = null
  editingRow.value = null
  resetForm()
  formOpen.value = true
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

// Правила: https://docs.amnezia.org/ru/documentation/amnezia-wg/
// i1-i5 (CPS-сигнатуры протоколов) не генерируем — случайные значения там бессмысленны
function generateJunk () {
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
  form.s3 = String(randInt(0, 64))
  form.s4 = String(randInt(0, 32))

  const hs = new Set()
  while (hs.size < 4) {
    hs.add(randInt(1, 2147483647))
  }
  ;[form.h1, form.h2, form.h3, form.h4] = [...hs].map(String)

  $q.notify({
    type: 'info',
    message: t('configs.obfuscationGenerated')
  })
}

function openEdit (row) {
  editingId.value = row.id
  editingRow.value = row
  Object.keys(form).forEach((k) => {
    if (row[k] !== undefined && row[k] !== null) form[k] = row[k]
  })
  formOpen.value = true
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
  saving.value = true
  try {
    const payload = { ...form }
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
    const fieldMsg = errors?.internal_subnet?.[0] || errors?.listen_port?.[0]
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
    $q.notify({ type: 'negative', message: e?.response?.data?.message || t('common.error') })
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
  a.download = `${row.name}-${config.name}.conf`
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
