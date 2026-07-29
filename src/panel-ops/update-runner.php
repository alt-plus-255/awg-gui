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

$state['status'] = $exitCode === 0 ? 'success' : 'failed';
$state['finished_at'] = isoNow();
$state['message'] = $exitCode === 0
    ? 'Update completed successfully.'
    : "Update failed with exit code {$exitCode}.";
writeRunnerState($state);

exit($exitCode);
