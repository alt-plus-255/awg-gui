<?php

namespace App\Services\AmneziaWg;

use App\Services\Docker\DockerRuntime;

class CertbotProcessTracker
{
    public function __construct(private DockerRuntime $docker) {}

    public function containerName(): string
    {
        return (string) env('CERTBOT_CONTAINER', 'awggui-certbot');
    }

    public function challengeDir(): string
    {
        $root = $_ENV['HOST_AWG_GUI_DIR'] ?? getenv('HOST_AWG_GUI_DIR') ?: '/host-awg-gui';

        return rtrim((string) $root, '/').'/certbot/challenge';
    }

    public function isRunning(): bool
    {
        if (! $this->docker->containerRunning($this->containerName())) {
            return false;
        }

        $result = $this->docker->exec(
            $this->containerName(),
            ['sh', '-c', 'pgrep -x certbot >/dev/null 2>&1'],
            timeout: 10,
        );

        return $result->successful();
    }

    public function exitCode(): int
    {
        $path = $this->challengeDir().'/exit_code';
        if (! is_readable($path)) {
            return 1;
        }

        $raw = trim((string) file_get_contents($path));

        return $raw === '' ? 1 : (int) $raw;
    }

    public function logs(int $tail = 80): string
    {
        $result = $this->docker->logs($this->containerName(), $tail, timeout: 15);
        $out = trim($result->output()."\n".$result->errorOutput());

        return mb_substr($out, 0, 2000);
    }

    public function stopProcess(): void
    {
        if (! $this->docker->containerRunning($this->containerName())) {
            return;
        }

        $this->docker->exec(
            $this->containerName(),
            ['sh', '-c', 'pkill -x certbot >/dev/null 2>&1 || true'],
            timeout: 30,
        );
    }

    public function clearExitCode(): void
    {
        $path = $this->challengeDir().'/exit_code';
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
