<?php

namespace App\Services\Resolver;

use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use Illuminate\Support\Facades\Log;

/**
 * Resolve the NIC sing-box / MASQUERADE should use inside the AWG container.
 * Docker bridge is usually eth0, but host-network / custom nets may differ.
 */
class EgressInterfaceResolver
{
    public const SETTING_KEY = 'singbox_egress_interface';

    public const AUTO = 'auto';

    /** Last-resort fallback when detection fails. */
    public const FALLBACK = 'eth0';

    private ?string $resolvedCache = null;

    /** @var list<string>|null */
    private ?array $candidatesCache = null;

    private ?string $detectedCache = null;

    private bool $detectedResolved = false;

    public function __construct(
        private AmneziaWgService $awg,
        private DockerRuntime $docker,
    ) {}

    public function settingValue(): string
    {
        $raw = trim((string) Setting::getValue(self::SETTING_KEY, self::AUTO));
        if ($raw === '') {
            return self::AUTO;
        }

        return $raw;
    }

    /**
     * Interface used in sing-box route.default_interface and PostUp MASQUERADE.
     */
    public function resolve(): string
    {
        if ($this->resolvedCache !== null) {
            return $this->resolvedCache;
        }

        $setting = $this->settingValue();
        if ($setting !== self::AUTO && $this->isValidIfaceName($setting)) {
            return $this->resolvedCache = $setting;
        }

        $detected = $this->detectDefault();
        if ($detected !== null) {
            return $this->resolvedCache = $detected;
        }

        return $this->resolvedCache = self::FALLBACK;
    }

    /**
     * Default-route NIC inside the AWG container, or null if unknown.
     */
    public function detectDefault(): ?string
    {
        if ($this->detectedResolved) {
            return $this->detectedCache;
        }
        $this->detectedResolved = true;

        if (! $this->awg->isContainerRunning()) {
            return $this->detectedCache = null;
        }

        try {
            $r = $this->docker->exec(
                $this->awg->containerName(),
                [
                    'sh', '-c',
                    // Prefer default route; fall back to route-get toward a public IP.
                    'iface=$(ip -4 route show default 0.0.0.0/0 2>/dev/null | awk \'{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}\'); '
                    .'if [ -z "$iface" ]; then '
                    .'iface=$(ip -o -4 route get 1.1.1.1 2>/dev/null | awk \'{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}\'); '
                    .'fi; '
                    .'echo "$iface"',
                ],
                timeout: 8,
            );
            $iface = trim($r->output());
            if ($this->isValidIfaceName($iface) && ! $this->isTunnelIface($iface)) {
                return $this->detectedCache = $iface;
            }
        } catch (\Throwable $e) {
            Log::warning('egress iface detect: '.$e->getMessage());
        }

        return $this->detectedCache = null;
    }

    /**
     * Candidate NICs for the settings UI (excludes lo / awg* / awgc* / sbox0).
     *
     * @return list<string>
     */
    public function listCandidates(): array
    {
        if ($this->candidatesCache !== null) {
            return $this->candidatesCache;
        }

        $out = [];
        if ($this->awg->isContainerRunning()) {
            try {
                $r = $this->docker->exec(
                    $this->awg->containerName(),
                    [
                        'sh', '-c',
                        "ip -o link show 2>/dev/null | awk -F': ' '{print \$2}' | cut -d'@' -f1",
                    ],
                    timeout: 8,
                );
                foreach (preg_split('/\r\n|\r|\n/', trim($r->output())) ?: [] as $line) {
                    $iface = trim($line);
                    if (! $this->isValidIfaceName($iface) || $iface === 'lo' || $this->isTunnelIface($iface)) {
                        continue;
                    }
                    $out[] = $iface;
                }
            } catch (\Throwable $e) {
                Log::warning('egress iface list: '.$e->getMessage());
            }
        }

        $detected = $this->detectDefault();
        if ($detected !== null && ! in_array($detected, $out, true)) {
            $out[] = $detected;
        }

        $setting = $this->settingValue();
        if ($setting !== self::AUTO && $this->isValidIfaceName($setting) && ! in_array($setting, $out, true)) {
            $out[] = $setting;
        }

        sort($out);

        return $this->candidatesCache = array_values($out);
    }

    /** @return array{setting: string, resolved: string, detected: ?string, options: list<string>} */
    public function status(): array
    {
        return [
            'setting' => $this->settingValue(),
            'resolved' => $this->resolve(),
            'detected' => $this->detectDefault(),
            'options' => $this->listCandidates(),
        ];
    }

    public function forgetCache(): void
    {
        $this->resolvedCache = null;
        $this->candidatesCache = null;
        $this->detectedCache = null;
        $this->detectedResolved = false;
    }

    public function isValidIfaceName(string $iface): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,14}$/', $iface);
    }

    private function isTunnelIface(string $iface): bool
    {
        return (bool) preg_match('/^(awg|awgc)\d+$/', $iface)
            || $iface === ResolverService::TUN_IFACE;
    }
}
