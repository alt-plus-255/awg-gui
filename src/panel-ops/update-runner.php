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

function rotateLogIfHuge(string $path, int $maxBytes = 10 * 1024 * 1024): void
{
    if (! is_file($path)) {
        return;
    }
    $size = @filesize($path);
    if ($size === false || $size <= $maxBytes) {
        return;
    }
    @unlink($path.'.1');
    @rename($path, $path.'.1');
    @file_put_contents($path, '');
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
 * Build host update job script (written to /etc/awg-gui/update-job.sh).
 */
function buildHostUpdateJobScript(?string $version, string $installUrl): string
{
    $script = "#!/bin/bash\n"
        ."set -euxo pipefail\n"
        ."mkdir -p /etc/awg-gui\n"
        ."touch /etc/awg-gui/update.log\n"
        ."chmod 666 /etc/awg-gui/update.log\n"
        ."exec >>/etc/awg-gui/update.log 2>&1\n"
        .'echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] host update job running"'."\n";
    if ($version !== null && $version !== '') {
        $script .= 'export AWG_GUI_VERSION='.escapeshellarg($version)."\n";
    }

    // Do not force AmneziaWG kernel install on GUI upgrades when it was never installed.
    // Pass --no-awg-kernel unless the package/module is present or AWG_KERNEL_WANTED=1.
    $script .= 'install_args=(--yes)'."\n"
        .'kernel_present=0'."\n"
        .'if [[ -x /etc/awg-gui/awg-kernel-host.sh ]]; then'."\n"
        .'  st="$(/etc/awg-gui/awg-kernel-host.sh status 2>/dev/null || true)"'."\n"
        .'  if echo "$st" | grep -qE \'"package_installed":true|"module_loaded":true\'; then'."\n"
        .'    kernel_present=1'."\n"
        .'  fi'."\n"
        .'fi'."\n"
        .'wanted="$(grep -E \'^AWG_KERNEL_WANTED=\' /opt/awg-gui/runtime/.env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d \'"\' || true)"'."\n"
        .'if [[ "$kernel_present" -ne 1 && "$wanted" != "1" ]]; then'."\n"
        .'  echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] AmneziaWG kernel not installed - skipping forced kernel install"'."\n"
        .'  install_args+=(--no-awg-kernel)'."\n"
        .'fi'."\n"
        .'tmp="$(mktemp /tmp/awg-gui-install.XXXXXX)"'."\n"
        .'trap \'rm -f "$tmp" /etc/awg-gui/update-job.sh; find /tmp -maxdepth 1 -type d \\( -name "awg-gui-install.*" -o -name "awg-gui-extract.*" \\) -exec rm -rf {} + 2>/dev/null || true\' EXIT'."\n"
        .'curl -fsSL '.escapeshellarg($installUrl).' -o "$tmp"'."\n"
        .'/bin/bash "$tmp" "${install_args[@]}"'."\n";

    return $script;
}

/**
 * Host-side bootstrap: write job script, then start it via systemd-run so the
 * process lives in the host systemd cgroup (survives alpine --rm / panel-ops recreate).
 * bundle-install.sh finalizes /etc/awg-gui/update.state when the upgrade finishes.
 */
function buildHostUpdateBootstrap(?string $version, string $installUrl): string
{
    $jobB64 = base64_encode(buildHostUpdateJobScript($version, $installUrl));

    return <<<HOST
set -euo pipefail
mkdir -p /etc/awg-gui
echo {$jobB64} | base64 -d > /etc/awg-gui/update-job.sh
chmod 700 /etc/awg-gui/update-job.sh
if systemctl is-active --quiet awg-gui-update.service; then
  echo 'awg-gui-update.service is already active' >&2
  exit 1
fi
systemctl stop awg-gui-update.service >/dev/null 2>&1 || true
systemctl reset-failed awg-gui-update.service >/dev/null 2>&1 || true
systemd-run --no-block --collect --unit=awg-gui-update.service --property=Type=oneshot /bin/bash /etc/awg-gui/update-job.sh
HOST;
}

/**
 * @return list<string>
 */
function buildUpdateCommand(?string $version): array
{
    $repo = getenv('AWG_GUI_GITHUB_REPO') ?: 'alt-plus-255/awg-gui';
    $installUrl = sprintf('https://raw.githubusercontent.com/%s/refs/heads/main/dist/install.sh', $repo);

    if ($version !== null && $version !== '' && ! preg_match('/^[0-9A-Za-z._-]+$/', $version)) {
        throw new InvalidArgumentException('Invalid update version.');
    }

    $helper = 'set -eu; apk add --no-cache util-linux >/dev/null; '
        .'nsenter -t 1 -m -u -i -n -p -- /bin/bash -lc '
        .escapeshellarg(buildHostUpdateBootstrap($version, $installUrl));

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
// Fresh update run: truncate current log; keep one oversized backup if needed.
rotateLogIfHuge($logPath);
@file_put_contents($logPath, '['.isoNow()."] update started\n");
@chmod($logPath, 0666);

try {
    $command = buildUpdateCommand($targetVersion);
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];

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

// Host install continues under systemd. Keep status=running; installer writes the final state.
@file_put_contents(
    $logPath,
    '['.isoNow()."] host update unit started (awg-gui-update.service); waiting for installer to finish\n",
    FILE_APPEND
);

exit(0);
