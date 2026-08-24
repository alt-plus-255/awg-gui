<?php

namespace App\Services\Telegram;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\System\HostMetricsService;
use Illuminate\Support\Facades\App;

class TelegramDailyReportNotifier
{
    public function __construct(
        private TelegramSettings $settings,
        private TelegramBotClient $bot,
        private HostMetricsService $hostMetrics,
        private AmneziaWgService $awg,
    ) {}

    public function send(): bool
    {
        if (! $this->settings->isConfigured() || ! $this->settings->dailyReportEnabled()) {
            return false;
        }

        App::setLocale($this->settings->language());

        if (! $this->bot->isReady()) {
            return false;
        }

        $text = $this->buildReport();
        $this->bot->sendMessage($this->settings->adminId(), $text);

        return true;
    }

    public function buildReport(): string
    {
        App::setLocale($this->settings->language());

        $na = __('telegram.dashboard_na');
        $now = now()->format('Y-m-d H:i:s');

        $hostname = gethostname() ?: $na;

        $host = [];
        try {
            $host = $this->hostMetrics->collect();
        } catch (\Throwable) {
            $host = [];
        }

        $uptime = $this->formatUptime($host['uptime_seconds'] ?? null);
        $load = $this->formatLoad($host['loadavg'] ?? null);
        $cpu = $this->formatPercent($host['cpu']['percent'] ?? null);
        $memUsed = $this->formatBytes($host['memory']['used'] ?? null);
        $memTotal = $this->formatBytes($host['memory']['total'] ?? null);
        $memPercent = $this->formatPercent($host['memory']['percent'] ?? null);
        $disk = $this->formatPercent($host['disk']['percent'] ?? null);

        $peersTotal = VpnClient::query()->count();
        $enabled = AwgConfigPeer::query()->where('enabled', true)->count();
        $online = AwgConfigPeer::query()->where('online', true)->count();

        $rx = (int) AwgConfigPeer::query()->sum('transfer_rx');
        $tx = (int) AwgConfigPeer::query()->sum('transfer_tx');
        $totalTraffic = $this->formatBytes($rx + $tx);
        $rxLabel = $this->formatBytes($rx);
        $txLabel = $this->formatBytes($tx);

        $awgStatus = $na;
        $endpoint = $na;
        try {
            $awgStatus = $this->awg->isContainerRunning()
                ? __('telegram.dashboard_awg_up')
                : __('telegram.dashboard_awg_down');
            $endpoint = (string) ($this->awg->endpointStatus()['endpoint'] ?? $na);
            if ($endpoint === '') {
                $endpoint = $na;
            }
        } catch (\Throwable) {
            // leave n/a
        }

        $resolverCount = AwgConfig::query()
            ->where('type', 'server')
            ->where('resolver_enabled', true)
            ->count();

        $lines = [
            __('telegram.daily_report_title'),
            __('telegram.daily_report_datetime', ['datetime' => $now]),
            __('telegram.daily_report_hostname', ['hostname' => $this->esc($hostname)]),
            __('telegram.daily_report_uptime', ['uptime' => $uptime]),
            __('telegram.daily_report_load', ['load' => $load]),
            __('telegram.daily_report_cpu', ['cpu' => $cpu]),
            __('telegram.daily_report_memory', [
                'used' => $memUsed,
                'total' => $memTotal,
                'percent' => $memPercent,
            ]),
            __('telegram.daily_report_disk', ['percent' => $disk]),
            __('telegram.daily_report_peers', [
                'peers' => $peersTotal,
                'enabled' => $enabled,
                'online' => $online,
            ]),
            __('telegram.daily_report_traffic', [
                'total' => $totalTraffic,
                'tx' => $txLabel,
                'rx' => $rxLabel,
            ]),
            __('telegram.daily_report_awg', [
                'status' => $awgStatus,
                'endpoint' => $this->esc($endpoint),
            ]),
            __('telegram.daily_report_resolver', ['count' => $resolverCount]),
        ];

        return implode("\n", $lines);
    }

    private function formatUptime(mixed $seconds): string
    {
        if ($seconds === null || $seconds === '') {
            return __('telegram.dashboard_na');
        }

        $seconds = max(0, (int) $seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $mins = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return sprintf('%d d %02d:%02d', $days, $hours, $mins);
        }

        return sprintf('%02d:%02d', $hours, $mins);
    }

    /**
     * @param  array{1?: float|null, 5?: float|null, 15?: float|null}|null  $load
     */
    private function formatLoad(?array $load): string
    {
        if ($load === null) {
            return __('telegram.dashboard_na');
        }

        $a = $load[1] ?? null;
        $b = $load[5] ?? null;
        $c = $load[15] ?? null;
        if ($a === null && $b === null && $c === null) {
            return __('telegram.dashboard_na');
        }

        return sprintf(
            '%s, %s, %s',
            $a !== null ? number_format($a, 2, '.', '') : '—',
            $b !== null ? number_format($b, 2, '.', '') : '—',
            $c !== null ? number_format($c, 2, '.', '') : '—'
        );
    }

    private function formatPercent(mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('telegram.dashboard_na');
        }

        return number_format((float) $value, 1, '.', '').'%';
    }

    private function formatBytes(mixed $bytes): string
    {
        if ($bytes === null || $bytes === '') {
            return __('telegram.dashboard_na');
        }

        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        $precision = $i === 0 ? 0 : 2;

        return number_format($bytes, $precision, '.', '').$units[$i];
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
