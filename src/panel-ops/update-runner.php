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

    // Host install must outlive panel-ops recreate (compose up restarts this container).
    // setsid + log redirect detach from the alpine helper / panel-ops lifetime.
    // bundle-install.sh finalizes /etc/awg-gui/update.state when the upgrade finishes.
    $versionExport = '';
    if ($version !== null && $version !== '') {
        $versionExport = sprintf('export AWG_GUI_VERSION=%s; ', escapeshellarg($version));
    }

    $hostJob = $versionExport.sprintf(
        'curl -fsSL %s | /bin/bash -s -- --yes >>/etc/awg-gui/update.log 2>&1',
        escapeshellarg($installUrl)
    );

    $helper = sprintf(
        'set -eu; apk add --no-cache util-linux curl >/dev/null; '.
        'nsenter -t 1 -m -u -i -n -p -- setsid -f /bin/bash -c %s </dev/null >/dev/null 2>&1',
        escapeshellarg($hostJob)
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

try {
    $process = proc_open($command, $descriptors, $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start update helper container.');
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Update helper container exited with code {$exitCode}.");
    }
} catch (Throwable $e) {
    @file_put_contents($logPath, '['.isoNow().'] '.$e->getMessage().PHP_EOL, FILE_APPEND);
    $state['status'] = 'failed';
    $state['finished_at'] = isoNow();
    $state['message'] = $e->getMessage();
    writeRunnerState($state);
    exit(1);
}

// Host install continues detached. Keep status=running; installer writes the final state.
@file_put_contents(
    $logPath,
    '['.isoNow()."] host update job started (detached); waiting for installer to finish\n",
    FILE_APPEND
);

exit(0);
