<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class HostMetricsService
{
    private const CPU_CACHE_KEY = 'host_metrics.cpu_snapshot';

    private const PROC_CPU_CACHE_KEY = 'host_metrics.proc_cpu_snapshot';

    private const DISK_PATHS = ['/compose', '/host-awg-gui', '/'];

    private const PROC_ROOTS = ['/host/proc', '/proc'];

    /**
     * @return array{
     *     cpu: array{percent: float|null},
     *     memory: array{
     *         used: int|null,
     *         total: int|null,
     *         percent: float|null,
     *         top_containers: list<array{name: string, used: int, percent: float|null}>,
     *         top_processes: list<array{pid: int, command: string, used: int, percent: float|null}>
     *     },
     *     disk: array{used: int|null, total: int|null, percent: float|null}
     * }
     */
    public function collect(): array
    {
        return [
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disk' => $this->disk(),
        ];
    }

    /**
     * @return array{processes: list<array<string, mixed>>, containers: list<array<string, mixed>>}
     */
    public function processMonitor(string $sort = 'cpu', int $limit = 40): array
    {
        $sort = $sort === 'mem' ? 'mem' : 'cpu';
        $limit = max(1, min(100, $limit));

        $processes = $this->hostProcesses($limit, $sort);
        $containers = $this->dockerStats();

        usort($containers, function (array $a, array $b) use ($sort) {
            if ($sort === 'mem') {
                return ($b['used'] ?? 0) <=> ($a['used'] ?? 0);
            }

            return ($b['cpu_percent'] ?? 0) <=> ($a['cpu_percent'] ?? 0);
        });

        return [
            'processes' => $processes,
            'containers' => array_slice($containers, 0, 20),
        ];
    }

    /**
     * @return array{percent: float|null}
     */
    private function cpu(): array
    {
        $current = $this->readCpuSnapshot();
        if ($current === null) {
            return ['percent' => null];
        }

        $previous = Cache::get(self::CPU_CACHE_KEY);
        Cache::put(self::CPU_CACHE_KEY, $current, now()->addMinutes(5));

        if (! is_array($previous) || ! isset($previous['total'], $previous['idle'])) {
            usleep(120000);
            $second = $this->readCpuSnapshot();
            if ($second === null) {
                return ['percent' => null];
            }
            Cache::put(self::CPU_CACHE_KEY, $second, now()->addMinutes(5));
            $previous = $current;
            $current = $second;
        }

        $totalDelta = $current['total'] - $previous['total'];
        $idleDelta = $current['idle'] - $previous['idle'];

        if ($totalDelta <= 0) {
            return ['percent' => 0.0];
        }

        $usage = (1 - ($idleDelta / $totalDelta)) * 100;

        return ['percent' => round(max(0, min(100, $usage)), 1)];
    }

    /**
     * @return array{total: float, idle: float}|null
     */
    private function readCpuSnapshot(): ?array
    {
        $stat = $this->readProcFile('stat');
        if ($stat === null) {
            return null;
        }

        foreach (explode("\n", $stat) as $line) {
            if (! str_starts_with($line, 'cpu ')) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 5) {
                return null;
            }

            $values = array_map('floatval', array_slice($parts, 1));
            $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
            $total = array_sum($values);

            return ['total' => $total, 'idle' => $idle];
        }

        return null;
    }

    /**
     * @return array{used: int|null, total: int|null, percent: float|null}
     */
    private function memory(): array
    {
        $meminfo = $this->readProcFile('meminfo');
        if ($meminfo === null) {
            return ['used' => null, 'total' => null, 'percent' => null];
        }

        $totalKb = null;
        $availableKb = null;

        foreach (explode("\n", $meminfo) as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m)) {
                $totalKb = (int) $m[1];
            } elseif (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) {
                $availableKb = (int) $m[1];
            }

            if ($totalKb !== null && $availableKb !== null) {
                break;
            }
        }

        if (! $totalKb || $availableKb === null) {
            return ['used' => null, 'total' => null, 'percent' => null];
        }

        $total = $totalKb * 1024;
        $used = max(0, ($totalKb - $availableKb) * 1024);
        $percent = round(($used / $total) * 100, 1);

        return [
            'used' => $used,
            'total' => $total,
            'percent' => $percent,
        ];
    }

    /**
     * @return array{used: int|null, total: int|null, percent: float|null}
     */
    private function disk(): array
    {
        $path = $this->resolveDiskPath();
        if ($path === null) {
            return ['used' => null, 'total' => null, 'percent' => null];
        }

        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return ['used' => null, 'total' => null, 'percent' => null];
        }

        $used = max(0, (int) $total - (int) $free);
        $percent = round(($used / $total) * 100, 1);

        return [
            'used' => $used,
            'total' => (int) $total,
            'percent' => $percent,
        ];
    }

    private function resolveDiskPath(): ?string
    {
        foreach (self::DISK_PATHS as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    private function procRoot(): ?string
    {
        foreach (self::PROC_ROOTS as $root) {
            if (is_dir($root) && is_readable($root.'/stat')) {
                return $root;
            }
        }

        return null;
    }

    private function readProcFile(string $relative): ?string
    {
        $root = $this->procRoot();
        if ($root === null) {
            return null;
        }

        $content = @file_get_contents($root.'/'.$relative);

        return $content === false ? null : $content;
    }

    /**
     * @return list<array{name: string, used: int, mem_percent: float|null, cpu_percent: float|null}>
     */
    public function dockerStats(): array
    {
        $result = Process::timeout(8)->run([
            'docker', 'stats', '--no-stream', '--format', '{{json .}}',
        ]);

        if (! $result->successful()) {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($result->output())) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if (! is_array($data)) {
                continue;
            }

            $name = (string) ($data['Name'] ?? $data['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $memUsage = (string) ($data['MemUsage'] ?? $data['mem_usage'] ?? '');
            $used = $this->parseDockerMemUsage($memUsage);
            $memPercent = $this->parsePercent($data['MemPerc'] ?? $data['mem_perc'] ?? null);
            $cpuPercent = $this->parsePercent($data['CPUPerc'] ?? $data['cpu_perc'] ?? null);

            $rows[] = [
                'name' => $name,
                'used' => $used,
                'mem_percent' => $memPercent,
                'cpu_percent' => $cpuPercent,
            ];
        }

        return $rows;
    }

    private function parsePercent(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 1);
        }

        if (preg_match('/([\d.]+)/', (string) $value, $m)) {
            return round((float) $m[1], 1);
        }

        return null;
    }

    private function parseDockerMemUsage(string $memUsage): int
    {
        // e.g. "123.4MiB / 1.918GiB"
        $left = trim(explode('/', $memUsage, 2)[0] ?? '');

        return $this->parseSizeToBytes($left);
    }

    private function parseSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '--') {
            return 0;
        }

        if (! preg_match('/^([\d.]+)\s*([KMGTPE]?i?B?)$/i', $value, $m)) {
            return 0;
        }

        $num = (float) $m[1];
        $unit = strtoupper($m[2] ?: 'B');

        $factors = [
            'B' => 1,
            'KB' => 1000,
            'MB' => 1000 ** 2,
            'GB' => 1000 ** 3,
            'TB' => 1000 ** 4,
            'KIB' => 1024,
            'MIB' => 1024 ** 2,
            'GIB' => 1024 ** 3,
            'TIB' => 1024 ** 4,
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            'T' => 1024 ** 4,
        ];

        $factor = $factors[$unit] ?? 1;

        return (int) round($num * $factor);
    }

    /**
     * @return list<array{pid: int, command: string, used: int, mem_percent: float|null, cpu_percent: float|null}>
     */
    public function hostProcesses(int $limit = 40, string $sort = 'cpu', bool $allowResample = true): array
    {
        $root = $this->procRoot();
        if ($root === null) {
            return [];
        }

        $memTotal = $this->memory()['total'] ?? null;
        $cpuSnap = $this->readCpuSnapshot();
        $systemTotal = $cpuSnap['total'] ?? null;

        $previous = Cache::get(self::PROC_CPU_CACHE_KEY);
        $prevSystemTotal = is_array($previous) ? ($previous['system_total'] ?? null) : null;
        $prevProcs = is_array($previous) ? ($previous['procs'] ?? []) : [];

        $currentProcs = [];
        $rows = [];

        foreach (scandir($root) ?: [] as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $pid = (int) $entry;
            $statPath = $root.'/'.$entry.'/stat';
            $stat = @file_get_contents($statPath);
            if ($stat === false) {
                continue;
            }

            if (! preg_match('/^(\d+)\s+\((.*)\)\s+(\S+)\s+(.*)$/s', $stat, $m)) {
                continue;
            }

            $rest = preg_split('/\s+/', trim($m[4])) ?: [];
            if (count($rest) < 13) {
                continue;
            }

            $utime = (float) $rest[11];
            $stime = (float) $rest[12];
            $jiffies = $utime + $stime;
            $currentProcs[$pid] = $jiffies;

            $rssKb = $this->readVmRss($root.'/'.$entry.'/status');
            $used = $rssKb * 1024;
            $memPercent = ($memTotal && $memTotal > 0)
                ? round(($used / $memTotal) * 100, 1)
                : null;

            $cpuPercent = 0.0;
            if (
                $systemTotal !== null
                && $prevSystemTotal !== null
                && isset($prevProcs[$pid])
            ) {
                $sysDelta = $systemTotal - $prevSystemTotal;
                $procDelta = $jiffies - (float) $prevProcs[$pid];
                if ($sysDelta > 0 && $procDelta >= 0) {
                    $cpuPercent = round(min(100 * $this->nproc(), max(0, ($procDelta / $sysDelta) * 100)), 1);
                }
            }

            $command = $this->readCmdline($root.'/'.$entry.'/cmdline', $m[2]);

            $rows[] = [
                'pid' => $pid,
                'command' => $command,
                'used' => $used,
                'mem_percent' => $memPercent,
                'cpu_percent' => $cpuPercent,
            ];
        }

        if ($systemTotal !== null) {
            Cache::put(self::PROC_CPU_CACHE_KEY, [
                'system_total' => $systemTotal,
                'procs' => $currentProcs,
            ], now()->addMinutes(5));
        }

        if ($allowResample && empty($prevProcs) && $sort === 'cpu') {
            usleep(120000);

            return $this->hostProcesses($limit, $sort, false);
        }

        usort($rows, function (array $a, array $b) use ($sort) {
            if ($sort === 'mem') {
                return ($b['used'] ?? 0) <=> ($a['used'] ?? 0);
            }

            $cmp = ($b['cpu_percent'] ?? 0) <=> ($a['cpu_percent'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($b['used'] ?? 0) <=> ($a['used'] ?? 0);
        });

        return array_slice($rows, 0, $limit);
    }

    private function nproc(): int
    {
        $root = $this->procRoot();
        if ($root) {
            $stat = @file_get_contents($root.'/stat');
            if (is_string($stat)) {
                $count = preg_match_all('/^cpu\d+/m', $stat);
                if ($count > 0) {
                    return $count;
                }
            }
        }

        return 1;
    }

    private function readVmRss(string $statusPath): int
    {
        $status = @file_get_contents($statusPath);
        if ($status === false) {
            return 0;
        }

        if (preg_match('/^VmRSS:\s+(\d+)/m', $status, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function readCmdline(string $cmdlinePath, string $commFallback): string
    {
        $raw = @file_get_contents($cmdlinePath);
        if (is_string($raw) && $raw !== '') {
            $cmd = trim(str_replace("\0", ' ', $raw));
            if ($cmd !== '') {
                return mb_substr($cmd, 0, 200);
            }
        }

        return $commFallback !== '' ? $commFallback : '?';
    }
}
