<?php

declare(strict_types=1);

/**
 * Minimal internal HTTP API for privileged Docker compose operations.
 * Listens only inside the awggui Docker network.
 */

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' || ! str_starts_with($header, 'Bearer ')) {
        return null;
    }

    $token = trim(substr($header, 7));

    return $token !== '' ? $token : null;
}

function expectedToken(): string
{
    return trim((string) getenv('PANEL_OPS_TOKEN'));
}

function updateStatePath(): string
{
    return getenv('AWG_GUI_UPDATE_STATE_PATH') ?: '/host-awg-gui/update.state';
}

function kernelStatePath(): string
{
    return getenv('AWG_GUI_KERNEL_STATE_PATH') ?: '/host-awg-gui/awg-kernel.state';
}

/**
 * @return array<string, mixed>
 */
function readUpdateState(): array
{
    $path = updateStatePath();
    if (! is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array<string, mixed>
 */
function readKernelStateFile(): array
{
    $path = kernelStatePath();
    if (! is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function isUpdateRunning(array $state): bool
{
    if (($state['status'] ?? null) !== 'running') {
        return false;
    }

    $pid = (int) ($state['pid'] ?? 0);
    if ($pid < 1) {
        return false;
    }

    return is_dir("/proc/{$pid}");
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

    return $started > 0 && (time() - $started) < 1800;
}

function recreateCaddy(): array
{
    $project = getenv('COMPOSE_PROJECT') ?: 'awggui';
    $composeFile = getenv('COMPOSE_FILE') ?: '/compose/docker-compose.yml';
    $envFile = getenv('COMPOSE_ENV_FILE') ?: '/compose/.env';

    if (! is_file($composeFile)) {
        return ['ok' => false, 'error' => "Compose file not found: {$composeFile}"];
    }
    if (! is_file($envFile)) {
        return ['ok' => false, 'error' => "Env file not found: {$envFile}"];
    }

    $cmd = [
        'docker', 'compose',
        '-p', $project,
        '--env-file', $envFile,
        '-f', $composeFile,
        'up', '-d', '--force-recreate', '--no-deps', 'caddy',
    ];

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes);
    if (! is_resource($process)) {
        return ['ok' => false, 'error' => 'Failed to start docker compose'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = time() + 180;

    while (true) {
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        $status = proc_get_status($process);
        if (! $status['running']) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            break;
        }

        if (time() >= $deadline) {
            proc_terminate($process, 15);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return ['ok' => false, 'error' => 'docker compose timed out after 180s'];
        }

        usleep(100_000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $err = trim($stderr !== '' ? $stderr : $stdout);

        return ['ok' => false, 'error' => $err !== '' ? $err : "docker compose exited with code {$exitCode}"];
    }

    return ['ok' => true];
}

function startUpdate(?string $version): array
{
    $current = readUpdateState();
    if (isUpdateRunning($current)) {
        return ['ok' => false, 'error' => 'Update is already running.', 'status' => 409];
    }

    $env = $_ENV;
    $env['AWG_GUI_UPDATE_STATE_PATH'] = updateStatePath();
    $env['AWG_GUI_UPDATE_LOG_PATH'] = getenv('AWG_GUI_UPDATE_LOG_PATH') ?: '/host-awg-gui/update.log';
    $env['AWG_GUI_GITHUB_REPO'] = getenv('AWG_GUI_GITHUB_REPO') ?: 'alt-plus-255/awg-gui';
    $env['AWG_GUI_UPDATE_VERSION'] = $version !== null ? ltrim($version, 'v') : '';

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $command = sprintf('%s /app/update-runner.php >/dev/null 2>&1 & echo $!', escapeshellarg(PHP_BINARY));
    $process = proc_open(['/bin/sh', '-lc', $command], $descriptors, $pipes, '/app', $env);
    if (! is_resource($process)) {
        return ['ok' => false, 'error' => 'Failed to start update runner.', 'status' => 500];
    }

    $pid = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [
        'ok' => true,
        'status' => 202,
        'pid' => ctype_digit($pid) ? (int) $pid : null,
        'message' => $version
            ? 'Update has started for the requested version.'
            : 'Update has started.',
    ];
}

/**
 * Truncate update.log (root-owned on host; app www-data cannot write it).
 *
 * @return array<string, mixed>
 */
function clearUpdateLog(): array
{
    $path = getenv('AWG_GUI_UPDATE_LOG_PATH') ?: '/host-awg-gui/update.log';
    $dir = dirname($path);
    if (! is_dir($dir)) {
        return ['ok' => false, 'error' => 'Host GUI directory is missing.', 'status' => 500];
    }

    if (@file_put_contents($path, '') === false) {
        return ['ok' => false, 'error' => 'Failed to clear update log.', 'status' => 500];
    }
    @chmod($path, 0666);

    return ['ok' => true, 'status' => 200];
}

/**
 * Sync host status via fixed nsenter → awg-kernel-host.sh status.
 *
 * @return array<string, mixed>
 */
function awgKernelStatus(): array
{
    $script = '/etc/awg-gui/awg-kernel-host.sh';
    $hostCmd = escapeshellarg($script).' status';
    $helper = sprintf(
        'set -eu; apk add --no-cache util-linux >/dev/null; '.
        'nsenter -t 1 -m -u -i -n -p -- /bin/bash -lc %s',
        escapeshellarg($hostCmd)
    );
    $cmd = [
        'docker', 'run', '--rm', '--privileged', '--pid=host', '--network', 'host',
        'alpine:3.20', 'sh', '-lc', $helper,
    ];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (! is_resource($process)) {
        return ['ok' => false, 'error' => 'Failed to probe kernel status', 'status' => 500];
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $host = json_decode(trim($stdout), true);
    $opState = readKernelStateFile();
    $running = isKernelOpRunning($opState);

    if ($exit !== 0 || ! is_array($host)) {
        return [
            'ok' => true,
            'status' => 200,
            'module_loaded' => false,
            'package_installed' => false,
            'awg_datapath' => 'unknown',
            'os_family' => 'unknown',
            'detail' => trim($stderr !== '' ? $stderr : $stdout),
            'script_present' => false,
            'op' => [
                'status' => $running ? 'running' : (string) ($opState['status'] ?? 'idle'),
                'message' => (string) ($opState['message'] ?? ''),
                'op' => (string) ($opState['op'] ?? ''),
                'started_at' => $opState['started_at'] ?? null,
                'finished_at' => $opState['finished_at'] ?? null,
                'running' => $running,
            ],
        ];
    }

    return [
        'ok' => true,
        'status' => 200,
        'module_loaded' => (bool) ($host['module_loaded'] ?? false),
        'package_installed' => (bool) ($host['package_installed'] ?? false),
        'awg_datapath' => (string) ($host['awg_datapath'] ?? 'unknown'),
        'os_family' => (string) ($host['os_family'] ?? 'unknown'),
        'detail' => (string) ($host['detail'] ?? ''),
        'script_present' => true,
        'op' => [
            'status' => $running ? 'running' : (string) ($opState['status'] ?? 'idle'),
            'message' => (string) ($opState['message'] ?? ''),
            'op' => (string) ($opState['op'] ?? ''),
            'started_at' => $opState['started_at'] ?? null,
            'finished_at' => $opState['finished_at'] ?? null,
            'running' => $running,
        ],
    ];
}

/**
 * @param  'install'|'uninstall'  $op
 * @return array<string, mixed>
 */
function startAwgKernelOp(string $op): array
{
    if (! in_array($op, ['install', 'uninstall'], true)) {
        return ['ok' => false, 'error' => 'Invalid op', 'status' => 400];
    }

    $current = readKernelStateFile();
    if (isKernelOpRunning($current)) {
        return ['ok' => false, 'error' => 'Kernel module operation is already running.', 'status' => 409];
    }

    if (! is_file('/host-awg-gui/awg-kernel-host.sh') && ! is_file('/etc/awg-gui/awg-kernel-host.sh')) {
        // Host path is via nsenter; panel-ops sees /host-awg-gui mount.
        // Script must exist on host at /etc/awg-gui/awg-kernel-host.sh (= /host-awg-gui/...).
    }
    if (! is_file('/host-awg-gui/awg-kernel-host.sh')) {
        return [
            'ok' => false,
            'error' => 'Host script /etc/awg-gui/awg-kernel-host.sh is missing. Re-run the awg-gui installer.',
            'status' => 503,
        ];
    }

    $env = $_ENV;
    $env['AWG_GUI_KERNEL_STATE_PATH'] = kernelStatePath();
    $env['AWG_GUI_KERNEL_LOG_PATH'] = getenv('AWG_GUI_KERNEL_LOG_PATH') ?: '/host-awg-gui/awg-kernel.log';
    $env['AWG_GUI_KERNEL_OP'] = $op;

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $command = sprintf('%s /app/awg-kernel-runner.php >/dev/null 2>&1 & echo $!', escapeshellarg(PHP_BINARY));
    $process = proc_open(['/bin/sh', '-lc', $command], $descriptors, $pipes, '/app', $env);
    if (! is_resource($process)) {
        return ['ok' => false, 'error' => 'Failed to start kernel runner.', 'status' => 500];
    }

    $pid = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [
        'ok' => true,
        'status' => 202,
        'pid' => ctype_digit($pid) ? (int) $pid : null,
        'message' => $op === 'install'
            ? 'Kernel module install has started.'
            : 'Kernel module uninstall has started.',
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/health') {
    respond(200, ['ok' => true]);
}

$allowedGet = ['/ops/awg-kernel/status'];
$allowedPost = [
    '/ops/caddy/recreate',
    '/ops/update/start',
    '/ops/update/clear-log',
    '/ops/awg-kernel/install',
    '/ops/awg-kernel/uninstall',
];

if ($method === 'GET' && in_array($path, $allowedGet, true)) {
    $expected = expectedToken();
    if ($expected === '') {
        respond(503, ['ok' => false, 'error' => 'PANEL_OPS_TOKEN is not configured']);
    }
    $provided = bearerToken();
    if ($provided === null || ! hash_equals($expected, $provided)) {
        respond(401, ['ok' => false, 'error' => 'Unauthorized']);
    }
    $result = awgKernelStatus();
    respond((int) ($result['status'] ?? 200), $result);
}

if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

if (! in_array($path, $allowedPost, true)) {
    respond(404, ['ok' => false, 'error' => 'Not found']);
}

$expected = expectedToken();
if ($expected === '') {
    respond(503, ['ok' => false, 'error' => 'PANEL_OPS_TOKEN is not configured']);
}

$provided = bearerToken();
if ($provided === null || ! hash_equals($expected, $provided)) {
    respond(401, ['ok' => false, 'error' => 'Unauthorized']);
}

if ($path === '/ops/update/start') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $version = is_array($payload) ? trim((string) ($payload['version'] ?? '')) : '';
    $result = startUpdate($version !== '' ? $version : null);
    respond((int) ($result['status'] ?? 202), $result);
}

if ($path === '/ops/update/clear-log') {
    $result = clearUpdateLog();
    respond((int) ($result['status'] ?? 200), $result);
}

if ($path === '/ops/awg-kernel/install') {
    // Body intentionally ignored (no user argv).
    $result = startAwgKernelOp('install');
    respond((int) ($result['status'] ?? 202), $result);
}

if ($path === '/ops/awg-kernel/uninstall') {
    $result = startAwgKernelOp('uninstall');
    respond((int) ($result['status'] ?? 202), $result);
}

$result = recreateCaddy();
respond($result['ok'] ? 200 : 500, $result);
