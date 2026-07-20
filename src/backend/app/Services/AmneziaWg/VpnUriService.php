<?php

namespace App\Services\AmneziaWg;

use App\Models\AwgConfigPeer;

class VpnUriService
{
    public function __construct(
        private AmneziaWgService $awg,
        private QrCodeService $qr,
    ) {}

    public function buildFromMembership(AwgConfigPeer $membership): string
    {
        if (! $membership->relationLoaded('config') || ! $membership->relationLoaded('client')) {
            $membership->loadMissing(['config', 'client']);
        }
        $config = $membership->config;
        if (! $config) {
            throw new \RuntimeException('Config not found for membership');
        }

        $conf = $this->qr->normalizeConfigText($this->awg->buildClientConfig($membership));
        $conf = rtrim($conf, "\n");

        $privateKey = $this->matchConf($conf, 'PrivateKey');
        $address = $this->matchConf($conf, 'Address');
        $allowedRaw = $this->matchConf($conf, 'AllowedIPs') ?: '0.0.0.0/0';
        $endpoint = $this->matchConf($conf, 'Endpoint') ?: '';
        $keepalive = $this->matchConf($conf, 'PersistentKeepalive')
            ?? (string) ($membership->keepalive ?? $config->persistent_keepalive ?? 25);
        $psk = $this->matchConf($conf, 'PresharedKey');

        $hostName = $this->parseEndpointHost($endpoint);
        $allowedIps = array_values(array_filter(array_map('trim', explode(',', $allowedRaw))));

        $dnsParts = array_values(array_filter(array_map('trim', explode(',', $this->matchConf($conf, 'DNS') ?: '1.1.1.1'))));
        $dns1 = $dnsParts[0] ?? '1.1.1.1';
        $dns2 = $dnsParts[1] ?? $dns1;

        $inner = [
            'H1' => (string) $config->h1,
            'H2' => (string) $config->h2,
            'H3' => (string) $config->h3,
            'H4' => (string) $config->h4,
            'Jc' => (string) $config->jc,
            'Jmin' => (string) $config->jmin,
            'Jmax' => (string) $config->jmax,
            'S1' => (string) $config->s1,
            'S2' => (string) $config->s2,
            'S3' => (string) $config->s3,
            'S4' => (string) $config->s4,
            'allowed_ips' => $allowedIps,
            'client_ip' => $address,
            'client_priv_key' => $privateKey,
            'config' => $conf,
            'hostName' => $hostName,
            'mtu' => '1280',
            'persistent_keep_alive' => (string) $keepalive,
            'port' => (int) $config->listen_port,
            'server_pub_key' => $config->server_public_key,
        ];

        $i1 = trim((string) ($config->i1 ?? ''));
        if ($i1 !== '') {
            $inner['I1'] = $i1;
            $inner['I2'] = '';
            $inner['I3'] = '';
            $inner['I4'] = '';
            $inner['I5'] = '';
        }

        if ($psk !== null && $psk !== '') {
            $inner['psk_key'] = $psk;
        }

        $description = $membership->client?->name
            ? 'AWG '.$membership->client->name
            : 'AWG Server';

        $outer = [
            'containers' => [[
                'awg' => [
                    'isThirdPartyConfig' => true,
                    'last_config' => json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'port' => (string) $config->listen_port,
                    'protocol_version' => '2',
                    'transport_proto' => 'udp',
                ],
                'container' => 'amnezia-awg',
            ]],
            'defaultContainer' => 'amnezia-awg',
            'description' => $description,
            'dns1' => $dns1,
            'dns2' => $dns2,
            'hostName' => $hostName,
        ];

        $json = json_encode($outer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $compressed = gzcompress($json);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress vpn:// payload');
        }

        $payload = pack('N', strlen($json)).$compressed;
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return 'vpn://'.$encoded;
    }

    public function decode(string $vpnUri): array
    {
        $encoded = preg_replace('#^vpn://#', '', trim($vpnUri));
        if ($encoded === null || $encoded === '') {
            throw new \InvalidArgumentException('Invalid vpn:// URI');
        }

        $padding = (4 - strlen($encoded) % 4) % 4;
        $payload = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);
        if ($payload === false || strlen($payload) < 5) {
            throw new \InvalidArgumentException('Invalid vpn:// base64 payload');
        }

        $jsonLen = unpack('N', substr($payload, 0, 4))[1];
        $compressed = substr($payload, 4);
        $json = gzuncompress($compressed);
        if ($json === false) {
            throw new \InvalidArgumentException('Invalid vpn:// zlib payload');
        }

        if ($jsonLen !== strlen($json)) {
            throw new \InvalidArgumentException('vpn:// length header mismatch');
        }

        $outer = json_decode($json, true);
        if (! is_array($outer)) {
            throw new \InvalidArgumentException('Invalid vpn:// JSON');
        }

        return $outer;
    }

    private function matchConf(string $conf, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').'\s*=\s*(.+)$/m', $conf, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function parseEndpointHost(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }

        if (str_starts_with($endpoint, '[')) {
            $close = strpos($endpoint, ']');
            if ($close !== false) {
                return substr($endpoint, 1, $close - 1);
            }
        }

        $parts = explode(':', $endpoint);

        return $parts[0] ?? $endpoint;
    }
}
