<?php

declare(strict_types=1);

/**
 * Runs fixed host ops for AmneziaWG kernel module via privileged nsenter.
 * SECURITY: argv is only status|install|uninstall from this file — never from HTTP body.
 */

function kernelStatePath(): string
{
    return getenv('AWG_GUI_KERNEL_STATE_PATH') ?: '/host-awg-gui/awg-kernel.state';
}

function kernelLogPath(): string
{
    return getenv('AWG_GUI_KERNEL_LOG_PATH') ?: '/host-awg-gui/awg-kernel.log';
}

function kernelHostScript(): string
{
    return '/etc/awg-gui/awg-kernel-host.sh';
}

function isoNowKernel(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * @param  array<string, mixed>  $state
 */
function writeKernelState(array $state): void
{
    @file_put_contents(
        kernelStatePath(),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
    );
}

/**
 * @return array<string, mixed>
 */
function readKernelState(): array
{
    $path = kernelStatePath();
    if (! is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function isKernelOpRunning(array $state): bool
{
    if (($state['status'] ?? null) !== 'running') {
        return false;
    }
    $pid = (int) ($state['pid'] ?? 0);
    if ($pid > 0 && is_dir("/proc/{$pid}")) {
        return true;
    }
    $started = strtotime((string) ($state['started_at'] ?? '')) ?: 0;
    // Stale running older than 30 minutes → treat as not running.
    if ($started > 0 && (time() - $started) < 1800) {
        return true;
    }

    return false;
}

/**
 * @return list<string>
 */
function buildKernelNsenterCommand(string $op): array
{
    // Fixed ops only.
    if (! in_array($op, ['status', 'install', 'uninstall'], true)) {
        throw new InvalidArgumentException('Invalid kernel op');
    }

    $script = kernelHostScript();
    $hostCmd = escapeshellarg($script).' '.escapeshellarg($op);

    $helper = sprintf(
        'set -eu; apk add --no-cache util-linux >/dev/null; '.
        'nsenter -t 1 -m -u -i -n -p -- /bin/bash -lc %s',
        escapeshellarg($hostCmd)
    );

    return [
        'docker',
        'run',
        '--rm',
        '--privileged',
        '--pid=host',
        '--network',
        'host',
        'alpine:3.20',
        'sh',
        '-lc',
        $helper,
    ];
}

$op = trim((string) getenv('AWG_GUI_KERNEL_OP'));
if (! in_array($op, ['install', 'uninstall'], true)) {
    fwrite(STDERR, "AWG_GUI_KERNEL_OP must be install or uninstall\n");
    exit(2);
}

$state = [
    'pid' => getmypid(),
    'status' => 'running',
    'op' => $op,
    'started_at' => isoNowKernel(),
    'finished_at' => null,
    'message' => $op === 'install'
        ? 'Installing AmneziaWG kernel module...'
        : 'Removing AmneziaWG kernel module...',
];
writeKernelState($state);

$logPath = kernelLogPath();
@file_put_contents($logPath, '['.isoNowKernel()."] {$op} started\n", FILE_APPEND);

try {
    $command = buildKernelNsenterCommand($op);
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start kernel helper container.');
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Kernel helper exited with code {$exitCode}.");
    }
    // Prefer host script state if it wrote ok/error.
    $hostState = readKernelState();
    if (($hostState['status'] ?? '') === 'ok' || ($hostState['status'] ?? '') === 'error') {
        $hostState['pid'] = $state['pid'];
        $hostState['op'] = $op;
        $hostState['started_at'] = $state['started_at'];
        $hostState['finished_at'] = isoNowKernel();
        writeKernelState($hostState);
    } else {
        $state['status'] = 'ok';
        $state['finished_at'] = isoNowKernel();
        $state['message'] = $op === 'install' ? 'Kernel module install finished.' : 'Kernel module uninstall finished.';
        writeKernelState($state);
    }
} catch (Throwable $e) {
    @file_put_contents($logPath, '['.isoNowKernel().'] '.$e->getMessage().PHP_EOL, FILE_APPEND);
    $state['status'] = 'error';
    $state['finished_at'] = isoNowKernel();
    $state['message'] = $e->getMessage();
    writeKernelState($state);
    exit(1);
}

exit(0);
