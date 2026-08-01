<template>
  <q-page padding>
    <div class="page-wrap">
      <div class="row items-center q-mb-md">
        <div class="text-h5 col">{{ t('settings.title') }}</div>
        <q-btn
          v-if="isDirty"
          color="primary"
          :label="t('common.save')"
          :loading="saving"
          @click="save"
        />
      </div>

      <q-card class="surface-panel settings-card">
        <q-tabs
          v-model="activeTab"
          dense
          align="left"
          active-color="primary"
          indicator-color="primary"
          class="settings-tabs text-muted-theme"
          outside-arrows
          mobile-arrows
        >
          <q-tab name="general" :label="t('settings.tabGeneral')" />
          <q-tab name="panel" :label="t('settings.tabPanel')" />
          <q-tab v-if="hasDomain" name="https" label="HTTPS" />
          <q-tab name="telegram" :label="t('settings.tabTelegram')" />
          <q-tab name="update" :label="t('settings.tabUpdate')" />
          <q-tab name="twofa" label="2FA" />
        </q-tabs>

        <q-separator />

        <q-form>
          <q-tab-panels v-model="activeTab" class="settings-panels">
            <q-tab-panel name="general" class="q-pa-md">
              <div class="text-subtitle2 q-mb-sm">{{ t('settings.appearance') }}</div>

              <div class="row q-col-gutter-md general-appearance-row">
                <div class="col-12 col-md-4">
                  <q-select
                    :model-value="theme.colorMode"
                    :options="colorModeOptions"
                    :label="t('theme.theme')"
                    filled
                    emit-value
                    map-options
                    @update:model-value="theme.setColorMode"
                  />
                </div>

                <div class="col-12 col-md-4">
                  <q-select
                    :model-value="theme.styleId"
                    :options="theme.styleOptions"
                    :label="t('theme.style')"
                    filled
                    emit-value
                    map-options
                    @update:model-value="theme.setStyle"
                  />
                </div>

                <div class="col-12 col-md-4">
                  <q-select
                    :model-value="localeStore.locale"
                    :options="localeStore.localeOptions"
                    :label="t('theme.language')"
                    filled
                    emit-value
                    map-options
                    @update:model-value="localeStore.setLocale"
                  />
                </div>
              </div>

              <div v-if="theme.colorMode === 'auto'" class="row q-col-gutter-sm q-mt-sm q-mb-md">
                <div class="col-12 col-sm-6">
                  <q-input
                    :model-value="theme.autoHours.from"
                    type="time"
                    :label="t('theme.lightFrom')"
                    filled
                    @update:model-value="onAutoFrom"
                  />
                </div>
                <div class="col-12 col-sm-6">
                  <q-input
                    :model-value="theme.autoHours.to"
                    type="time"
                    :label="t('theme.lightUntil')"
                    filled
                    @update:model-value="onAutoTo"
                  />
                </div>
              </div>

              <q-separator class="q-my-md" />

              <div class="text-subtitle2 q-mb-sm">{{ t('settings.sounds') }}</div>
              <q-toggle
                :model-value="sounds.enabled"
                :label="t('settings.uiSounds')"
                color="primary"

                @update:model-value="sounds.setEnabled"
              />

              <q-separator class="q-my-md" />

              <div class="text-subtitle2 q-mb-sm">{{ t('settings.failureAlert') }}</div>
              <q-input
                v-model="form.failure_webhook_url"
                :label="t('settings.webhookUrl')"
                :hint="t('settings.webhookUrlHint')"

                filled
                class="q-mb-md"
              />
              <div class="row q-gutter-sm">
                <q-btn outline color="accent" :label="t('settings.jsonSchema')" @click="schemaOpen = true" />
                <q-btn outline color="primary" :label="t('settings.sendTest')" :loading="testing" @click="testWebhook" />
              </div>
            </q-tab-panel>

            <q-tab-panel name="panel" class="q-pa-md">
              <div class="row q-col-gutter-md">
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.server_endpoint"
                    :label="t('settings.publicIp')"
                    :hint="t('settings.publicIpHint')"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_domain"
                    :label="t('settings.panelDomain')"
                    :hint="domainHint"

                    filled
                    clearable
                    :error="!!domainError"
                    :error-message="domainError"
                    @update:model-value="domainError = ''"
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_port"
                    :label="t('settings.httpPort')"
                    :hint="t('settings.httpPortHint')"

                    filled
                  />
                </div>
                <div v-if="showHttpsPort" class="col-12 col-md-6">
                  <q-input
                    v-model="form.panel_https_port"
                    :label="t('settings.httpsPort')"
                    :hint="t('settings.httpsPortHint')"

                    filled
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-select
                    v-model="form.timezone"
                    :options="timezoneOptions"
                    :label="t('settings.timezone')"
                    :hint="t('settings.timezoneHint')"

                    filled
                    use-input
                    fill-input
                    hide-selected
                    input-debounce="0"
                    emit-value
                    map-options
                    @filter="filterTimezones"
                  />
                </div>
                <div class="col-12 col-md-6">
                  <q-select
                    v-model="form.singbox_egress_interface"
                    :options="egressInterfaceOptions"
                    :label="t('settings.egressInterface')"
                    :hint="egressInterfaceHint"
                    filled
                    emit-value
                    map-options
                  />
                </div>
                <div v-if="hasDomain" class="col-12">
                  <q-toggle
                    v-model="form.endpoint_use_domain"
                    :label="t('settings.useDomainInEndpoint')"
                    color="primary"

                  />
                  <div class="text-caption text-grey-5 q-mt-xs">
                    {{ t('settings.inClientConfigs') }}
                    <span class="mono">Endpoint = {{ endpointHostPreview }}:&lt;listen_port&gt;</span>
                  </div>
                </div>
              </div>
              <div class="text-caption text-grey-5 q-mt-sm">
                {{ t('settings.panelUrl') }} <span class="mono">{{ panelUrl || '—' }}</span>
              </div>
              <div class="text-caption text-grey-5 q-mt-xs">
                {{ t('settings.awgConfigsHint') }}
              </div>

              <q-separator class="q-my-lg" />
              <div class="text-subtitle2 q-mb-sm">{{ t('settings.awgKernelTitle') }}</div>
              <div class="text-caption text-grey-5 q-mb-md">{{ t('settings.awgKernelHint') }}</div>
              <div v-if="!awgKernel.script_present" class="text-warning text-caption q-mb-md">
                {{ t('settings.awgKernelMissingScript') }}
              </div>
              <div class="row q-col-gutter-md q-mb-md">
                <div class="col-12 col-sm-6 col-md-3">
                  <div class="text-caption text-grey-5">{{ t('settings.awgKernelModule') }}</div>
                  <div class="text-body2">{{ awgKernel.module_loaded ? 'yes' : 'no' }}</div>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                  <div class="text-caption text-grey-5">{{ t('settings.awgKernelPackage') }}</div>
                  <div class="text-body2">{{ awgKernel.package_installed ? 'yes' : 'no' }}</div>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                  <div class="text-caption text-grey-5">{{ t('settings.awgKernelDatapath') }}</div>
                  <div class="text-body2 mono">{{ awgKernel.awg_datapath }}</div>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                  <div class="text-caption text-grey-5">{{ t('settings.awgKernelOs') }}</div>
                  <div class="text-body2 mono">{{ awgKernel.os_family }}</div>
                </div>
              </div>
              <div class="text-caption text-grey-5 q-mb-xs">{{ t('settings.awgKernelStatus') }}</div>
              <div class="q-mb-md" :class="awgKernelStatusClass">
                <q-spinner v-if="awgKernel.running" size="16px" color="primary" class="q-mr-sm" />
                {{ awgKernelStatusLabel }}
                <span v-if="awgKernel.op_message" class="text-grey-5"> — {{ awgKernel.op_message }}</span>
              </div>
              <div class="row q-gutter-sm">
                <q-btn
                  color="primary"
                  :label="t('settings.awgKernelInstall')"
                  :loading="awgKernel.starting || awgKernel.running"
                  :disable="!awgKernel.script_present || awgKernel.running"
                  @click="onAwgKernelInstall"
                />
                <q-btn
                  outline
                  color="negative"
                  :label="t('settings.awgKernelUninstall')"
                  :loading="awgKernel.starting || awgKernel.running"
                  :disable="!awgKernel.script_present || awgKernel.running"
                  @click="onAwgKernelUninstall"
                />
                <q-btn
                  flat
                  color="grey-5"
                  :label="t('common.refresh')"
                  :loading="awgKernel.loading"
                  @click="() => awgKernel.fetchStatus()"
                />
              </div>
            </q-tab-panel>

            <q-tab-panel v-if="hasDomain" name="https" class="q-pa-md">
              <div class="text-caption text-grey-5 q-mb-md">
                {{ sslHint }}
              </div>

              <div class="row q-col-gutter-md q-mb-md">
                <div class="col-12 col-md-6">
                  <q-input
                    v-model="sslEmail"
                    :label="t('settings.letsEncryptEmail')"
                    :hint="t('settings.letsEncryptEmailHint')"

                    filled
                    type="email"
                    :disable="sslBusy"
                  />
                </div>
                <div class="col-12 col-md-6">
                  <div class="text-caption text-grey-5">{{ t('settings.certStatus') }}</div>
                  <div class="text-body2">{{ sslStatusLabel }}</div>
                  <div v-if="ssl.expires_at" class="text-caption text-grey-5 q-mt-xs">
                    {{ t('settings.validUntil') }} <span class="mono">{{ ssl.expires_at }}</span>
                  </div>
                  <div v-if="ssl.error" class="text-negative text-caption q-mt-xs">
                    {{ ssl.error }}
                  </div>
                </div>
              </div>

              <div v-if="activeChallenge" class="challenge-box q-mb-md">
                <div class="text-subtitle2 q-mb-sm">{{ t('settings.addTxtRecord') }}</div>
                <div class="text-caption text-grey-5 q-mb-xs">{{ t('settings.txtName') }}</div>
                <div class="mono q-mb-sm">{{ activeChallenge.txt_name }}</div>
                <div class="text-caption text-grey-5 q-mb-xs">{{ t('settings.txtValue') }}</div>
                <div class="mono q-mb-md" style="word-break: break-all;">{{ activeChallenge.txt_value }}</div>
                <div class="row q-gutter-sm">
                  <q-btn
                    color="primary"
                    :label="t('settings.continueAfterTxt')"
                    :loading="sslCompleting"
                    :disable="sslBusy && !sslCompleting"
                    @click="completeSsl"
                  />
                  <q-btn
                    flat
                    color="grey-5"
                    :label="t('settings.abort')"
                    :disable="sslBusy && !sslIssuing"
                    @click="abortSsl"
                  />
                </div>
              </div>

              <div class="row q-gutter-sm">
                <q-btn
                  v-if="!ssl.enabled"
                  color="primary"
                  :label="t('settings.issueCert')"
                  :loading="sslIssuing"
                  :disable="sslBusy || !!activeChallenge"
                  @click="issueSsl(false)"
                />
                <q-btn
                  v-if="ssl.enabled"
                  outline
                  color="primary"
                  :label="t('settings.renewCert')"
                  :loading="sslIssuing"
                  :disable="sslBusy || !!activeChallenge"
                  @click="issueSsl(true)"
                />
                <q-btn
                  v-if="ssl.enabled"
                  outline
                  color="negative"
                  :label="t('settings.disableHttps')"
                  :loading="sslDisabling"
                  :disable="sslBusy"
                  @click="disableSsl"
                />
              </div>
            </q-tab-panel>

            <q-tab-panel name="telegram" class="q-pa-md">
              <div class="text-body2 q-mb-md">{{ t('settings.telegramHint') }}</div>

              <q-input
                v-model="form.telegram_bot_token"
                :label="t('settings.telegramToken')"
                :hint="form.telegram_bot_token_set ? t('settings.telegramTokenHintSet') : t('settings.telegramTokenHint')"
                filled
                class="q-mb-md"
                autocomplete="off"
              />

              <q-input
                v-model="form.telegram_admin_id"
                :label="t('settings.telegramAdminId')"
                :hint="t('settings.telegramAdminIdHint')"
                filled
                class="q-mb-md"
              />

              <div class="text-caption q-mb-xs">{{ t('settings.telegramLanguage') }}</div>
              <q-btn-toggle
                v-model="form.telegram_language"
                toggle-color="primary"
                :options="telegramLanguageOptions"
                class="q-mb-md"
                unelevated
                spread
              />

              <div class="text-caption q-mb-xs">{{ t('settings.telegramMode') }}</div>
              <q-btn-toggle
                v-model="form.telegram_mode"
                toggle-color="primary"
                :options="telegramModeOptions"
                class="q-mb-md"
                unelevated
                spread
              />

              <q-toggle
                v-model="form.telegram_notifications_enabled"
                :label="t('settings.telegramNotifications')"
                color="primary"
                class="q-mb-md"
              />

              <div v-if="form.telegram_mode === 'polling'">
                <q-separator class="q-mb-md" />
                <div class="text-subtitle2 q-mb-sm">{{ t('settings.telegramProxies') }}</div>
                <div class="text-caption text-grey-5 q-mb-md">{{ t('settings.telegramProxiesHint') }}</div>

                <div class="text-caption q-mb-xs">{{ t('settings.telegramProxyStrategy') }}</div>
                <q-btn-toggle
                  v-model="form.telegram_proxy_strategy"
                  toggle-color="primary"
                  :options="telegramStrategyOptions"
                  class="q-mb-md"
                  unelevated
                  spread
                />

                <div
                  v-for="proxy in telegramUrlProxies"
                  :key="proxy.id"
                  class="row q-col-gutter-sm items-center q-mb-sm"
                >
                  <div class="col">
                    <q-input
                      v-model="proxy.url"
                      :label="t('settings.telegramProxyUrl')"
                      filled
                      dense
                    />
                  </div>
                  <div class="col-auto">
                    <q-toggle
                      :model-value="proxy.enabled"
                      color="primary"
                      dense
                      @update:model-value="(v) => onTelegramProxyEnabledChange(proxy, v)"
                    />
                  </div>
                  <div class="col-auto">
                    <q-btn
                      flat
                      dense
                      color="negative"
                      icon="delete"
                      @click="confirmRemoveTelegramProxy(proxy)"
                    />
                  </div>
                </div>

                <div class="row q-gutter-sm q-mb-md">
                  <q-btn outline color="primary" :label="t('settings.telegramAddProxy')" @click="openTelegramProxyDialog" />
                </div>

                <q-select
                  v-model="telegramConnectionIds"
                  :options="telegramConnectionOptions"
                  filled
                  multiple
                  use-chips
                  emit-value
                  map-options
                  :label="t('settings.telegramResolverConnections')"
                  class="q-mb-md"
                />
              </div>

              <div class="row q-gutter-sm">
                <q-btn
                  outline
                  color="primary"
                  :label="t('settings.telegramTestBot')"
                  :loading="telegramTesting"
                  @click="testTelegram(false)"
                />
                <q-btn
                  v-if="form.telegram_mode === 'polling'"
                  outline
                  color="accent"
                  :label="t('settings.telegramProbeProxies')"
                  :loading="telegramTesting"
                  @click="testTelegram(true)"
                />
              </div>
              <div v-if="telegramTestResult" class="text-caption q-mt-sm" :class="telegramTestResult.ok ? 'text-positive' : 'text-negative'">
                {{ telegramTestResult.text }}
              </div>
              <div v-if="telegramProxyProbe.length" class="q-mt-sm">
                <div
                  v-for="row in telegramProxyProbe"
                  :key="row.id"
                  class="text-caption mono"
                >
                  {{ row.source }} · {{ row.url }} · {{ row.ok ? (row.latency_ms + ' ms') : t('settings.telegramProxyProbeFail') }}
                </div>
              </div>
            </q-tab-panel>

            <q-tab-panel name="update" class="q-pa-md">
              <div class="text-subtitle2 q-mb-sm">{{ t('settings.updateTitle') }}</div>
              <div class="text-caption text-grey-5 q-mb-md">
                {{ t('settings.updateHint') }}
              </div>

              <div class="row q-col-gutter-md q-mb-md">
                <div class="col-12 col-md-4">
                  <div class="text-caption text-grey-5">{{ t('settings.currentVersion') }}</div>
                  <div class="text-body1 mono">{{ projectUpdate.current_version || '—' }}</div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="text-caption text-grey-5">{{ t('settings.latestVersion') }}</div>
                  <div class="text-body1 mono">
                    {{ projectUpdate.latest_version || '—' }}
                    <q-badge
                      v-if="projectUpdate.release_checked_at && projectUpdate.update_available"
                      color="warning"
                      class="q-ml-sm"
                    >
                      {{ t('settings.updateAvailableBadge') }}
                    </q-badge>
                  </div>
                </div>
                <div class="col-12 col-md-4">
                  <div class="text-caption text-grey-5">{{ t('settings.updateStatus') }}</div>
                  <div class="text-body1">
                    <q-spinner v-if="updateStatusBusy" size="18px" color="primary" class="q-mr-sm" />
                    <span :class="updateStatusClass">{{ updateStatusLabel }}</span>
                  </div>
                </div>
              </div>

              <div
                v-if="projectUpdate.release_checked_at && !projectUpdate.release_check_error"
                class="text-caption q-mb-md"
                :class="projectUpdate.update_available ? 'text-warning' : 'text-positive'"
              >
                {{
                  projectUpdate.update_available
                    ? t('settings.updateAvailableHint', { version: projectUpdate.latest_version || '—' })
                    : t('settings.upToDate')
                }}
              </div>
              <div v-if="projectUpdate.release_check_error" class="text-negative text-caption q-mb-md">
                {{ projectUpdate.release_check_error }}
              </div>

              <div v-if="projectUpdate.installed_at" class="text-caption text-grey-5 q-mb-xs">
                {{ t('settings.installedAt', { ts: formatUpdateTs(projectUpdate.installed_at) }) }}
              </div>
              <div v-if="projectUpdate.started_at" class="text-caption text-grey-5 q-mb-xs">
                {{ t('settings.updateStartedAt', { ts: formatUpdateTs(projectUpdate.started_at) }) }}
              </div>
              <div v-if="projectUpdate.finished_at" class="text-caption text-grey-5 q-mb-md">
                {{ t('settings.updateFinishedAt', { ts: formatUpdateTs(projectUpdate.finished_at) }) }}
              </div>

              <div class="text-body2 q-mb-md">
                {{ projectUpdate.message || t('settings.updateIdle') }}
              </div>

              <div class="row q-gutter-sm q-mb-md">
                <q-btn
                  color="primary"
                  :label="t('settings.updateNow')"
                  :loading="projectUpdate.starting"
                  :disable="!projectUpdate.can_update || !projectUpdate.update_available || projectUpdate.running"
                  @click="confirmProjectUpdate"
                />
                <q-btn
                  outline
                  color="primary"
                  :label="t('settings.checkForUpdates')"
                  :loading="projectUpdate.checking"
                  @click="checkForUpdatesFromSettings"
                />
              </div>

              <div v-if="!projectUpdate.can_update" class="text-warning text-caption q-mb-md">
                {{ t('settings.updateUnavailable') }}
              </div>

              <div class="text-subtitle2 q-mb-sm">{{ t('settings.updateLogTitle') }}</div>
              <pre class="mono update-log-pre">{{ projectUpdate.log_tail || t('settings.updateLogEmpty') }}</pre>
            </q-tab-panel>

            <q-tab-panel name="twofa" class="q-pa-md">
              <div class="text-caption text-grey-5 q-mb-md">
                {{ t('settings.twoFactorHint') }}
                 <span class="mono">awg-gui 2fa disable</span>
              </div>

              <div class="row items-center q-gutter-sm q-mb-md">
                <q-badge :color="twoFactorEnabled ? 'positive' : 'grey-7'">
                  {{ twoFactorEnabled ? t('settings.twoFactorOn') : t('settings.twoFactorOff') }}
                </q-badge>
                <q-spinner v-if="twoFactorLoading" size="20px" color="primary" />
              </div>

              <div v-if="!twoFactorEnabled && !twoFactorSetup" class="row q-gutter-sm">
                <q-btn
                  color="primary"
                  :label="t('settings.enable2fa')"
                  :loading="twoFactorBusy"
                  @click="startTwoFactorSetup"
                />
              </div>

              <div v-if="twoFactorSetup" class="q-mb-md">
                <div class="text-caption text-grey-5 q-mb-sm">
                  {{ t('settings.scanQrHint') }}
                </div>
                <div class="row q-gutter-md items-start q-mb-md">
                  <img v-if="twoFactorQr" :src="twoFactorQr" alt="QR 2FA" class="twofa-qr" />
                  <div class="col">
                    <div class="text-caption text-grey-5">{{ t('settings.secret') }}</div>
                    <div class="mono q-mb-sm" style="word-break: break-all;">{{ twoFactorSecret }}</div>
                  </div>
                </div>
                <q-input
                  v-model="twoFactorConfirmCode"
                  :label="t('settings.confirmCode')"

                  filled
                  maxlength="6"
                  inputmode="numeric"
                  class="q-mb-md"
                  style="max-width: 240px;"
                />
                <div class="row q-gutter-sm">
                  <q-btn
                    color="primary"
                    :label="t('settings.confirm')"
                    :loading="twoFactorBusy"
                    @click="confirmTwoFactor"
                  />
                  <q-btn flat color="grey-5" :label="t('common.cancel')" :disable="twoFactorBusy" @click="cancelTwoFactorSetup" />
                </div>
              </div>

              <div v-if="twoFactorEnabled">
                <q-btn
                  outline
                  color="negative"
                  :label="t('settings.disable2fa')"
                  :disable="twoFactorBusy"
                  @click="openDisableTwoFactor"
                />
              </div>
            </q-tab-panel>

          </q-tab-panels>
        </q-form>
      </q-card>
    </div>

    <q-dialog v-model="telegramProxyDialogOpen" v-bind="mobileDialog" persistent>
      <q-card style="width: min(480px, 95vw);" class="surface-panel dialog-card column no-wrap">
        <DialogHeader :title="t('settings.telegramAddProxyTitle')" />
        <q-card-section class="col dialog-scroll-body">
          <div class="text-caption text-grey-5 q-mb-md">
            {{ t('settings.telegramAddProxyHint') }}
          </div>
          <q-input
            v-model="telegramProxyDraftUrl"
            :label="t('settings.telegramProxyUrl')"
            filled
            class="q-mb-sm"
            :disable="telegramProxyChecking"
            :error="!!telegramProxyDialogError"
            :error-message="telegramProxyDialogError"
            @update:model-value="telegramProxyDialogError = ''"
            @keyup.enter="submitTelegramProxyDialog"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn
            flat
            :label="t('common.cancel')"
            :disable="telegramProxyChecking"
            v-close-popup
          />
          <q-btn
            color="primary"
            :label="t('settings.telegramAddProxyConfirm')"
            :loading="telegramProxyChecking"
            @click="submitTelegramProxyDialog"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="twoFactorDisableOpen" v-bind="mobileDialog" persistent>
      <q-card style="width: min(420px, 95vw);" class="surface-panel dialog-card column no-wrap">
        <DialogHeader :title="t('settings.disable2fa')" />
        <q-card-section class="col dialog-scroll-body">
          <div class="text-caption text-grey-5 q-mb-md">
            {{ t('settings.disable2faHint') }}
          </div>
          <q-input
            v-model="twoFactorDisablePassword"
            :label="t('settings.password')"
            type="password"

            filled
            class="q-mb-md"
            autocomplete="current-password"
            :disable="twoFactorBusy"
          />
          <q-input
            v-model="twoFactorDisableCode"
            :label="t('settings.twoFactorCode')"

            filled
            maxlength="6"
            inputmode="numeric"
            autocomplete="one-time-code"
            :disable="twoFactorBusy"
            @keyup.enter="disableTwoFactor"
          />
        </q-card-section>
        <q-card-actions align="right">
          <q-btn flat :label="t('common.cancel')" color="grey-5" :disable="twoFactorBusy" v-close-popup />
          <q-btn
            color="negative"
            :label="t('settings.disable')"
            :loading="twoFactorBusy"
            @click="disableTwoFactor"
          />
        </q-card-actions>
      </q-card>
    </q-dialog>

    <q-dialog v-model="schemaOpen" v-bind="mobileDialog">
      <q-card style="width: min(720px, 95vw); max-width: 95vw;" class="surface-panel dialog-card column no-wrap">
        <DialogHeader :title="t('settings.webhookSchemaTitle')" />
        <q-card-section class="col dialog-scroll-body">
          <q-markup-table flat dense class="q-mb-md">
            <thead>
              <tr>
                <th class="text-left">{{ t('settings.field') }}</th>
                <th class="text-left">{{ t('settings.description') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(desc, key) in schema.fields || {}" :key="key">
                <td class="mono">{{ key }}</td>
                <td>{{ desc }}</td>
              </tr>
            </tbody>
          </q-markup-table>
          <div class="text-caption q-mb-sm">{{ t('settings.payloadExample') }}</div>
          <pre class="mono schema-pre">{{ JSON.stringify(schema.example || {}, null, 2) }}</pre>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn v-if="$q.screen.gt.sm" flat :label="t('common.close')" v-close-popup />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import api from '@/boot/axios'
import { useThemeStore } from '@/stores/theme'
import { useLocaleStore } from '@/stores/locale'
import { useSettingsStore } from '@/stores/settings'
import { useProjectUpdateStore } from '@/stores/projectUpdate'
import { useAwgKernelStore } from '@/stores/awgKernel'
import { useSoundStore } from '@/sounds/store'
import { useMobileDialog } from '@/composables/useMobileDialog'
import DialogHeader from '@/components/DialogHeader.vue'
import { COLOR_MODES } from '@/themes/themes'
import { apiErrorMessage } from '@/utils/apiError'

const $q = useQuasar()
const route = useRoute()
const { t } = useI18n()
const mobileDialog = useMobileDialog()
const theme = useThemeStore()
const localeStore = useLocaleStore()
const sounds = useSoundStore()

const colorModeOptions = computed(() =>
  COLOR_MODES.map((value) => ({ value, label: t(`theme.${value}`) }))
)

function onAutoFrom (value) {
  theme.setAutoHours({ ...theme.autoHours, from: value })
}

function onAutoTo (value) {
  theme.setAutoHours({ ...theme.autoHours, to: value })
}
const settingsStore = useSettingsStore()
const projectUpdate = useProjectUpdateStore()
const awgKernel = useAwgKernelStore()
const activeTab = ref('general')
const saving = ref(false)
const testing = ref(false)
const schemaOpen = ref(false)
const domainError = ref('')
const baseline = ref(null)
const timezoneOptions = ref([{ label: 'UTC', value: 'UTC' }])
const sslEmail = ref('')
const sslIssuing = ref(false)
const sslCompleting = ref(false)
const sslDisabling = ref(false)

const twoFactorLoading = ref(false)
const twoFactorBusy = ref(false)
const twoFactorEnabled = ref(false)
const twoFactorSetup = ref(false)
const twoFactorQr = ref('')
const twoFactorSecret = ref('')
const twoFactorConfirmCode = ref('')
const twoFactorDisableOpen = ref(false)
const twoFactorDisablePassword = ref('')
const twoFactorDisableCode = ref('')

const form = reactive({
  server_endpoint: '',
  panel_domain: '',
  endpoint_use_domain: false,
  panel_port: '',
  panel_https_port: '7443',
  failure_webhook_url: '',
  timezone: 'UTC',
  telegram_bot_token: '',
  telegram_bot_token_set: false,
  telegram_admin_id: '',
  telegram_mode: 'polling',
  telegram_language: 'en',
  telegram_proxies: [],
  telegram_proxy_strategy: 'fastest',
  telegram_notifications_enabled: true,
  singbox_egress_interface: 'auto'
})

const telegramTesting = ref(false)
const telegramTestResult = ref(null)
const telegramProxyProbe = ref([])
const telegramConnectionOptions = ref([])
const telegramProxyDialogOpen = ref(false)
const telegramProxyDraftUrl = ref('')
const telegramProxyDialogError = ref('')
const telegramProxyChecking = ref(false)

watch(
  () => route.query.tab,
  (tab) => {
    if (tab === 'update') activeTab.value = 'update'
  },
  { immediate: true }
)

const telegramModeOptions = computed(() => [
  { label: t('settings.telegramModePollingShort'), value: 'polling' },
  { label: t('settings.telegramModeWebhookShort'), value: 'webhook' }
])

const telegramLanguageOptions = computed(() => [
  { label: t('settings.telegramLanguageEn'), value: 'en' },
  { label: t('settings.telegramLanguageRu'), value: 'ru' }
])

const telegramStrategyOptions = computed(() => [
  { label: t('settings.telegramStrategyFastestShort'), value: 'fastest' },
  { label: t('settings.telegramStrategyFirstOkShort'), value: 'first_ok' }
])

function normalizeTelegramProxies (raw) {
  if (Array.isArray(raw)) return raw
  if (typeof raw === 'string' && raw.trim() !== '') {
    try {
      const parsed = JSON.parse(raw)
      return Array.isArray(parsed) ? parsed : []
    } catch {
      return []
    }
  }
  return []
}

const telegramUrlProxies = computed(() =>
  normalizeTelegramProxies(form.telegram_proxies).filter((p) => p.type === 'url')
)

const telegramConnectionIds = computed({
  get () {
    return normalizeTelegramProxies(form.telegram_proxies)
      .filter((p) => p.type === 'connection' && p.enabled)
      .map((p) => p.connection_id)
  },
  set (ids) {
    const selected = new Set((ids || []).map((id) => Number(id)))
    const keptUrls = normalizeTelegramProxies(form.telegram_proxies).filter((p) => p.type === 'url')
    const connections = [...selected].map((connectionId) => {
      const existing = normalizeTelegramProxies(form.telegram_proxies).find(
        (p) => p.type === 'connection' && Number(p.connection_id) === connectionId
      )
      return existing || {
        id: `c${connectionId}`,
        type: 'connection',
        connection_id: connectionId,
        enabled: true
      }
    })
    form.telegram_proxies = [...keptUrls, ...connections]
  }
})

const panelUrl = computed(() => settingsStore.panelUrl)
const schema = computed(() => settingsStore.webhookSchema)
const ssl = computed(() => settingsStore.ssl)
const hasDomain = computed(() => String(form.panel_domain || '').trim() !== '')
const showHttpsPort = computed(() => {
  const domain = String(form.panel_domain || '').trim().toLowerCase()
  const sslDomain = String(ssl.value.domain || '').trim().toLowerCase()

  return hasDomain.value
    && ssl.value.enabled
    && ssl.value.status === 'active'
    && sslDomain !== ''
    && sslDomain === domain
})
const activeChallenge = computed(() => ssl.value.challenge || null)
const sslBusy = computed(() => sslIssuing.value || sslCompleting.value || sslDisabling.value)

const domainHint = computed(() => {
  if (!hasDomain.value) {
    return t('settings.panelDomainHint')
  }
  return t('settings.panelDomainDnsHint')
})

const sslHint = computed(() => {
  const port = String(form.panel_https_port || ssl.value.https_port || '7443').trim() || '7443'
  const base = ssl.value.hint || t('settings.acmeTxtHint')
  return `${base} ${t('settings.httpsAccessHint', { port })}`
})

const sslStatusLabel = computed(() => {
  const map = {
    disabled: t('settings.httpsDisabled'),
    pending: t('settings.waitingTxt'),
    active: t('settings.httpsActive'),
    error: t('settings.httpsError')
  }
  return map[ssl.value.status] || ssl.value.status || '—'
})

const endpointHostPreview = computed(() => {
  const domain = String(form.panel_domain || '').trim()
  if (form.endpoint_use_domain && domain) return domain
  return String(form.server_endpoint || '').trim() || '—'
})

const updateStatusBusy = computed(() => projectUpdate.busy)

const updateStatusLabel = computed(() => {
  if (projectUpdate.running) return t('settings.updateRunning')
  const map = {
    idle: t('settings.updateIdle'),
    success: t('settings.updateSuccess'),
    failed: t('settings.updateFailed')
  }
  return map[projectUpdate.status] || t('settings.updateIdle')
})

const updateStatusClass = computed(() => {
  if (projectUpdate.running) return 'text-primary'
  if (projectUpdate.status === 'success') return 'text-positive'
  if (projectUpdate.status === 'failed') return 'text-negative'
  return ''
})

const isDirty = computed(() => {
  if (!baseline.value) return false
  return snapshotForm() !== baseline.value
})

function asBool (value) {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function snapshotForm () {
  return JSON.stringify({
    server_endpoint: String(form.server_endpoint || '').trim(),
    panel_domain: String(form.panel_domain || '').trim(),
    endpoint_use_domain: hasDomain.value ? !!form.endpoint_use_domain : false,
    panel_port: String(form.panel_port || '').trim(),
    panel_https_port: String(form.panel_https_port || '').trim(),
    failure_webhook_url: String(form.failure_webhook_url || '').trim(),
    timezone: String(form.timezone || 'UTC').trim() || 'UTC',
    telegram_bot_token: String(form.telegram_bot_token || '').trim(),
    telegram_admin_id: String(form.telegram_admin_id || '').trim(),
    telegram_mode: form.telegram_mode || 'polling',
    telegram_language: form.telegram_language || 'en',
    telegram_proxies: form.telegram_proxies,
    telegram_proxy_strategy: form.telegram_proxy_strategy || 'fastest',
    telegram_notifications_enabled: !!form.telegram_notifications_enabled,
    singbox_egress_interface: String(form.singbox_egress_interface || 'auto').trim() || 'auto'
  })
}

function markBaseline () {
  baseline.value = snapshotForm()
}

function buildTimezoneOptions (list) {
  const values = (Array.isArray(list) && list.length) ? list : ['UTC']
  return values.map((tz) => ({ label: tz, value: tz }))
}

const egressInterfaceOptions = computed(() => {
  const eg = settingsStore.egress || {}
  const detected = eg.detected || eg.resolved || 'eth0'
  const opts = [{ label: t('settings.egressInterfaceAuto', { iface: detected }), value: 'auto' }]
  const seen = new Set(['auto'])
  for (const iface of (eg.options || [])) {
    if (!iface || seen.has(iface)) continue
    seen.add(iface)
    opts.push({ label: iface, value: iface })
  }
  const current = String(form.singbox_egress_interface || 'auto')
  if (current !== 'auto' && !seen.has(current)) {
    opts.push({ label: current, value: current })
  }
  return opts
})

const egressInterfaceHint = computed(() => {
  const eg = settingsStore.egress || {}
  const resolved = eg.resolved || eg.detected || '—'
  return t('settings.egressInterfaceHint', { iface: resolved })
})

function filterTimezones (val, update) {
  const all = buildTimezoneOptions(settingsStore.timezones)
  if (!val) {
    update(() => { timezoneOptions.value = all })
    return
  }
  const needle = String(val).toLowerCase()
  update(() => {
    timezoneOptions.value = all.filter((o) => o.label.toLowerCase().includes(needle))
  })
}

function applySettings (s) {
  if (s.server_endpoint !== undefined) form.server_endpoint = String(s.server_endpoint)
  if (s.panel_domain !== undefined) form.panel_domain = String(s.panel_domain ?? '')
  if (s.endpoint_use_domain !== undefined) form.endpoint_use_domain = asBool(s.endpoint_use_domain)
  if (s.panel_port !== undefined) form.panel_port = String(s.panel_port)
  if (s.panel_https_port !== undefined) form.panel_https_port = String(s.panel_https_port || '7443')
  if (s.failure_webhook_url !== undefined) form.failure_webhook_url = String(s.failure_webhook_url ?? '')
  if (s.timezone !== undefined) form.timezone = String(s.timezone || 'UTC')
  if (s.telegram_bot_token !== undefined) form.telegram_bot_token = String(s.telegram_bot_token ?? '')
  form.telegram_bot_token_set = asBool(s.telegram_bot_token_set) || String(s.telegram_bot_token || '').includes('*')
  if (s.telegram_admin_id !== undefined) form.telegram_admin_id = String(s.telegram_admin_id ?? '')
  if (s.telegram_mode !== undefined) form.telegram_mode = String(s.telegram_mode || 'polling')
  if (s.telegram_language !== undefined) form.telegram_language = String(s.telegram_language || 'en')
  if (s.telegram_proxy_strategy !== undefined) form.telegram_proxy_strategy = String(s.telegram_proxy_strategy || 'fastest')
  if (s.telegram_notifications_enabled !== undefined) {
    form.telegram_notifications_enabled = asBool(s.telegram_notifications_enabled)
  }
  if (s.singbox_egress_interface !== undefined) {
    form.singbox_egress_interface = String(s.singbox_egress_interface || 'auto').trim() || 'auto'
  }
  if (s.telegram_proxies !== undefined) {
    const rows = normalizeTelegramProxies(s.telegram_proxies)
    form.telegram_proxies = rows.map((row) => {
      if (row.type === 'url') {
        return {
          id: row.id || `p${Math.random().toString(36).slice(2, 8)}`,
          type: 'url',
          url: row.url_masked || row.url || '',
          enabled: row.enabled !== false
        }
      }
      return {
        id: row.id || `c${row.connection_id}`,
        type: 'connection',
        connection_id: Number(row.connection_id),
        enabled: row.enabled !== false
      }
    })
  } else if (!Array.isArray(form.telegram_proxies)) {
    form.telegram_proxies = []
  }
  if (!String(form.panel_domain || '').trim()) form.endpoint_use_domain = false
  if (!form.panel_https_port) form.panel_https_port = '7443'
  timezoneOptions.value = buildTimezoneOptions(settingsStore.timezones)
  markBaseline()
}

watch(
  () => settingsStore.settings,
  (s) => {
    if (s && Object.keys(s).length) applySettings(s)
  },
  { immediate: true, deep: true }
)

watch(
  () => form.panel_domain,
  (value) => {
    if (!String(value || '').trim()) form.endpoint_use_domain = false
  }
)

watch(hasDomain, (ok) => {
  if (!ok && activeTab.value === 'https') activeTab.value = 'panel'
})

watch(
  () => ssl.value.email,
  (value) => {
    if (value && !sslEmail.value) sslEmail.value = value
  },
  { immediate: true }
)

async function loadTwoFactor () {
  twoFactorLoading.value = true
  try {
    const { data } = await api.get('/api/2fa/status')
    twoFactorEnabled.value = !!data.enabled
    if (!data.enabled && !data.pending) {
      twoFactorSetup.value = false
      twoFactorQr.value = ''
      twoFactorSecret.value = ''
    }
  } catch {
    // ignore
  } finally {
    twoFactorLoading.value = false
  }
}

async function startTwoFactorSetup () {
  twoFactorBusy.value = true
  try {
    const { data } = await api.post('/api/2fa/setup')
    twoFactorSetup.value = true
    twoFactorQr.value = data.qr || ''
    twoFactorSecret.value = data.secret || ''
    twoFactorConfirmCode.value = ''
    twoFactorEnabled.value = false
  } catch (e) {
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('settings.twoFactorSetupError')
    })
  } finally {
    twoFactorBusy.value = false
  }
}

async function confirmTwoFactor () {
  twoFactorBusy.value = true
  try {
    await api.post('/api/2fa/confirm', { code: twoFactorConfirmCode.value })
    twoFactorEnabled.value = true
    twoFactorSetup.value = false
    twoFactorQr.value = ''
    twoFactorSecret.value = ''
    twoFactorConfirmCode.value = ''
    $q.notify({ type: 'positive', message: t('settings.twoFactorEnabled') })
  } catch (e) {
    const msg = e?.response?.data?.errors?.code?.[0] || e?.response?.data?.message || t('settings.invalidCode')
    $q.notify({ type: 'negative', message: msg })
  } finally {
    twoFactorBusy.value = false
  }
}

function cancelTwoFactorSetup () {
  twoFactorSetup.value = false
  twoFactorQr.value = ''
  twoFactorSecret.value = ''
  twoFactorConfirmCode.value = ''
}

function openDisableTwoFactor () {
  twoFactorDisablePassword.value = ''
  twoFactorDisableCode.value = ''
  twoFactorDisableOpen.value = true
}

async function disableTwoFactor () {
  twoFactorBusy.value = true
  try {
    await api.delete('/api/2fa', {
      data: {
        password: twoFactorDisablePassword.value,
        code: twoFactorDisableCode.value
      }
    })
    twoFactorEnabled.value = false
    twoFactorDisableOpen.value = false
    twoFactorDisablePassword.value = ''
    twoFactorDisableCode.value = ''
    $q.notify({ type: 'positive', message: t('settings.twoFactorDisabled') })
  } catch (e) {
    const errors = e?.response?.data?.errors
    const msg = errors?.password?.[0] || errors?.code?.[0] || e?.response?.data?.message || t('settings.disableFailed')
    $q.notify({ type: 'negative', message: msg })
  } finally {
    twoFactorBusy.value = false
  }
}

async function load () {
  await settingsStore.fetch(true)
  applySettings(settingsStore.settings)
  if (settingsStore.ssl?.email) sslEmail.value = settingsStore.ssl.email
  await Promise.all([loadTwoFactor(), loadTelegramConnections()])
  void projectUpdate.fetchStatus({ silent: true })
}

async function loadTelegramConnections () {
  try {
    const { data } = await api.get('/api/resolver/connections')
    const list = data.connections || data || []
    telegramConnectionOptions.value = (Array.isArray(list) ? list : []).map((c) => ({
      label: c.name || `#${c.id}`,
      value: c.id
    }))
  } catch {
    telegramConnectionOptions.value = []
  }
}

function openTelegramProxyDialog () {
  telegramProxyDraftUrl.value = ''
  telegramProxyDialogError.value = ''
  telegramProxyDialogOpen.value = true
}

function addTelegramProxyUrl (url) {
  if (!Array.isArray(form.telegram_proxies)) form.telegram_proxies = []
  form.telegram_proxies.push({
    id: `p${Date.now().toString(36)}`,
    type: 'url',
    url: String(url || '').trim(),
    enabled: true
  })
}

function telegramProxyLabel (proxy) {
  const url = String(proxy?.url || '').trim()
  if (!url) return `#${proxy?.id || ''}`
  try {
    const parts = new URL(url)
    const auth = parts.username ? '***@' : ''
    return `${parts.protocol}//${auth}${parts.host}`
  } catch {
    return url.length > 48 ? `${url.slice(0, 45)}…` : url
  }
}

function onTelegramProxyEnabledChange (proxy, enabled) {
  if (enabled) {
    proxy.enabled = true
    return
  }
  $q.dialog({
    title: t('settings.telegramDisableProxyTitle'),
    message: t('settings.telegramDisableProxyConfirm', { url: telegramProxyLabel(proxy) }),
    cancel: { label: t('common.cancel'), flat: true },
    ok: { label: t('settings.telegramDisableProxy'), color: 'warning' },
    persistent: true
  }).onOk(() => {
    proxy.enabled = false
  })
}

function confirmRemoveTelegramProxy (proxy) {
  $q.dialog({
    title: t('settings.telegramRemoveProxyTitle'),
    message: t('settings.telegramRemoveProxyConfirm', { url: telegramProxyLabel(proxy) }),
    cancel: { label: t('common.cancel'), flat: true },
    ok: { label: t('common.delete'), color: 'negative' },
    persistent: true
  }).onOk(() => {
    removeTelegramProxy(proxy.id)
  })
}

function removeTelegramProxy (id) {
  form.telegram_proxies = normalizeTelegramProxies(form.telegram_proxies).filter((p) => p.id !== id)
}

function telegramErrorText (payload, fallbackKey = 'settings.telegramTestFailed') {
  if (payload?.response) {
    return apiErrorMessage(payload, t(fallbackKey))
  }
  const message = String(payload?.message || '').trim()
  if (message) return message
  const errors = payload?.errors
  if (errors && typeof errors === 'object') {
    for (const key of Object.keys(errors)) {
      const first = Array.isArray(errors[key]) ? errors[key][0] : errors[key]
      const text = String(first || '').trim()
      if (text) return text
    }
  }
  return t(fallbackKey)
}

async function submitTelegramProxyDialog () {
  telegramProxyDialogError.value = ''
  const url = String(telegramProxyDraftUrl.value || '').trim()
  if (!url) {
    telegramProxyDialogError.value = t('settings.telegramProxyUrlEmpty')
    return
  }

  const already = normalizeTelegramProxies(form.telegram_proxies).some(
    (p) => p.type === 'url' && String(p.url || '').trim() === url
  )
  if (already) {
    telegramProxyDialogError.value = t('settings.telegramProxyAlreadyAdded')
    return
  }

  telegramProxyChecking.value = true
  try {
    const token = String(form.telegram_bot_token || '').trim()
    const { data } = await api.post('/api/settings/test-telegram-proxy', {
      url,
      token: token && !token.includes('*') ? token : undefined
    })
    addTelegramProxyUrl(url)
    telegramProxyDialogOpen.value = false
    telegramProxyDraftUrl.value = ''
    telegramProxyDialogError.value = ''
    $q.notify({
      type: 'positive',
      message: data?.message || t('settings.telegramProxyAdded', { ms: data?.latency_ms || 0 })
    })
  } catch (e) {
    telegramProxyDialogError.value = telegramErrorText(
      e,
      'settings.telegramProxyCheckFailed'
    )
  } finally {
    telegramProxyChecking.value = false
  }
}

async function testTelegram (probeProxies) {
  telegramTesting.value = true
  telegramTestResult.value = null
  telegramProxyProbe.value = []
  try {
    const { data } = await api.post('/api/settings/test-telegram', { probe_proxies: !!probeProxies })
    const username = data?.bot?.username ? `@${data.bot.username}` : ''
    telegramTestResult.value = {
      ok: true,
      text: data?.message
        || (username ? t('settings.telegramTestOk', { username }) : t('settings.telegramTestOkGeneric'))
    }
    if (Array.isArray(data.proxies)) telegramProxyProbe.value = data.proxies
  } catch (e) {
    telegramTestResult.value = {
      ok: false,
      text: telegramErrorText(e, 'settings.telegramTestFailed')
    }
  } finally {
    telegramTesting.value = false
  }
}

async function save () {
  if (!isDirty.value) return
  saving.value = true
  domainError.value = ''
  try {
    const payload = {
      ...form,
      panel_domain: String(form.panel_domain || '').trim() || null,
      endpoint_use_domain: hasDomain.value ? !!form.endpoint_use_domain : false,
      panel_https_port: String(form.panel_https_port || '7443').trim() || '7443',
      timezone: String(form.timezone || 'UTC').trim() || 'UTC',
      telegram_bot_token: String(form.telegram_bot_token || '').trim(),
      telegram_admin_id: String(form.telegram_admin_id || '').trim(),
      telegram_mode: form.telegram_mode || 'polling',
      telegram_language: form.telegram_language || 'en',
      telegram_proxy_strategy: form.telegram_proxy_strategy || 'fastest',
      telegram_notifications_enabled: !!form.telegram_notifications_enabled,
      singbox_egress_interface: String(form.singbox_egress_interface || 'auto').trim() || 'auto',
      telegram_proxies: form.telegram_proxies
        .filter((p) => p.type === 'url' ? String(p.url || '').trim() !== '' : !!p.connection_id)
        .map((p) => ({
          id: p.id,
          type: p.type,
          enabled: p.enabled !== false,
          ...(p.type === 'url' ? { url: p.url } : { connection_id: Number(p.connection_id) })
        }))
    }
    delete payload.telegram_bot_token_set
    const { data } = await api.put('/api/settings', payload)
    settingsStore.applyResponse(data)
    applySettings(data.settings || {})
    $q.notify({ type: 'positive', message: t('settings.settingsSaved') })
    if (data.telegram_sync && data.telegram_sync.ok === false) {
      $q.notify({
        type: 'warning',
        message: data.telegram_sync.message || t('settings.telegramSyncWarn')
      })
    }
  } catch (e) {
    const errors = e?.response?.data?.errors
    const panelDomainMsg = errors?.panel_domain?.[0]
    if (panelDomainMsg) {
      domainError.value = panelDomainMsg
      form.panel_domain = ''
      form.endpoint_use_domain = false
    }
    const firstError = errors && Object.values(errors).flat().find(Boolean)
    $q.notify({
      type: 'negative',
      message: firstError || e?.response?.data?.message || t('settings.saveError')
    })
  } finally {
    saving.value = false
  }
}

async function redirectToHttpsPanel (panelUrl) {
  const target = String(panelUrl || settingsStore.panelUrl || '').trim()
  if (!target.startsWith('https://')) return false
  if (window.location.href.startsWith(target)) return false
  $q.notify({
    type: 'positive',
    message: t('settings.switchingToHttps'),
    timeout: 1500
  })
  const path = window.location.pathname + window.location.search + window.location.hash
  window.location.replace(target.replace(/\/$/, '') + (path.startsWith('/') ? path : '/'))
  return true
}

async function tryRecoverSslAndRedirect () {
  try {
    const data = await settingsStore.sslRecover()
    if (data?.ok) {
      $q.notify({
        type: 'positive',
        message: data.message || t('settings.certPickedUp')
      })
      await redirectToHttpsPanel(data.panel_url)
      return true
    }
  } catch (_) {
    // no cert to recover
  }
  return false
}

async function issueSsl (renew) {
  const email = String(sslEmail.value || '').trim()
  if (!email) {
    $q.notify({ type: 'warning', message: t('settings.specifyLetsEncryptEmail') })
    return
  }
  sslIssuing.value = true
  try {
    const data = await settingsStore.sslIssueStart(email, renew)
    if (data?.redirect || data?.recovered) {
      $q.notify({
        type: 'positive',
        message: data.message || t('settings.httpsEnabledNotify')
      })
      await redirectToHttpsPanel(data.panel_url)
      return
    }
    $q.notify({
      type: 'info',
      message: data.message || t('settings.addTxtNotify')
    })
  } catch (e) {
    const msg = e?.response?.data?.message || ''
    if (e?.response?.data?.redirect || e?.response?.data?.recovered) {
      settingsStore.applyResponse(e.response.data)
      await redirectToHttpsPanel(e.response.data.panel_url)
      return
    }
    if (msg.includes('Successfully received certificate')) {
      if (await tryRecoverSslAndRedirect()) return
    }
    if (await tryRecoverSslAndRedirect()) return
    $q.notify({
      type: 'negative',
      message: msg || t('settings.certIssueStartError')
    })
  } finally {
    sslIssuing.value = false
  }
}

async function completeSsl () {
  sslCompleting.value = true
  try {
    const data = await settingsStore.sslIssueComplete()
    $q.notify({
      type: 'positive',
      message: data.message || t('settings.certIssued')
    })
    if (data?.redirect !== false) {
      await redirectToHttpsPanel(data.panel_url)
    }
  } catch (e) {
    const body = e?.response?.data
    if (body?.ok && (body.redirect || body.recovered)) {
      settingsStore.applyResponse(body)
      $q.notify({
        type: 'positive',
        message: body.message || t('settings.certPickedUp')
      })
      await redirectToHttpsPanel(body.panel_url)
      return
    }
    if (await tryRecoverSslAndRedirect()) return
    $q.notify({
      type: 'negative',
      message: body?.message || t('settings.certFinishError')
    })
  } finally {
    sslCompleting.value = false
  }
}

async function abortSsl () {
  try {
    await settingsStore.sslAbort()
    $q.notify({ type: 'info', message: t('settings.issueCancelled') })
  } catch (e) {
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('settings.cancelFailed')
    })
  }
}

