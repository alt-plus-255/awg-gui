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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_SERVER['REQUEST_URI'] ?? '/') === '/health') {
    respond(200, ['ok' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($path !== '/ops/caddy/recreate') {
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

$result = recreateCaddy();
respond($result['ok'] ? 200 : 500, $result);
