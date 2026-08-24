<?php

namespace App\Services\Resolver;

use Illuminate\Validation\ValidationException;

class AmneziaWgConfParser
{
    /** @var list<string> */
    private const JUNK_KEYS = [
        'jc', 'jmin', 'jmax',
        's1', 's2', 's3', 's4',
        'h1', 'h2', 'h3', 'h4',
        'i1', 'i2', 'i3', 'i4', 'i5',
    ];

    /**
     * Parse AmneziaWG / WireGuard client .conf text.
     *
     * @return array{
     *     private_key: string,
     *     address: string,
     *     dns: ?string,
     *     mtu: ?string,
     *     jc?: string, jmin?: string, jmax?: string,
     *     s1?: string, s2?: string, s3?: string, s4?: string,
     *     h1?: string, h2?: string, h3?: string, h4?: string,
     *     i1?: string, i2?: string, i3?: string, i4?: string, i5?: string,
     *     peer: array{
     *         public_key: string,
     *         endpoint: string,
     *         allowed_ips: string,
     *         preshared_key: ?string,
     *         persistent_keepalive: ?string
     *     }
     * }
     */
    public function parse(string $raw): array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        if ($raw === '') {
            throw ValidationException::withMessages([
                'awg_conf' => [__('resolver.awg_conf_required')],
            ]);
        }

        $sections = $this->parseSections($raw);
        $interface = $sections['interface'] ?? [];
        $peers = $sections['peers'] ?? [];

        if ($interface === []) {
            throw ValidationException::withMessages([
                'awg_conf' => [__('resolver.awg_conf_missing_interface')],
            ]);
        }
        if ($peers === []) {
            throw ValidationException::withMessages([
                'awg_conf' => [__('resolver.awg_conf_missing_peer')],
            ]);
        }

        $privateKey = $this->requireKey($interface, 'PrivateKey', 'awg_conf');
        $address = $this->requireKey($interface, 'Address', 'awg_conf');

        $peer = $peers[0];
        $publicKey = $this->requireKey($peer, 'PublicKey', 'awg_conf');
        $endpoint = $this->requireKey($peer, 'Endpoint', 'awg_conf');
        $allowedIps = trim((string) ($peer['AllowedIPs'] ?? '0.0.0.0/0, ::/0'));
        if ($allowedIps === '') {
            $allowedIps = '0.0.0.0/0, ::/0';
        }

        $out = [
            'private_key' => $privateKey,
            'address' => $address,
            'dns' => $this->optionalKey($interface, 'DNS'),
            'mtu' => $this->optionalKey($interface, 'MTU'),
            'peer' => [
                'public_key' => $publicKey,
                'endpoint' => $endpoint,
                'allowed_ips' => $allowedIps,
                'preshared_key' => $this->optionalKey($peer, 'PresharedKey'),
                'persistent_keepalive' => $this->optionalKey($peer, 'PersistentKeepalive'),
            ],
        ];

        foreach (self::JUNK_KEYS as $key) {
            $confKey = $this->junkConfKey($key);
            if (array_key_exists($confKey, $interface)) {
                $out[$key] = trim((string) $interface[$confKey]);

                continue;
            }
            // Case-insensitive fallback (Jc vs JC vs jc)
            foreach ($interface as $ik => $iv) {
                if (strcasecmp((string) $ik, $confKey) === 0) {
                    $out[$key] = trim((string) $iv);
                    break;
                }
            }
        }

        return $out;
    }

    private function junkConfKey(string $field): string
    {
        return match ($field) {
            'jc' => 'Jc',
            'jmin' => 'Jmin',
            'jmax' => 'Jmax',
            default => strtoupper($field),
        };
    }

    /**
     * @return array{interface?: array<string, string>, peers: list<array<string, string>>}
     */
    private function parseSections(string $raw): array
    {
        $interface = null;
        $peers = [];
        $current = null;
        /** @var array<string, string> $bucket */
        $bucket = [];

        $flush = function () use (&$current, &$bucket, &$interface, &$peers): void {
            if ($current === null) {
                return;
            }
            if ($current === 'interface') {
                $interface = $bucket;
            } elseif ($current === 'peer') {
                $peers[] = $bucket;
            }
            $bucket = [];
            $current = null;
        };

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^\[(.+)\]$/u', $line, $m)) {
                $flush();
                $name = strtolower(trim($m[1]));
                if ($name === 'interface') {
                    $current = 'interface';
                } elseif ($name === 'peer') {
                    $current = 'peer';
                } else {
                    $current = 'other';
                }
                $bucket = [];

                continue;
            }

            if ($current === null || $current === 'other') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }
            $bucket[$key] = $value;
        }

        $flush();

        return [
            'interface' => $interface ?? [],
            'peers' => $peers,
        ];
    }

    /**
     * @param  array<string, string>  $section
     */
    private function requireKey(array $section, string $key, string $field): string
    {
        $value = trim((string) ($section[$key] ?? ''));
        if ($value === '') {
            throw ValidationException::withMessages([
                $field => [__('resolver.awg_conf_missing_field', ['field' => $key])],
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $section
     */
    private function optionalKey(array $section, string $key): ?string
    {
        $value = trim((string) ($section[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