async function disableSsl () {
  sslDisabling.value = true
  try {
    const data = await settingsStore.sslDisable()
    $q.notify({
      type: 'positive',
      message: data.message || t('settings.httpsDisabledNotify')
    })
  } catch (e) {
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('settings.disableHttpsError')
    })
  } finally {
    sslDisabling.value = false
  }
}

async function testWebhook () {
  testing.value = true
  try {
    const { data } = await api.post('/api/settings/test-webhook')
    $q.notify({
      type: data.ok ? 'positive' : 'warning',
      message: data.ok ? t('settings.webhookTestSent') : t('settings.webhookTestFailedCode', { code: data.exit_code })
    })
  } catch (e) {
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('settings.testFailed')
    })
  } finally {
    testing.value = false
  }
}

function formatUpdateTs (value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString()
}

watch(activeTab, (tab) => {
  if (tab === 'update') void projectUpdate.fetchStatus({ silent: true })
  if (tab === 'panel') void awgKernel.fetchStatus({ silent: true })
})

const awgKernelStatusLabel = computed(() => {
  if (awgKernel.running) return t('settings.awgKernelRunning')
  const map = {
    ok: t('settings.awgKernelOk'),
    error: t('settings.awgKernelError'),
    idle: t('settings.awgKernelIdle'),
    running: t('settings.awgKernelRunning')
  }
  return map[awgKernel.op_status] || t('settings.awgKernelIdle')
})

