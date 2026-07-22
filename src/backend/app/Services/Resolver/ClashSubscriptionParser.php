<?php

namespace App\Services\Resolver;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ClashSubscriptionParser
{
    public function __construct(
        private SingBoxOutboundParser $parser,
    ) {}

    /**
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>|null
     */
    public function parse(string $body): ?array
    {
        if (! str_contains($body, 'proxies:')) {
            return null;
        }

        try {
            $data = Yaml::parse($body);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $proxies = $data['proxies'] ?? null;
        if (! is_array($proxies)) {
            return null;
        }

        $nodes = [];
        $seen = [];
        foreach ($proxies as $proxy) {
            if (! is_array($proxy)) {
                continue;
            }
            try {
                $outbound = $this->parser->normalize($this->outboundFromClashProxy($proxy));
            } catch (\Throwable) {
                continue;
            }
            if (empty($outbound['type']) || empty($outbound['server'])) {
                continue;
            }
            $name = trim((string) ($proxy['name'] ?? ''));
            if ($name === '') {
                $name = (string) ($outbound['server'] ?? 'node');
            }
            $uri = json_encode($proxy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $key = substr(hash('sha256', (string) $uri), 0, 16);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $nodes[] = [
                'key' => $key,
                'name' => mb_substr($name, 0, 120),
                'type' => (string) $outbound['type'],
                'server' => (string) $outbound['server'],
                'port' => (int) ($outbound['server_port'] ?? 0),
                'outbound' => $outbound,
            ];
        }

        return $nodes === [] ? null : $nodes;
    }

    /** @param  array<string, mixed>  $p */
    private function outboundFromClashProxy(array $p): array
    {
        $type = strtolower((string) ($p['type'] ?? ''));
        $server = (string) ($p['server'] ?? '');
        $port = (int) ($p['port'] ?? 443);
        if ($server === '') {
            throw new \InvalidArgumentException('missing server');
        }

        return match ($type) {
            'vless' => $this->vlessFromClash($p, $server, $port),
            'vmess' => $this->vmessFromClash($p, $server, $port),
            'trojan' => [
                'type' => 'trojan',
                'server' => $server,
                'server_port' => $port,
                'password' => (string) ($p['password'] ?? ''),
                ...$this->clashTls($p),
            ],
            'ss' => [
                'type' => 'shadowsocks',
                'server' => $server,
                'server_port' => $port,
                'method' => (string) ($p['cipher'] ?? 'aes-256-gcm'),
                'password' => (string) ($p['password'] ?? ''),
            ],
            'socks5', 'socks' => [
                'type' => 'socks',
                'server' => $server,
                'server_port' => $port,
                'username' => (string) ($p['username'] ?? ''),
                'password' => (string) ($p['password'] ?? ''),
            ],
            'hysteria', 'hysteria2', 'hy2' => [
                'type' => 'hysteria2',
                'server' => $server,
                'server_port' => $port,
                'password' => (string) ($p['password'] ?? $p['auth'] ?? ''),
            ],
            default => throw new \InvalidArgumentException("unsupported clash type: {$type}"),
        };
    }

    /** @param  array<string, mixed>  $p */
    private function vmessFromClash(array $p, string $server, int $port): array
    {
        $ob = [
            'type' => 'vmess',
            'server' => $server,
            'server_port' => $port,
            'uuid' => (string) ($p['uuid'] ?? ''),
            'security' => (string) ($p['cipher'] ?? 'auto'),
            'alter_id' => (int) ($p['alterId'] ?? $p['alter_id'] ?? 0),
        ];
        $network = strtolower((string) ($p['network'] ?? 'tcp'));
        $tls = $this->clashTls($p);
        if ($tls !== []) {
            $ob['tls'] = $tls['tls'];
        }
        $transport = $this->clashTransport($p, $network);
        if ($transport !== null) {
            $ob['transport'] = $transport;
        }

        return $ob;
    }

    /** @param  array<string, mixed>  $p */
    private function vlessFromClash(array $p, string $server, int $port): array
    {
        $ob = [
            'type' => 'vless',
            'server' => $server,
            'server_port' => $port,
            'uuid' => (string) ($p['uuid'] ?? ''),
            'packet_encoding' => 'xudp',
        ];
        // Clash `network` is transport (tcp/ws/grpc), not sing-box dial network.
        // Leave dial network unset so XUDP can carry UDP; only wire transport below.
        $network = strtolower((string) ($p['network'] ?? 'tcp'));
        $flow = (string) ($p['flow'] ?? '');
        if ($flow !== '') {
            $ob['flow'] = $flow;
        }
        $tls = $this->clashTls($p);
        if ($tls !== []) {
            $ob['tls'] = $tls['tls'];
        }
        $transport = $this->clashTransport($p, $network);
        if ($transport !== null) {
            $ob['transport'] = $transport;
        }

        return $ob;
    }

    /** @param  array<string, mixed>  $p */
    private function clashTls(array $p): array
    {
        $tls = ! empty($p['tls']);
        $sni = (string) ($p['servername'] ?? $p['sni'] ?? $p['server'] ?? '');
        $fp = (string) ($p['client-fingerprint'] ?? $p['fp'] ?? 'chrome');
        $realityOpts = $p['reality-opts'] ?? $p['reality_opts'] ?? null;
        if (is_array($realityOpts) && ! empty($realityOpts['public-key'])) {
            return [
                'tls' => [
                    'enabled' => true,
                    'server_name' => $sni,
                    'utls' => ['enabled' => true, 'fingerprint' => $fp ?: 'chrome'],
                    'reality' => [
                        'enabled' => true,
                        'public_key' => (string) $realityOpts['public-key'],
                        'short_id' => (string) ($realityOpts['short-id'] ?? $realityOpts['short_id'] ?? ''),
                    ],
                ],
            ];
        }
        if ($tls) {
            return [
                'tls' => [
                    'enabled' => true,
                    'server_name' => $sni,
                    'utls' => ['enabled' => true, 'fingerprint' => $fp ?: 'chrome'],
                ],
            ];
        }

        return [];
    }

    /** @param  array<string, mixed>  $p */
    private function clashTransport(array $p, string $network): ?array
    {
        if ($network === 'ws') {
            $wsOpts = is_array($p['ws-opts'] ?? null) ? $p['ws-opts'] : [];
            $ob = ['type' => 'ws', 'path' => (string) ($wsOpts['path'] ?? '/')];
            $headers = is_array($wsOpts['headers'] ?? null) ? $wsOpts['headers'] : [];
            if ($headers !== []) {
                $ob['headers'] = $headers;
            }

            return $ob;
        }
        if ($network === 'grpc') {
            $grpcOpts = is_array($p['grpc-opts'] ?? null) ? $p['grpc-opts'] : [];

            return [
                'type' => 'grpc',
                'service_name' => (string) ($grpcOpts['grpc-service-name'] ?? 'GunService'),
            ];
        }
        if ($network === 'http' || $network === 'h2') {
            return [
                'type' => 'http',
                'path' => (string) ($p['path'] ?? '/'),
            ];
        }

        return null;
    }
}
