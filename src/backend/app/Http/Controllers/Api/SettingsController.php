<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\SslCertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(
        private AmneziaWgService $awg,
        private SslCertificateService $ssl,
    ) {}

    public function show()
    {
        if ($this->awg->ensureDbDefaults()) {
            $this->awg->bootstrapRuntime();
        }

        $all = Setting::allKeyed();

        return response()->json([
            'settings' => $all,
            'display_endpoint' => $this->awg->resolveEndpointHost(),
            'panel_url' => $this->awg->resolvePanelUrl(),
            'ssl' => $this->ssl->status(),
            'webhook_schema' => $this->webhookSchema(),
            'timezones' => $this->timezoneOptions(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'server_endpoint' => ['sometimes', 'string', 'max:255'],
            'panel_domain' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'endpoint_use_domain' => ['sometimes', 'boolean'],
            'panel_port' => ['sometimes', 'string', 'max:10'],
            'panel_https_port' => ['sometimes', 'string', 'max:10'],
            'failure_webhook_url' => ['nullable', 'string', 'max:2048'],
            'timezone' => ['sometimes', 'string', 'max:64', Rule::in(timezone_identifiers_list())],
        ]);

        $serverEndpoint = array_key_exists('server_endpoint', $data)
            ? trim((string) $data['server_endpoint'])
            : trim((string) Setting::getValue('server_endpoint', 'auto'));

        $panelDomain = array_key_exists('panel_domain', $data)
            ? trim((string) ($data['panel_domain'] ?? ''))
            : trim((string) Setting::getValue('panel_domain', ''));

        $useDomain = array_key_exists('endpoint_use_domain', $data)
            ? (bool) $data['endpoint_use_domain']
            : filter_var(Setting::getValue('endpoint_use_domain', '0'), FILTER_VALIDATE_BOOLEAN);

        $oldHttpPort = (string) Setting::getValue('panel_port', env('PANEL_PORT', '8877'));
        $oldHttpsPort = (string) Setting::getValue('panel_https_port', env('PANEL_HTTPS_PORT', '7443'));
        $oldDomain = trim((string) Setting::getValue('panel_domain', ''));

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

        $data['panel_port'] = $httpPort;
        $data['panel_https_port'] = $httpsPort;

        if ($panelDomain === '') {
            $useDomain = false;
            $data['endpoint_use_domain'] = false;
            $data['panel_domain'] = '';
        } else {
            try {
                $this->awg->assertDomainPointsToPublicIp($panelDomain, $serverEndpoint);
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

        foreach ($data as $key => $value) {
            if ($key === 'endpoint_use_domain') {
                Setting::setValue($key, filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
                continue;
            }
            Setting::setValue($key, $value ?? '');
        }

        if (array_key_exists('timezone', $data)) {
            $tz = $this->awg->applyTimezone((string) $data['timezone']);
            $this->awg->syncTimezoneToHostEnv($tz);
        }

        $domainClearedOrChanged = $panelDomain === '' || ($oldDomain !== '' && strcasecmp($oldDomain, $panelDomain) !== 0);
        if ($domainClearedOrChanged && $this->ssl->isSslEnabled()) {
            $this->ssl->disable();
        }

        $this->awg->writeWebhookConf();

        $portsChanged = $oldHttpPort !== $httpPort || $oldHttpsPort !== $httpsPort;
        if ($portsChanged) {
            $this->awg->syncPanelUrlToHostEnv();
            try {
                $this->ssl->recreateCaddy();
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => __('settings.caddy_ports_apply_failed', ['error' => $e->getMessage()]),
                    'settings' => Setting::allKeyed(),
                    'display_endpoint' => $this->awg->resolveEndpointHost(),
                    'panel_url' => $this->awg->resolvePanelUrl(),
                    'ssl' => $this->ssl->status(),
                    'timezones' => $this->timezoneOptions(),
                ], 500);
            }
        } else {
            $this->awg->syncPanelUrlToHostEnv();
        }

        $all = Setting::allKeyed();

        return response()->json([
            'settings' => $all,
            'display_endpoint' => $this->awg->resolveEndpointHost(),
            'panel_url' => $this->awg->resolvePanelUrl(),
            'ssl' => $this->ssl->status(),
            'timezones' => $this->timezoneOptions(),
        ]);
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
                'settings' => Setting::allKeyed(),
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
            'settings' => Setting::allKeyed(),
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
            'settings' => Setting::allKeyed(),
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
            'settings' => Setting::allKeyed(),
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
                'settings' => Setting::allKeyed(),
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
     * On false-negative certbot errors, activate existing cert and ask UI to redirect.
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
                        'settings' => Setting::allKeyed(),
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
