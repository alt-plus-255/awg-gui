<?php

namespace App\Services\System;

use App\Services\Docker\PanelOpsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProjectUpdateService
{
    private const LOG_TAIL_LINES = 80;

    private const GITHUB_REPO_DEFAULT = 'alt-plus-255/awg-gui';
    public function __construct(
        private PanelOpsClient $panelOps,
        private ?string $hostGuiDir = null,
        private ?string $hostComposeDir = null,
    ) {
        $this->hostGuiDir = rtrim($hostGuiDir ?: (string) env('HOST_AWG_GUI_DIR', '/host-awg-gui'), '/');
        $this->hostComposeDir = rtrim($hostComposeDir ?: (string) env('HOST_COMPOSE_DIR', '/compose'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(bool $checkRelease = false): array
    {
        $install = $this->readKeyValueFile($this->hostGuiDir.'/install.state');
        $update = $this->readJsonFile($this->hostGuiDir.'/update.state');

        $currentVersion = $this->detectCurrentVersion($install);
        $running = $this->isRunningState($update);

        $payload = [
            'current_version' => $currentVersion['version'],
            'version_source' => $currentVersion['source'],
            'installed_at' => $install['completed_at'] ?? null,
            'can_update' => $this->canUpdate($install),
            'status' => $running ? 'running' : $this->normalizeStatus($update['status'] ?? null),
            'running' => $running,
            'target_version' => $update['target_version'] ?? null,
            'started_at' => $update['started_at'] ?? null,
            'finished_at' => $update['finished_at'] ?? null,
            'message' => $this->localizedStatusMessage($update, $running),
            'log_tail' => $this->readLogTail(),
            'latest_version' => null,
            'update_available' => false,
            'release_checked_at' => null,
            'release_check_error' => null,
        ];

        if ($checkRelease) {
            $payload = array_merge($payload, $this->fetchLatestRelease($currentVersion['version']));
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function checkForUpdates(): array
    {
        return $this->status(checkRelease: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function start(?string $targetVersion = null): array
    {
        $status = $this->status(checkRelease: true);
        if (! ($status['can_update'] ?? false)) {
            throw new RuntimeException('update_not_available');
        }

        if ($status['running'] ?? false) {
            throw new RuntimeException('update_already_running');
        }

        $resolvedTarget = $targetVersion !== null && trim($targetVersion) !== ''
            ? ltrim(trim($targetVersion), 'v')
            : ($status['latest_version'] ?? null);

        if (! $this->isNewerVersion($resolvedTarget, $status['current_version'] ?? null)) {
            throw new RuntimeException('update_not_available');
        }

        $this->panelOps->startUpdate($resolvedTarget);

        return array_merge($this->status(), [
            'status' => 'running',
            'running' => true,
            'target_version' => $resolvedTarget,
            'message' => __('settings.update_message_started'),
            'log_tail' => $this->readLogTail(),
        ]);
    }

    /**
     * @param  array<string, string>  $install
     */
    private function canUpdate(array $install): bool
    {
        $bundleVersion = trim((string) ($install['bundle_version'] ?? ''));

        return is_dir($this->hostGuiDir)
            && $bundleVersion !== 'source'
            && trim((string) env('PANEL_OPS_TOKEN', '')) !== '';
    }

    /**
     * @param  array<string, string>  $install
     * @return array{version:?string, source:string}
     */
    private function detectCurrentVersion(array $install): array
    {
        $bundleVersion = trim((string) ($install['bundle_version'] ?? ''));
        if ($bundleVersion !== '' && $bundleVersion !== 'unknown') {
            return ['version' => $bundleVersion, 'source' => 'install_state'];
        }

        $compose = $this->hostComposeDir.'/docker-compose.yml';
        if (is_readable($compose)) {
            $raw = file_get_contents($compose);
            if ($raw !== false && preg_match('/image:\s*awggui-app:([^\s]+)/', $raw, $m)) {
                return ['version' => trim($m[1]), 'source' => 'compose'];
            }
        }

        return ['version' => null, 'source' => 'unknown'];
    }

    /**
     * @return array<string, string>
     */
    private function readKeyValueFile(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        $data = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $data[$key] = trim($value);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function isRunningState(array $update): bool
    {
        return ($update['status'] ?? null) === 'running';
    }

    private function normalizeStatus(mixed $status): string
    {
        $value = trim((string) $status);
        if (in_array($value, ['running', 'success', 'failed'], true)) {
            return $value;
        }

        return 'idle';
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function localizedStatusMessage(array $update, bool $running): string
    {
        if ($running) {
            $custom = trim((string) ($update['message'] ?? ''));
            if ($custom !== '') {
                return $custom;
            }

            return __('settings.update_message_running');
        }

        $custom = trim((string) ($update['message'] ?? ''));
        if ($custom !== '' && $this->normalizeStatus($update['status'] ?? null) === 'failed') {
            return $custom;
        }

        return match ($this->normalizeStatus($update['status'] ?? null)) {
            'success' => __('settings.update_message_success'),
            'failed' => __('settings.update_message_failed'),
            default => $custom !== '' ? $custom : __('settings.update_message_idle'),
        };
    }

    /**
     * @return array{latest_version:?string, update_available:bool, release_checked_at:string, release_check_error:?string}
     */
    private function fetchLatestRelease(?string $currentVersion): array
    {
        $checkedAt = now()->toIso8601String();
        $repo = trim((string) env('AWG_GUI_GITHUB_REPO', self::GITHUB_REPO_DEFAULT));
        $url = "https://api.github.com/repos/{$repo}/releases/latest";

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'awg-gui-panel',
                ])
                ->get($url);

            if (! $response->successful()) {
                return [
                    'latest_version' => null,
                    'update_available' => false,
                    'release_checked_at' => $checkedAt,
                    'release_check_error' => __('settings.update_release_fetch_failed'),
                ];
            }

            $tag = trim((string) ($response->json('tag_name') ?? ''));
            $latest = ltrim($tag, 'v');

            return [
                'latest_version' => $latest !== '' ? $latest : null,
                'update_available' => $this->isNewerVersion($latest, $currentVersion),
                'release_checked_at' => $checkedAt,
                'release_check_error' => null,
            ];
        } catch (\Throwable) {
            return [
                'latest_version' => null,
                'update_available' => false,
                'release_checked_at' => $checkedAt,
                'release_check_error' => __('settings.update_release_fetch_failed'),
            ];
        }
    }

    private function isNewerVersion(?string $latest, ?string $current): bool
    {
        $latest = ltrim(trim((string) $latest), 'v');
        if ($latest === '') {
            return false;
        }

        $current = ltrim(trim((string) $current), 'v');
        if ($current === '' || in_array($current, ['source', 'unknown'], true)) {
            return true;
        }

        if ($latest === $current) {
            return false;
        }

        if (preg_match('/^\d+(\.\d+)*([.-][\w.-]+)?$/', $latest) && preg_match('/^\d+(\.\d+)*([.-][\w.-]+)?$/', $current)) {
            return version_compare($latest, $current, '>');
        }

        return $latest !== $current;
    }

    private function readLogTail(int $maxLines = self::LOG_TAIL_LINES): string
    {
        $path = $this->hostGuiDir.'/update.log';
        if (! is_readable($path)) {
            return '';
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        if ($lines === []) {
            return '';
        }

        if (count($lines) <= $maxLines) {
            return implode("\n", $lines);
        }

        return implode("\n", array_slice($lines, -$maxLines));
    }
}
