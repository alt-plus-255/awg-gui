<?php

declare(strict_types=1);

function runnerStatePath(): string
{
    return getenv('AWG_GUI_UPDATE_STATE_PATH') ?: '/host-awg-gui/update.state';
}

function runnerLogPath(): string
{
    return getenv('AWG_GUI_UPDATE_LOG_PATH') ?: '/host-awg-gui/update.log';
}

function isoNow(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * @param  array<string, mixed>  $state
 */
function writeRunnerState(array $state): void
{
    @file_put_contents(
        runnerStatePath(),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
    );
}

/**
 * @return list<string>
 */
function buildUpdateCommand(?string $version): array
{
    $repo = getenv('AWG_GUI_GITHUB_REPO') ?: 'alt-plus-255/awg-gui';
    $installUrl = sprintf('https://raw.githubusercontent.com/%s/refs/heads/main/dist/install.sh', $repo);

    // nsenter -m switches to the host mount namespace; /host from a container bind is not visible there.
    $hostShell = '/bin/bash -s -- --yes';
    if ($version !== null && $version !== '') {
        $hostShell = sprintf(
            '/usr/bin/env AWG_GUI_VERSION=%s /bin/bash -s -- --yes',
            escapeshellarg($version)
        );
    }

    $helper = sprintf(
        'set -eu; apk add --no-cache util-linux curl >/dev/null; curl -fsSL %s | nsenter -t 1 -m -u -i -n -p -- %s',
        escapeshellarg($installUrl),
        $hostShell
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

/**
 * Best-effort host /tmp + helper image cleanup after a successful update.
 * Bundle install also drops unused awggui:* tags; this covers leftovers and alpine:3.20.
 */
function cleanupAfterUpdate(string $logPath): void
{
    $tmpClean = implode(' ', [
        'find /host-tmp -maxdepth 1 -type d',
        '\\( -name \'awg-gui-install.*\' -o -name \'awg-gui-extract.*\' \\)',
        '-exec rm -rf {} + 2>/dev/null || true;',
        'find /host-tmp -maxdepth 1 -type f',
        '\\( -name \'awg-gui-install.sh\' -o -name \'awg-gui-ensure-docker.*\'',
        '-o -name \'awg-gui*.log\' -o -name \'awg-gui-*.log\' \\)',
        '-delete 2>/dev/null || true',
    ]);

    $command = [
        'docker',
        'run',
        '--rm',
        '-v',
        '/tmp:/host-tmp',
        'alpine:3.20',
        'sh',
        '-lc',
        $tmpClean,
    ];

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];

    $process = @proc_open($command, $descriptors, $pipes);
    if (is_resource($process)) {
        proc_close($process);
    }

    // Drop unused previous project tags if an older bundle skipped that step.
    $images = [];
    @exec("docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null", $images);
    foreach ($images as $image) {
        $image = trim($image);
        if ($image === '' || ! str_starts_with($image, 'awggui-') || str_contains($image, ':<none>')) {
            continue;
        }
        // Without -f, in-use images are kept.
        @exec('docker rmi '.escapeshellarg($image).' >/dev/null 2>&1');
    }

    @exec('docker image prune -f >/dev/null 2>&1');
    @exec('docker rmi alpine:3.20 >/dev/null 2>&1');
}

$targetVersion = trim((string) getenv('AWG_GUI_UPDATE_VERSION'));
$targetVersion = $targetVersion !== '' ? ltrim($targetVersion, 'v') : null;

$state = [
    'pid' => getmypid(),
    'status' => 'running',
    'target_version' => $targetVersion,
    'started_at' => isoNow(),
    'finished_at' => null,
    'message' => $targetVersion
        ? "Updating to {$targetVersion}..."
        : 'Updating to the latest release...',
];

writeRunnerState($state);

$logPath = runnerLogPath();
@file_put_contents($logPath, '['.isoNow()."] update started\n");

$command = buildUpdateCommand($targetVersion);
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $logPath, 'a'],
    2 => ['file', $logPath, 'a'],
];

$exitCode = 1;
try {
    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start update helper container.');
    }

    $exitCode = proc_close($process);
} catch (Throwable $e) {
    @file_put_contents($logPath, '['.isoNow().'] '.$e->getMessage().PHP_EOL, FILE_APPEND);
    $state['status'] = 'failed';
    $state['finished_at'] = isoNow();
    $state['message'] = $e->getMessage();
    writeRunnerState($state);
    exit(1);
}

if ($exitCode === 0) {
    @file_put_contents($logPath, '['.isoNow()."] cleaning temporary update artifacts\n", FILE_APPEND);
    cleanupAfterUpdate($logPath);
}

$state['status'] = $exitCode === 0 ? 'success' : 'failed';
$state['finished_at'] = isoNow();
$state['message'] = $exitCode === 0
    ? 'Update completed successfully.'
    : "Update failed with exit code {$exitCode}.";
writeRunnerState($state);

exit($exitCode);
