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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_SERVER['REQUEST_URI'] ?? '/') === '/health') {
    respond(200, ['ok' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (! in_array($path, ['/ops/caddy/recreate', '/ops/update/start'], true)) {
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

$result = recreateCaddy();
respond($result['ok'] ? 200 : 500, $result);