const awgKernelStatusClass = computed(() => {
  if (awgKernel.running) return 'text-primary'
  if (awgKernel.op_status === 'ok') return 'text-positive'
  if (awgKernel.op_status === 'error') return 'text-negative'
  return 'text-grey-5'
})

async function onAwgKernelInstall () {
  try {
    await awgKernel.startInstall()
    $q.notify({ type: 'positive', message: t('settings.awgKernelStarted') })
  } catch (e) {
    const code = e?.response?.status
    $q.notify({
      type: code === 409 ? 'warning' : 'negative',
      message: e?.response?.data?.message || t(code === 409 ? 'settings.awgKernelAlreadyRunning' : 'settings.awgKernelStartError')
    })
  }
}

function onAwgKernelUninstall () {
  $q.dialog({
    title: t('settings.awgKernelUninstallConfirmTitle'),
    message: t('settings.awgKernelUninstallConfirmText'),
    cancel: true,
    persistent: true,
    ok: { label: t('settings.awgKernelUninstall'), color: 'negative' }
  }).onOk(async () => {
    try {
      await awgKernel.startUninstall()
      $q.notify({ type: 'positive', message: t('settings.awgKernelStarted') })
    } catch (e) {
      const code = e?.response?.status
      $q.notify({
        type: code === 409 ? 'warning' : 'negative',
        message: e?.response?.data?.message || t(code === 409 ? 'settings.awgKernelAlreadyRunning' : 'settings.awgKernelStartError')
      })
    }
  })
}

