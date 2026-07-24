<?php

namespace App\Services\Resolver;

use App\Services\AmneziaWg\Versions\AwgVersionProfile;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;

class AmneziaWgClientConfBuilder
{
    public function __construct(private AwgVersionRegistry $versions) {}

    public function ifaceName(int $connectionId): string
    {
        return 'awgc'.$connectionId;
    }

    /**
     * @return array{type: string, bind_interface: string}
     */
    public function outboundFor(int $connectionId): array
    {
        return [
            'type' => 'direct',
            'bind_interface' => $this->ifaceName($connectionId),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed  from AmneziaWgConfParser
     */
    public function build(array $parsed, string $protocolVersion): string
    {
        $profile = $this->versions->profileForConfig($protocolVersion);
        $junk = $this->normalizedJunk($parsed, $profile);

        $lines = [
            '[Interface]',
            'PrivateKey = '.$parsed['private_key'],
            'Address = '.$parsed['address'],
            'Table = off',
        ];

        if (! empty($parsed['dns'])) {
            $lines[] = 'DNS = '.$parsed['dns'];
        }
        if (! empty($parsed['mtu'])) {
            $lines[] = 'MTU = '.$parsed['mtu'];
        }

        array_push($lines, ...$profile->confObfuscationLinesFromParams($junk));

        $peer = $parsed['peer'] ?? [];
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = '.$peer['public_key'];
        if (! empty($peer['preshared_key'])) {
            $lines[] = 'PresharedKey = '.$peer['preshared_key'];
        }
        $lines[] = 'Endpoint = '.$peer['endpoint'];
        $lines[] = 'AllowedIPs = '.($peer['allowed_ips'] ?? '0.0.0.0/0, ::/0');
        if (! empty($peer['persistent_keepalive'])) {
            $lines[] = 'PersistentKeepalive = '.$peer['persistent_keepalive'];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    public function normalizedJunk(array $parsed, ?AwgVersionProfile $profile = null, ?string $protocolVersion = null): array
    {
        $profile ??= $this->versions->profileForConfig($protocolVersion);
        $params = [];
        foreach (['jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4', 'h1', 'h2', 'h3', 'h4', 'i1', 'i2', 'i3', 'i4', 'i5'] as $key) {
            if (array_key_exists($key, $parsed)) {
                $params[$key] = $parsed[$key];
            }
        }

        return $profile->normalizeForPersist($params);
    }
}
