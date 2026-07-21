<?php

namespace App\Services\Resolver;

use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PingProbeManager
{
    public const IDLE_TIMEOUT_SEC = 600;

    public const LAST_ACTIVITY_CACHE_KEY = 'ping_probe:last_activity';

    public const PENDING_RELOAD_CACHE_KEY = 'ping_probe:pending_reload';

    public function __construct(
        private AmneziaWgService $awg,
        private ClashApiClient $clash,
        private ResolverPaths $paths,
    ) {}

    public function configPath(): string
    {
        return $this->paths->singBoxPingConfigPath();
    }

    public function pingScriptPath(): string
    {
        $configScript = $this->awg->configDir().'/reload-singbox-ping.sh';
        if (is_executable($configScript)) {
            return $configScript;
        }

        return '/usr/local/bin/reload-singbox-ping.sh';
    }

    public function touch(): void
    {
        Cache::put(self::LAST_ACTIVITY_CACHE_KEY, now()->timestamp, self::IDLE_TIMEOUT_SEC + 120);
    }

    public function lastActivityAt(): ?int
    {
        $ts = Cache::get(self::LAST_ACTIVITY_CACHE_KEY);

        return is_numeric($ts) ? (int) $ts : null;
    }

    public function isRunning(): bool
    {
        try {
            $r = Process::timeout(5)->run([
                'docker', 'exec', $this->awg->containerName(),
                'sh', '-c', 'test -f /run/sing-box-ping.pid && kill -0 "$(cat /run/sing-box-ping.pid)" 2>/dev/null',
            ]);

            return $r->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws \RuntimeException
     */
    public function ensureStarted(): void
    {
        if (! is_file($this->configPath())) {
            throw new \RuntimeException(__('resolver.singbox_ping_json_missing'));
        }

        if ($this->isRunning() && $this->clash->waitForProbeApi(3, 150)) {
            $this->touch();

            return;
        }

        $this->runScript('start');
        if (! $this->clash->waitForProbeApi(40, 200)) {
            throw new \RuntimeException(__('resolver.singbox_probe_start_failed'));
        }
        $this->touch();
    }

    public function reloadIfRunning(): void
    {
        if (! $this->isRunning()) {
            return;
        }

        if (Cache::has(SubscriptionPingService::SESSION_ACTIVE_KEY)) {
            Cache::put(self::PENDING_RELOAD_CACHE_KEY, true, 600);

            return;
        }

        $this->runScript('reload');
        $this->clash->waitForProbeApi(25, 200);
    }

    public function applyPendingReload(): void
    {
        if (! Cache::pull(self::PENDING_RELOAD_CACHE_KEY)) {
            return;
        }
        $this->reloadIfRunning();
    }

    public function stop(): void
    {
        if (! $this->isRunning()) {
            return;
        }
        $this->runScript('stop');
    }

    /**
     * @throws \RuntimeException
     */
    public function forceRestart(): void
    {
        $this->stop();
        $this->ensureStarted();
    }

    public function stopIfIdle(): void
    {
        if (! $this->isRunning()) {
            return;
        }

        if (Cache::has(SubscriptionPingService::SESSION_ACTIVE_KEY)) {
            return;
        }

        $last = $this->lastActivityAt();
        if ($last === null) {
            $this->touch();

            return;
        }

        if ((time() - $last) >= self::IDLE_TIMEOUT_SEC) {
            Log::info('sing-box-ping: idle timeout, stopping probe');
            $this->stop();
        }
    }

    private function runScript(string $action): void
    {
        $script = $this->pingScriptPath();
        $inContainer = $this->containerScriptPath($script);

        $result = Process::timeout(30)->run([
            'docker', 'exec', $this->awg->containerName(),
            $inContainer, $action,
        ]);

        if (! $result->successful()) {
            $err = trim($result->errorOutput()."\n".$result->output());
            throw new \RuntimeException($err !== '' ? $err : "sing-box-ping script {$action} failed");
        }
    }

    private function containerScriptPath(string $script): string
    {
        $configDir = rtrim($this->awg->configDir(), '/');
        if ($configDir !== '' && str_starts_with($script, $configDir.'/')) {
            return '/config/'.substr($script, strlen($configDir) + 1);
        }

        if (str_starts_with($script, '/config/')) {
            return $script;
        }

        return '/usr/local/bin/reload-singbox-ping.sh';
    }
}