async function checkForUpdatesFromSettings () {
  try {
    const data = await projectUpdate.checkForUpdates()
    if (data?.release_check_error) {
      $q.notify({
        type: 'negative',
        message: data.release_check_error
      })
      return
    }
    if (data?.update_available) {
      $q.notify({
        type: 'warning',
        message: t('settings.updateAvailableHint', { version: data.latest_version || '—' })
      })
    } else {
      $q.notify({
        type: 'positive',
        message: t('settings.upToDate')
      })
    }
  } catch (e) {
    $q.notify({
      type: 'negative',
      message: e?.response?.data?.message || t('settings.updateCheckError')
    })
  }
}

function confirmProjectUpdate () {
  $q.dialog({
    title: t('settings.updateConfirmTitle'),
    message: t('settings.updateConfirmText'),
    cancel: { label: t('common.cancel'), flat: true },
    ok: { label: t('settings.updateNow'), color: 'primary' },
    persistent: true
  }).onOk(startProjectUpdate)
}

async function startProjectUpdate () {
  try {
    await projectUpdate.startUpdate()
    $q.notify({
      type: 'info',
      message: t('settings.updateStarted')
    })
    await projectUpdate.fetchStatus({ silent: true })
  } catch (e) {
    const status = e?.response?.status
    const msg = e?.response?.data?.message || t('settings.updateStartError')
    if (status === 409) {
      await projectUpdate.fetchStatus({ silent: true })
      $q.notify({ type: 'warning', message: t('settings.updateAlreadyRunning') })
      return
    }
    $q.notify({ type: 'negative', message: msg })
  }
}

onMounted(load)
</script>

<style scoped>
.settings-card {
  overflow: visible;
}
.settings-tabs {
  padding: 0 8px;
}
.settings-tabs :deep(.q-tab) {
  min-height: 44px;
  text-transform: none;
}
.settings-panels {
  background: transparent;
  min-height: 320px;
}
.settings-panels :deep(.q-tab-panel) {
  background: transparent;
  min-height: 280px;
}
.general-appearance-row {
  align-items: flex-start;
}
.mono {
  font-family: var(--theme-mono);
  font-size: 12px;
}
.schema-pre {
  white-space: pre-wrap;
  background: var(--surface-bg);
  padding: 12px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
}
.challenge-box {
  padding: 12px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
  background: var(--surface-bg);
}
.twofa-qr {
  width: 160px;
  height: 160px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
  background: #fff;
}
.update-log-pre {
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 280px;
  overflow: auto;
  background: var(--surface-bg);
  padding: 12px;
  border-radius: var(--surface-radius);
  border: 1px solid var(--surface-border);
  margin: 0;
}
</style>
