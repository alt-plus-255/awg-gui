<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\SslCertificateService;
use App\Services\Docker\PanelOpsClient;
use App\Services\Resolver\EgressInterfaceResolver;
use App\Services\System\ProjectUpdateService;
use App\Services\Telegram\TelegramSettings;
use App\Services\Telegram\TelegramWebhookSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        private AmneziaWgService $awg,
        private SslCertificateService $ssl,
        private ProjectUpdateService $projectUpdate,
        private TelegramSettings $telegram,
        private TelegramWebhookSync $telegramSync,
        private EgressInterfaceResolver $egress,
        private PanelOpsClient $panelOps,
    ) {}

    public function show()
    {
        if ($this->awg->ensureDbDefaults()) {
            $this->awg->bootstrapRuntime();
        }

        $all = Setting::allKeyed();
        $tg = $this->telegram->forApi();
        foreach ($tg as $key => $value) {
            $all[$key] = $value;
        }

        return response()->json([
            'settings' => $all,
            'display_endpoint' => $this->awg->resolveEndpointHost(),
            'panel_url' => $this->awg->resolvePanelUrl(),
            'ssl' => $this->ssl->status(),
            'webhook_schema' => $this->webhookSchema(),
            'timezones' => $this->timezoneOptions(),
            'egress' => $this->egress->status(),
        ]);
    }

    public function detectPublicIp()
    {
        $ip = $this->awg->detectPublicIpv4();
        if ($ip === '') {
            return response()->json([
                'message' => __('settings.public_ip_detect_failed'),
            ], 422);
        }

        return response()->json([
            'public_ip' => $ip,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'server_endpoint' => ['sometimes', 'string', 'max:255'],
            'panel_domain' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'endpoint_use_domain' => ['sometimes', 'boolean'],
            'redirect_ip_to_domain' => ['sometimes', 'boolean'],
            'panel_port' => ['sometimes', 'string', 'max:10'],
            'panel_https_port' => ['sometimes', 'string', 'max:10'],
            'failure_webhook_url' => ['nullable', 'string', 'max:2048'],
            'timezone' => ['sometimes', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
            'telegram_bot_token' => ['sometimes', 'nullable', 'string', 'max:256'],
            'telegram_admin_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'telegram_mode' => ['sometimes', 'string', Rule::in(['polling', 'webhook'])],
            'telegram_proxies' => ['sometimes', 'array'],
            'telegram_proxies.*.id' => ['nullable', 'string', 'max:32'],
            'telegram_proxies.*.type' => ['required_with:telegram_proxies', 'string', Rule::in(['url', 'connection'])],
            'telegram_proxies.*.url' => ['nullable', 'string', 'max:2048'],
            'telegram_proxies.*.connection_id' => ['nullable', 'integer', 'min:1'],
            'telegram_proxies.*.enabled' => ['sometimes', 'boolean'],
            'telegram_proxy_strategy' => ['sometimes', 'string', Rule::in(['fastest', 'first_ok'])],
            'telegram_notifications_enabled' => ['sometimes', 'boolean'],
            'telegram_daily_report_enabled' => ['sometimes', 'boolean'],
            'telegram_language' => ['sometimes', 'string', Rule::in(['en', 'ru'])],
            'singbox_egress_interface' => ['sometimes', 'string', 'max:32'],
        ]);

        if (array_key_exists('singbox_egress_interface', $data)) {
            $iface = trim((string) $data['singbox_egress_interface']);
            if ($iface === '' || strtolower($iface) === EgressInterfaceResolver::AUTO) {
                $data['singbox_egress_interface'] = EgressInterfaceResolver::AUTO;
            } elseif (! $this->egress->isValidIfaceName($iface)) {
                return response()->json([
                    'message' => __('settings.invalid_egress_interface'),
                    'errors' => ['singbox_egress_interface' => [__('settings.invalid_egress_interface')]],
                ], 422);
            } else {
                $data['singbox_egress_interface'] = $iface;
            }
        }

        // Frontend always sends Telegram fields; only sync/rebuild when values actually change.
        $telegramChanged = false;
        $proxiesChanged = false;

        if (array_key_exists('telegram_proxies', $data)) {
            $normalized = $this->normalizeTelegramProxies($data['telegram_proxies'] ?? []);
            $encoded = $this->telegram->encodeProxies($normalized);
            $proxiesChanged = $encoded !== (string) Setting::getValue('telegram_proxies', '[]');
            if ($proxiesChanged) {
                Setting::setValue('telegram_proxies', $encoded);
                $telegramChanged = true;
            }
            unset($data['telegram_proxies']);
        }

        if (array_key_exists('telegram_bot_token', $data)) {
            $token = trim((string) ($data['telegram_bot_token'] ?? ''));
            // Empty or masked value keeps the existing token.
            if ($token !== '' && ! str_contains($token, '*')) {
                if ($token !== (string) Setting::getValue('telegram_bot_token', '')) {
                    Setting::setValue('telegram_bot_token', $token);
                    $telegramChanged = true;
                }
            }
            unset($data['telegram_bot_token']);
        }

        if (array_key_exists('telegram_admin_id', $data)) {
            $adminId = trim((string) ($data['telegram_admin_id'] ?? ''));
            if ($adminId !== (string) Setting::getValue('telegram_admin_id', '')) {
                Setting::setValue('telegram_admin_id', $adminId);
                $telegramChanged = true;
            }
            unset($data['telegram_admin_id']);
        }

        if (array_key_exists('telegram_mode', $data)) {
            $mode = (string) $data['telegram_mode'];
            if ($mode !== (string) Setting::getValue('telegram_mode', TelegramSettings::MODE_POLLING)) {
                Setting::setValue('telegram_mode', $mode);
                $telegramChanged = true;
            }
            unset($data['telegram_mode']);
        }

        if (array_key_exists('telegram_proxy_strategy', $data)) {
            $strategy = (string) $data['telegram_proxy_strategy'];
            if ($strategy !== (string) Setting::getValue('telegram_proxy_strategy', TelegramSettings::STRATEGY_FASTEST)) {
                Setting::setValue('telegram_proxy_strategy', $strategy);
                $telegramChanged = true;
            }
            unset($data['telegram_proxy_strategy']);
        }

        if (array_key_exists('telegram_notifications_enabled', $data)) {
            $enabled = filter_var($data['telegram_notifications_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            if ($enabled !== (string) Setting::getValue('telegram_notifications_enabled', '1')) {
                Setting::setValue('telegram_notifications_enabled', $enabled);
                $telegramChanged = true;
            }
            unset($data['telegram_notifications_enabled']);
        }

        if (array_key_exists('telegram_daily_report_enabled', $data)) {
            $enabled = filter_var($data['telegram_daily_report_enabled'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            if ($enabled !== (string) Setting::getValue('telegram_daily_report_enabled', '1')) {
                Setting::setValue('telegram_daily_report_enabled', $enabled);
                $telegramChanged = true;
            }
            unset($data['telegram_daily_report_enabled']);
        }

        if (array_key_exists('telegram_language', $data)) {
            $language = (string) $data['telegram_language'];
            if ($language !== (string) Setting::getValue('telegram_language', TelegramSettings::LANG_EN)) {
                Setting::setValue('telegram_language', $language);
                $telegramChanged = true;
            }
            unset($data['telegram_language']);
        }

        if ($telegramChanged) {
            $this->telegramSync->ensureWebhookSecret();
        }

        $serverEndpoint = array_key_exists('server_endpoint', $data)
            ? trim((string) $data['server_endpoint'])
            : trim((string) Setting::getValue('server_endpoint', 'auto'));

        $panelDomain = array_key_exists('panel_domain', $data)
            ? trim((string) ($data['panel_domain'] ?? ''))
            : trim((string) Setting::getValue('panel_domain', ''));

        $useDomain = array_key_exists('endpoint_use_domain', $data)
            ? (bool) $data['endpoint_use_domain']
            : filter_var(Setting::getValue('endpoint_use_domain', '0'), FILTER_VALIDATE_BOOLEAN);

        $redirectIp = array_key_exists('redirect_ip_to_domain', $data)
            ? (bool) $data['redirect_ip_to_domain']
            : filter_var(Setting::getValue('redirect_ip_to_domain', '0'), FILTER_VALIDATE_BOOLEAN);

        $oldHttpPort = (string) Setting::getValue('panel_port', env('PANEL_PORT', '8877'));
        $oldHttpsPort = (string) Setting::getValue('panel_https_port', env('PANEL_HTTPS_PORT', '7443'));
        $oldDomain = trim((string) Setting::getValue('panel_domain', ''));
        $oldRedirectIp = filter_var(Setting::getValue('redirect_ip_to_domain', '0'), FILTER_VALIDATE_BOOLEAN);

        $httpPort = array_key_exists('panel_port', $data)
            ? trim((string) $data['panel_port'])
            : $oldHttpPort;
        $httpsPort = array_key_exists('panel_https_port', $data)
            ? trim((string) $data['panel_https_port'])
            : $oldHttpsPort;

        try {
            $this->awg->assertPanelPorts($httpPort, $httpsPort);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'panel_port' => [$e->getMessage()],
                    'panel_https_port' => [$e->getMessage()],
                ],
            ], 422);
        }

        if (array_key_exists('panel_port', $data) || array_key_exists('panel_https_port', $data)) {
            $data['panel_port'] = $httpPort;
            $data['panel_https_port'] = $httpsPort;
        }

        if (array_key_exists('panel_domain', $data) || array_key_exists('endpoint_use_domain', $data) || array_key_exists('redirect_ip_to_domain', $data) || array_key_exists('server_endpoint', $data)) {
            if ($panelDomain === '') {
                $useDomain = false;
                $redirectIp = false;
                $data['endpoint_use_domain'] = false;
                $data['redirect_ip_to_domain'] = false;
                $data['panel_domain'] = '';
            } else {
                try {
                    // Compare DNS to the real public IPv4, not WireGuard endpoint
                    // (endpoint may be a LAN address on home/NAT installs).
                    $this->awg->assertPanelDomainDns($panelDomain);
                } catch (\InvalidArgumentException $e) {
                    return response()->json([
                        'message' => $e->getMessage(),
                        'errors' => ['panel_domain' => [$e->getMessage()]],
                    ], 422);
                }
            }

            if (array_key_exists('endpoint_use_domain', $data) || $panelDomain === '') {
                $data['endpoint_use_domain'] = $useDomain ? '1' : '0';
            }
            if (array_key_exists('redirect_ip_to_domain', $data) || $panelDomain === '') {
                $data['redirect_ip_to_domain'] = $redirectIp ? '1' : '0';
            }
        }

        foreach ($data as $key => $value) {
            if ($key === 'endpoint_use_domain' || $key === 'redirect_ip_to_domain') {
                Setting::setValue($key, filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
                continue;
            }
            Setting::setValue($key, $value ?? '');
        }

        if (array_key_exists('timezone', $data)) {
            $tz = $this->awg->applyTimezone((string) $data['timezone']);
            $this->awg->syncTimezoneToHostEnv($tz);
        }

        // Only touch SSL when the domain field itself changed in this request.
        // An empty domain on unrelated saves must not wipe an active certificate.
        $domainTouched = array_key_exists('panel_domain', $data);
        $domainClearedOrChanged = $domainTouched && (
            $panelDomain === ''
            || ($oldDomain !== '' && strcasecmp($oldDomain, $panelDomain) !== 0)
        );
        if ($domainClearedOrChanged) {
            if ($this->ssl->isSslEnabled()) {
                $this->ssl->disable();
            } else {
                $this->ssl->abortChallenge(quiet: true);
            }
        }

        $this->awg->writeWebhookConf();

        $portsChanged = $oldHttpPort !== $httpPort || $oldHttpsPort !== $httpsPort;
        $redirectChanged = $oldRedirectIp !== filter_var(Setting::getValue('redirect_ip_to_domain', '0'), FILTER_VALIDATE_BOOLEAN);
        if ($portsChanged) {
            $this->awg->syncPanelUrlToHostEnv();
            try {
                if ($this->ssl->isSslEnabled()) {
                    $this->ssl->writeCaddyfile(true);
                }
                $this->ssl->recreateCaddy();
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => __('settings.caddy_ports_apply_failed', ['error' => $e->getMessage()]),
                    'settings' => $this->settingsPayload(),
                    'display_endpoint' => $this->awg->resolveEndpointHost(),
                    'panel_url' => $this->awg->resolvePanelUrl(),
                    'ssl' => $this->ssl->status(),
                    'timezones' => $this->timezoneOptions(),
                ], 500);
            }
        } else {
            $this->awg->syncPanelUrlToHostEnv();
            if ($redirectChanged) {
                try {
                    $this->ssl->refreshSslCaddyfileIfEnabled();
                } catch (\Throwable $e) {
                    return response()->json([
                        'message' => __('settings.caddy_reload_failed').': '.$e->getMessage(),
                        'settings' => $this->settingsPayload(),
                        'display_endpoint' => $this->awg->resolveEndpointHost(),
                        'panel_url' => $this->awg->resolvePanelUrl(),
                        'ssl' => $this->ssl->status(),
                        'timezones' => $this->timezoneOptions(),
                    ], 500);
                }
            }
        }

        $telegramSync = null;
        if ($telegramChanged) {
            $telegramSync = $this->telegramSync->syncAfterSettingsChange($proxiesChanged);
        }

        if (array_key_exists('singbox_egress_interface', $data)) {
            $this->egress->forgetCache();
            try {
                $this->awg->applyConfig();
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => __('settings.egress_apply_failed', ['error' => $e->getMessage()]),
                    'settings' => $this->settingsPayload(),
                    'display_endpoint' => $this->awg->resolveEndpointHost(),
                    'panel_url' => $this->awg->resolvePanelUrl(),
                    'ssl' => $this->ssl->status(),
                    'timezones' => $this->timezoneOptions(),
                    'egress' => $this->egress->status(),
                    'telegram_sync' => $telegramSync,
                ], 500);
            }
        }

        return response()->json([
            'settings' => $this->settingsPayload(),
            'display_endpoint' => $this->awg->resolveEndpointHost(),
            'panel_url' => $this->awg->resolvePanelUrl(),
            'ssl' => $this->ssl->status(),
            'timezones' => $this->timezoneOptions(),
            'egress' => $this->egress->status(),
            'telegram_sync' => $telegramSync,
        ]);
    }

    public function testTelegram(Request $request)
    {
        $probe = filter_var($request->input('probe_proxies', false), FILTER_VALIDATE_BOOLEAN);
        $result = $this->telegramSync->testBot($probe);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function testTelegramProxy(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'token' => ['nullable', 'string', 'max:256'],
        ]);

        $token = trim((string) ($data['token'] ?? ''));
        if ($token === '' || str_contains($token, '*')) {
            $token = null;
        }

        $result = $this->telegramSync->testProxyUrl(trim((string) $data['url']), $token);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function updateStatus()
    {
        return response()->json($this->projectUpdate->status());
    }

    public function checkProjectUpdates()
    {
        return response()->json($this->projectUpdate->checkForUpdates());
    }

    public function startProjectUpdate(Request $request)
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:64', 'regex:/^v?[A-Za-z0-9._-]+$/'],
        ]);

        $status = $this->projectUpdate->status();
        if ($status['running'] ?? false) {
            return response()->json($status, 409);
        }

        try {
            $state = $this->projectUpdate->start($data['version'] ?? null);
        } catch (\RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'update_not_available' => __('settings.update_not_available'),
                'update_already_running' => __('settings.update_already_running'),
                default => $e->getMessage(),
            };
            $code = $e->getMessage() === 'update_not_available' ? 422 : 500;

            return response()->json(['message' => $message], $code);
        }

        return response()->json($state, 202);
    }

    public function clearProjectUpdateLog()
    {
        try {
            $state = $this->projectUpdate->clearLog();
        } catch (\RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'update_log_clear_blocked' => __('settings.update_log_clear_blocked'),
                'update_log_clear_failed' => __('settings.update_log_clear_failed'),
                default => $e->getMessage(),
            };
            $code = $e->getMessage() === 'update_log_clear_blocked' ? 409 : 500;

            return response()->json(['message' => $message], $code);
        }

        return response()->json($state);
    }

    public function retryStuckProjectUpdate(Request $request)
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:64', 'regex:/^v?[A-Za-z0-9._-]+$/'],
        ]);

        try {
            $state = $this->projectUpdate->retryStuck($data['version'] ?? null);
        } catch (\RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'update_not_stuck' => __('settings.update_not_stuck'),
                'update_not_available' => __('settings.update_not_available'),
                'update_already_running' => __('settings.update_already_running'),
                default => $e->getMessage(),
            };
            $code = match ($e->getMessage()) {
                'update_not_stuck' => 409,
                'update_not_available' => 422,
                'update_already_running' => 409,
                default => 500,
            };

            return response()->json(['message' => $message], $code);
        }

        return response()->json($state, 202);
    }

    public function awgKernelStatus()
    {
        try {
            $data = $this->panelOps->awgKernelStatus();
        } catch (\RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'module_loaded' => false,
                'package_installed' => false,
                'awg_datapath' => 'unknown',
                'os_family' => 'unknown',
                'script_present' => false,
                'op' => ['status' => 'error', 'message' => $e->getMessage(), 'running' => false],
            ], 503);
        }

        return response()->json($data);
    }

    public function awgKernelInstall()
    {
        try {
            $result = $this->panelOps->startAwgKernelOp('install');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'kernel_op_already_running') {
                return response()->json(['message' => __('settings.awg_kernel_already_running')], 409);
            }

            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($result, 202);
    }

    public function awgKernelUninstall()
    {
        try {
            $result = $this->panelOps->startAwgKernelOp('uninstall');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'kernel_op_already_running') {
                return response()->json(['message' => __('settings.awg_kernel_already_running')], 409);
            }

            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($result, 202);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeTelegramProxies(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $enabled = filter_var($row['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = Str::lower(Str::random(8));
            }

            if ($type === 'url') {
                $url = trim((string) ($row['url'] ?? ''));
                if ($url === '' || str_contains($url, '***')) {
                    // Keep existing URL for this id if masked/empty on update.
                    foreach ($this->telegram->proxies() as $existing) {
                        if (($existing['id'] ?? '') === $id && ($existing['type'] ?? '') === 'url') {
                            $url = (string) $existing['url'];
                            break;
                        }
                    }
                }
                if ($url === '') {
                    continue;
                }
                if (! $this->isAllowedTelegramProxyUrl($url)) {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'type' => 'url',
                    'url' => $url,
                    'enabled' => $enabled,
                ];
                continue;
            }

            if ($type === 'connection') {
                $connectionId = (int) ($row['connection_id'] ?? 0);
                if ($connectionId < 1) {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'type' => 'connection',
                    'connection_id' => $connectionId,
                    'enabled' => $enabled,
                ];
            }
        }

        return $out;
    }

    private function isAllowedTelegramProxyUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return in_array($scheme, ['socks5', 'socks5h', 'http', 'https'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $all = Setting::allKeyed();
        foreach ($this->telegram->forApi() as $key => $value) {
            $all[$key] = $value;
        }

        return $all;
    }

    public function sslIssueStart(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'renew' => ['sometimes', 'boolean'],
        ]);

        try {
            $challenge = $this->ssl->startIssue(
                (string) $data['email'],
                (bool) ($data['renew'] ?? false),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->sslErrorWithRecover($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->sslErrorWithRecover($e->getMessage(), 500);
        }

        if (! empty($challenge['activated'])) {
            return response()->json([
                'ok' => true,
                'recovered' => true,
                'redirect' => true,
                'ssl' => $this->ssl->status(),
                'settings' => $this->settingsPayload(),
                'panel_url' => $this->awg->resolvePanelUrl(),
                'message' => __('settings.ssl_already_issued'),
            ]);
        }

        return response()->json([
            'ok' => true,
            'challenge' => $challenge,
            'ssl' => $this->ssl->status(),
            'message' => __('settings.ssl_add_txt_record'),
        ]);
    }

    public function sslIssueComplete()
    {
        try {
            $ssl = $this->ssl->completeIssue();
        } catch (\InvalidArgumentException $e) {
            return $this->sslErrorWithRecover($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->sslErrorWithRecover($e->getMessage(), 500);
        }

        return response()->json([
            'ok' => true,
            'redirect' => true,
            'ssl' => $ssl,
            'settings' => $this->settingsPayload(),
            'panel_url' => $this->awg->resolvePanelUrl(),
            'message' => __('settings.ssl_issued'),
        ]);
    }

    public function sslRecover()
    {
        try {
            $ssl = $this->ssl->recoverIfCertificateExists();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        if ($ssl === null) {
            return response()->json(['ok' => false, 'message' => __('settings.ssl_cert_not_found')], 404);
        }

        return response()->json([
            'ok' => true,
            'recovered' => true,
            'redirect' => true,
            'ssl' => $ssl,
            'settings' => $this->settingsPayload(),
            'panel_url' => $this->awg->resolvePanelUrl(),
            'message' => __('settings.ssl_cert_found_enabled'),
        ]);
    }

    public function sslDisable()
    {
        try {
            $ssl = $this->ssl->disable();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'ssl' => $ssl,
            'settings' => $this->settingsPayload(),
            'panel_url' => $this->awg->resolvePanelUrl(),
            'message' => __('settings.https_disabled'),
        ]);
    }

    public function sslAbort()
    {
        $this->ssl->abortChallenge();

        $recovered = null;
        try {
            $recovered = $this->ssl->recoverIfCertificateExists();
        } catch (\Throwable) {
            $recovered = null;
        }

        if ($recovered !== null) {
            return response()->json([
                'ok' => true,
                'recovered' => true,
                'redirect' => true,
                'ssl' => $recovered,
                'settings' => $this->settingsPayload(),
                'panel_url' => $this->awg->resolvePanelUrl(),
                'message' => __('settings.ssl_aborted_but_cert_found'),
            ]);
        }

        return response()->json([
            'ok' => true,
            'ssl' => $this->ssl->status(),
            'message' => __('settings.ssl_issue_aborted'),
        ]);
    }

    /**
     * On false-negative ACME errors, activate existing cert and ask UI to redirect.
     */
    private function sslErrorWithRecover(string $message, int $status)
    {
        if (
            str_contains($message, 'Successfully received certificate')
            || $this->ssl->hasLiveCertificate()
        ) {
            try {
                $ssl = $this->ssl->recoverIfCertificateExists();
                if ($ssl !== null) {
                    return response()->json([
                        'ok' => true,
                        'recovered' => true,
                        'redirect' => true,
                        'ssl' => $ssl,
                        'settings' => $this->settingsPayload(),
                        'panel_url' => $this->awg->resolvePanelUrl(),
                        'message' => __('settings.ssl_was_already_issued'),
                    ]);
                }
            } catch (\Throwable) {
                // fall through to original error
            }
        }

        return response()->json(['message' => $message], $status);
    }

    public function restartAwg()
    {
        $result = $this->awg->restartAwg();

        if (! empty($result['already_restarting'])) {
            return response()->json([
                'ok' => false,
                'already_restarting' => true,
                'message' => __('api.awg_restart_already_running'),
                'details' => $result,
            ], 409);
        }

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => __('api.awg_restart_failed'),
                'details' => $result,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => __('api.awg_restart_ok'),
            'details' => $result,
        ]);
    }

    public function testWebhook()
    {
        $url = Setting::getValue('failure_webhook_url', '');
        if (! $url) {
            return response()->json(['ok' => false, 'message' => __('settings.webhook_url_empty')], 422);
        }

        $this->awg->applyTimezone();

        $payload = [
            'schema_version' => '1.0',
            'event' => 'awg_gui.test',
            'severity' => 'info',
            'source' => 'awg-gui',
            'project' => 'awggui',
            'hostname' => gethostname() ?: 'unknown',
            'timestamp' => now()->toIso8601String(),
            'code' => 'awg_gui.test',
            'message' => 'Test failure webhook from AmneziaWG GUI admin',
            'panel_url' => $this->awg->resolvePanelUrl(),
            'details' => [
                'trigger' => 'admin_ui',
            ],
        ];

        $result = Process::timeout(10)->run([
            'curl', '-sS', '-X', 'POST',
            '-H', 'Content-Type: application/json',
            '--data-binary', json_encode($payload),
            '--max-time', '10',
            $url,
        ]);

        return response()->json([
            'ok' => $result->successful(),
            'exit_code' => $result->exitCode(),
            'stderr' => $result->errorOutput(),
            'payload' => $payload,
        ]);
    }

    /** @return list<string> */
    private function timezoneOptions(): array
    {
        $preferred = [
            'UTC',
            'Europe/Kaliningrad',
            'Europe/Moscow',
            'Europe/Samara',
            'Asia/Yekaterinburg',
            'Asia/Omsk',
            'Asia/Krasnoyarsk',
            'Asia/Irkutsk',
            'Asia/Yakutsk',
            'Asia/Vladivostok',
            'Asia/Magadan',
            'Asia/Kamchatka',
            'Europe/Kyiv',
            'Europe/Minsk',
            'Asia/Almaty',
            'Asia/Tashkent',
            'Europe/Berlin',
            'Europe/London',
            'America/New_York',
        ];

        $all = timezone_identifiers_list();
        $ordered = [];
        foreach ($preferred as $tz) {
            if (in_array($tz, $all, true)) {
                $ordered[] = $tz;
            }
        }
        foreach ($all as $tz) {
            if (! in_array($tz, $ordered, true)) {
                $ordered[] = $tz;
            }
        }

        return $ordered;
    }

    private function webhookSchema(): array
    {
        return [
            'schema_version' => '1.0',
            'method' => 'POST',
            'content_type' => 'application/json',
            'example' => [
                'schema_version' => '1.0',
                'event' => 'awg_gui.failure',
                'severity' => 'error',
                'source' => 'awg-gui',
                'project' => 'awggui',
                'hostname' => 'vpn.example.com',
                'timestamp' => '2026-07-15T10:58:00+03:00',
                'code' => 'docker_unavailable',
                'message' => 'Docker daemon did not become ready within timeout',
                'panel_url' => 'http://203.0.113.10:8877',
                'details' => [
                    'attempt' => 1,
                    'services' => ['caddy', 'app', 'db', 'awg'],
                    'stderr' => '...',
                ],
            ],
            'codes' => [
                'docker_unavailable',
                'compose_up_failed',
                'service_unhealthy',
                'awg_gui.test',
            ],
            'fields' => [
                'schema_version' => __('settings.webhook_field_schema_version'),
                'event' => __('settings.webhook_field_event'),
                'severity' => 'info | warning | error | critical',
                'source' => __('settings.webhook_field_source'),
                'project' => __('settings.webhook_field_project'),
                'hostname' => __('settings.webhook_field_hostname'),
                'timestamp' => __('settings.webhook_field_timestamp'),
                'code' => __('settings.webhook_field_code'),
                'message' => __('settings.webhook_field_message'),
                'panel_url' => __('settings.webhook_field_panel_url'),
                'details' => __('settings.webhook_field_details'),
            ],
        ];
    }
}
