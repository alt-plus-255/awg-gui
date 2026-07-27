<?php

namespace App\Services\AmneziaWg;

use App\Models\AwgConfigPeer;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;

class VpnUriService
{
    private const AMNEZIA_QR_MAGIC = 0x07C00100;

    public function __construct(
        private AmneziaWgService $awg,
        private QrCodeService $qr,
        private ?AwgVersionRegistry $versions = null,
    ) {}

    private function versions(): AwgVersionRegistry
    {
        return $this->versions ??= app(AwgVersionRegistry::class);
    }

    /**
     * AmneziaWG mobile apps expect this packed payload in QR codes (not raw .conf text).
     * Format: base64url( magic 0x07C00100 + zlib_len + plain_len + gzcompress(json) ).
     */
    public function buildAmneziaQrPackFromMembership(AwgConfigPeer $membership): string
    {
        $json = $this->encodeOuterJson($membership);

        return $this->buildAmneziaQrPackFromOuterJson($json);
    }

    public function buildAmneziaQrPackFromOuterJson(string $json): string
    {
        $compressed = gzcompress($json);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress Amnezia QR payload');
        }

        $header = pack('N', self::AMNEZIA_QR_MAGIC)
            .pack('N', strlen($compressed) + 4)
            .pack('N', strlen($json));

        $packed = $header.$compressed;

        return rtrim(strtr(base64_encode($packed), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeAmneziaQrPack(string $encoded): array
    {
        $encoded = trim($encoded);
        $padding = (4 - strlen($encoded) % 4) % 4;
        $packed = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);
        if ($packed === false || strlen($packed) < 12) {
            throw new \InvalidArgumentException('Invalid Amnezia QR base64 payload');
        }

        $magic = unpack('N', substr($packed, 0, 4))[1];
        if ($magic !== self::AMNEZIA_QR_MAGIC) {
            throw new \InvalidArgumentException('Invalid Amnezia QR magic header');
        }

        $jsonLen = unpack('N', substr($packed, 8, 4))[1];
        $compressed = substr($packed, 12);
        $json = gzuncompress($compressed);
        if ($json === false) {
            throw new \InvalidArgumentException('Invalid Amnezia QR zlib payload');
        }

        if ($jsonLen !== strlen($json)) {
            throw new \InvalidArgumentException('Amnezia QR length header mismatch');
        }

        $outer = json_decode($json, true);
        if (! is_array($outer)) {
            throw new \InvalidArgumentException('Invalid Amnezia QR JSON');
        }

        return $outer;
    }

    public function buildFromMembership(AwgConfigPeer $membership): string
    {
        $json = $this->encodeOuterJson($membership);
        $compressed = gzcompress($json);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress vpn:// payload');
        }

        $payload = pack('N', strlen($json)).$compressed;
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        return 'vpn://'.$encoded;
    }

    public function encodeOuterJson(AwgConfigPeer $membership): string
    {
        $outer = $this->buildOuterFromMembership($membership);

        return json_encode($outer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOuterFromMembership(AwgConfigPeer $membership): array
    {
        if (! $membership->relationLoaded('config') || ! $membership->relationLoaded('client')) {
            $membership->loadMissing(['config', 'client']);
        }
        $config = $membership->config;
        if (! $config) {
            throw new \RuntimeException('Config not found for membership');
        }

        $profile = $this->versions()->profileForConfig($config->protocol_version);

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

        $inner = array_merge($profile->vpnUriInnerParams($config), [
            'allowed_ips' => $allowedIps,
            'client_ip' => $address,
            'client_priv_key' => $privateKey,
            'config' => $conf,
            'hostName' => $hostName,
            'mtu' => '1280',
            'persistent_keep_alive' => (string) $keepalive,
            'port' => (int) $config->listen_port,
            'server_pub_key' => $config->server_public_key,
        ]);

        if ($psk !== null && $psk !== '') {
            $inner['psk_key'] = $psk;
        }

        $description = $this->awg->clientImportLabel($membership);

        return [
            'containers' => [[
                'awg' => [
                    'isThirdPartyConfig' => true,
                    'last_config' => json_encode($inner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'port' => (string) $config->listen_port,
                    'protocol_version' => $profile->vpnUriProtocolVersion(),
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
