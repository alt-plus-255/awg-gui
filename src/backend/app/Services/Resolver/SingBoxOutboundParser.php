<?php

namespace App\Services\Resolver;

use Illuminate\Validation\ValidationException;

class SingBoxOutboundParser
{
    /**
     * @return array<string, mixed>
     */
    public function fromRequest(string $configType, ?string $shareUrl, ?string $outboundJson): array
    {
        if ($configType === 'json') {
            return $this->normalize($this->fromJson($outboundJson ?? ''));
        }

        if ($configType === 'url') {
            return $this->normalize($this->fromShareUrl($shareUrl ?? ''));
        }

        throw ValidationException::withMessages([
            'config_type' => ['Тип конфигурации: url или json'],
        ]);
    }

    /**
     * Bring outbound to sing-box 1.12+ shape used by our resolver.
     *
     * @param  array<string, mixed>  $outbound
     * @return array<string, mixed>
     */
    public function normalize(array $outbound): array
    {
        unset($outbound['tag']);

        $type = (string) ($outbound['type'] ?? '');
        if ($type === '') {
            return $outbound;
        }

        if ($type === 'vless') {
            if (! isset($outbound['packet_encoding']) || $outbound['packet_encoding'] === '' || $outbound['packet_encoding'] === null) {
                $outbound['packet_encoding'] = 'xudp';
            }

            // Vision dials over TCP, but UDP clients must still reach the outbound
            // so XUDP can carry them. `network: tcp` makes sing-box reject UDP
            // ("UDP is not supported by outbound") before XUDP runs — strip it.
            if (($outbound['packet_encoding'] ?? '') === 'xudp') {
                unset($outbound['network']);
            }

            if (isset($outbound['tls']) && is_array($outbound['tls'])) {
                $outbound['tls']['enabled'] = true;
                $reality = $outbound['tls']['reality'] ?? null;
                if (is_array($reality) && (! empty($reality['enabled']) || ! empty($reality['public_key']))) {
                    $outbound['tls']['reality'] = [
                        'enabled' => true,
                        'public_key' => (string) ($reality['public_key'] ?? ''),
                        'short_id' => (string) ($reality['short_id'] ?? ''),
                    ];
                    $fp = (string) data_get($outbound, 'tls.utls.fingerprint', 'chrome');
                    if ($fp === '') {
                        $fp = 'chrome';
                    }
                    $outbound['tls']['utls'] = [
                        'enabled' => true,
                        'fingerprint' => $fp,
                    ];
                }
            }
        }

        if ($this->needsDomainResolver($outbound)) {
            $outbound['domain_resolver'] = 'bootstrap';
        }

        return $outbound;
    }

    /**
     * @param  array<string, mixed>  $outbound
     */
    private function needsDomainResolver(array $outbound): bool
    {
        if (isset($outbound['domain_resolver'])) {
            return false;
        }

        $server = $outbound['server'] ?? null;
        if (! is_string($server) || $server === '') {
            return false;
        }

        return filter_var($server, FILTER_VALIDATE_IP) === false;
    }

    /**
     * @return array<string, mixed>
     */
    public function fromJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw ValidationException::withMessages([
                'outbound_json' => ['Укажите outbound JSON'],
            ]);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw ValidationException::withMessages([
                'outbound_json' => ['Ожидается JSON-объект outbound sing-box'],
            ]);
        }

        if (empty($decoded['type']) || ! is_string($decoded['type'])) {
            throw ValidationException::withMessages([
                'outbound_json' => ['В outbound обязательно поле type'],
            ]);
        }

        unset($decoded['tag']);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function fromShareUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw ValidationException::withMessages([
                'share_url' => ['Укажите ссылку подключения'],
            ]);
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return match ($scheme) {
            'vless' => $this->parseVless($url),
            'ss' => $this->parseShadowsocks($url),
            'trojan' => $this->parseTrojan($url),
            'hysteria2', 'hy2' => $this->parseHysteria2($url),
            'socks', 'socks5' => $this->parseSocks($url),
            default => throw ValidationException::withMessages([
                'share_url' => ["Неподдерживаемая схема: {$scheme}. Доступны vless, ss, trojan, hy2/hysteria2, socks"],
            ]),
        };
    }

    /** @return array<string, mixed> */
    private function parseVless(string $url): array
    {
        $parts = parse_url($url);
        $uuid = rawurldecode((string) ($parts['user'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? 443);
        if ($uuid === '' || $host === '') {
            throw ValidationException::withMessages(['share_url' => ['Некорректная vless:// ссылка']]);
        }

        parse_str($parts['query'] ?? '', $q);
        $security = strtolower((string) ($q['security'] ?? 'none'));
        $network = strtolower((string) ($q['type'] ?? 'tcp'));
        $sni = (string) ($q['sni'] ?? $q['host'] ?? $host);
        $fp = (string) ($q['fp'] ?? 'chrome');
        if ($fp === '') {
            $fp = 'chrome';
        }
        $alpn = isset($q['alpn']) ? array_values(array_filter(explode(',', (string) $q['alpn']))) : null;
        $flow = (string) ($q['flow'] ?? '');
        $path = rawurldecode((string) ($q['path'] ?? '/'));
        $hostHeader = (string) ($q['host'] ?? '');
        $serviceName = (string) ($q['serviceName'] ?? $q['service_name'] ?? '');
        $pbk = (string) ($q['pbk'] ?? '');
        $sid = (string) ($q['sid'] ?? '');

        // sing-box 1.12+ VLESS outbound (Reality + Vision).
        $outbound = [
            'type' => 'vless',
            'server' => $host,
            'server_port' => $port,
            'uuid' => $uuid,
            'packet_encoding' => 'xudp',
        ];

        if ($flow !== '') {
            $outbound['flow'] = $flow;
            // Do not set network=tcp: XUDP needs UDP accepted at the outbound.
        } elseif ($network === 'udp') {
            $outbound['network'] = 'udp';
        }

        if ($security === 'reality') {
            if ($pbk === '') {
                throw ValidationException::withMessages(['share_url' => ['Reality: отсутствует pbk']]);
            }
            $outbound['tls'] = [
                'enabled' => true,
                'server_name' => $sni,
                'utls' => [
                    'enabled' => true,
                    'fingerprint' => $fp,
                ],
                'reality' => [
                    'enabled' => true,
                    'public_key' => $pbk,
                    'short_id' => $sid,
                ],
            ];
        } elseif ($security === 'tls') {
            $tls = [
                'enabled' => true,
                'server_name' => $sni,
                'utls' => [
                    'enabled' => true,
                    'fingerprint' => $fp,
                ],
            ];
            if ($alpn) {
                $tls['alpn'] = $alpn;
            }
            $outbound['tls'] = $tls;
        }

        if ($network === 'ws') {
            $outbound['transport'] = [
                'type' => 'ws',
                'path' => $path !== '' ? $path : '/',
            ];
            if ($hostHeader !== '') {
                $outbound['transport']['headers'] = ['Host' => $hostHeader];
            }
        } elseif ($network === 'grpc') {
            $outbound['transport'] = [
                'type' => 'grpc',
                'service_name' => $serviceName !== '' ? $serviceName : 'GunService',
            ];
        } elseif ($network === 'httpupgrade') {
            $outbound['transport'] = [
                'type' => 'httpupgrade',
                'path' => $path !== '' ? $path : '/',
            ];
            if ($hostHeader !== '') {
                $outbound['transport']['host'] = $hostHeader;
            }
        } elseif ($network === 'http' || $network === 'h2') {
            $outbound['transport'] = [
                'type' => 'http',
                'path' => $path !== '' ? $path : '/',
            ];
            if ($hostHeader !== '') {
                $outbound['transport']['host'] = [$hostHeader];
            }
        }

        return $outbound;
    }

    /** @return array<string, mixed> */
    private function parseShadowsocks(string $url): array
    {
        // ss://BASE64(method:password)@host:port#name
        // or ss://BASE64(method:password@host:port)#name
        $url = preg_replace('/^ss:\/\//', '', $url) ?? $url;
        $url = explode('#', $url, 2)[0];

        $method = '';
        $password = '';
        $host = '';
        $port = 0;

        if (str_contains($url, '@')) {
            [$userinfo, $hostport] = explode('@', $url, 2);
            if (! str_contains($userinfo, ':')) {
                $decoded = base64_decode(strtr($userinfo, '-_', '+/'), true);
                if ($decoded === false || ! str_contains($decoded, ':')) {
                    throw ValidationException::withMessages(['share_url' => ['Некорректная ss:// ссылка']]);
                }
                [$method, $password] = explode(':', $decoded, 2);
            } else {
                [$method, $password] = explode(':', $userinfo, 2);
            }
            if (str_contains($hostport, ':')) {
                [$host, $portStr] = explode(':', $hostport, 2);
                $port = (int) $portStr;
            }
        } else {
            $decoded = base64_decode(strtr($url, '-_', '+/'), true);
            if ($decoded === false || ! preg_match('#^(.+?):(.+)@(.+):(\d+)$#', $decoded, $m)) {
                throw ValidationException::withMessages(['share_url' => ['Некорректная ss:// ссылка']]);
            }
            $method = $m[1];
            $password = $m[2];
            $host = $m[3];
            $port = (int) $m[4];
        }

        if ($method === '' || $password === '' || $host === '' || $port < 1) {
            throw ValidationException::withMessages(['share_url' => ['Некорректная ss:// ссылка']]);
        }

        return [
            'type' => 'shadowsocks',
            'server' => $host,
            'server_port' => $port,
            'method' => $method,
            'password' => $password,
        ];
    }

    /** @return array<string, mixed> */
    private function parseTrojan(string $url): array
    {
        $parts = parse_url($url);
        $password = $parts['user'] ?? '';
        $host = $parts['host'] ?? '';
        $port = (int) ($parts['port'] ?? 443);
        if ($password === '' || $host === '') {
            throw ValidationException::withMessages(['share_url' => ['Некорректная trojan:// ссылка']]);
        }
        parse_str($parts['query'] ?? '', $q);
        $sni = (string) ($q['sni'] ?? $host);
        $allowInsecure = in_array(strtolower((string) ($q['allowInsecure'] ?? '0')), ['1', 'true'], true);

        return [
            'type' => 'trojan',
            'server' => $host,
            'server_port' => $port,
            'password' => rawurldecode($password),
            'tls' => [
                'enabled' => true,
                'server_name' => $sni,
                'insecure' => $allowInsecure,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function parseHysteria2(string $url): array
    {
        $parts = parse_url($url);
        $password = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $host = $parts['host'] ?? '';
        $port = (int) ($parts['port'] ?? 443);
        if ($password === '' || $host === '') {
            throw ValidationException::withMessages(['share_url' => ['Некорректная hysteria2:// ссылка']]);
        }
        parse_str($parts['query'] ?? '', $q);
        $sni = (string) ($q['sni'] ?? $host);
        $insecure = in_array(strtolower((string) ($q['insecure'] ?? '0')), ['1', 'true'], true);

        $outbound = [
            'type' => 'hysteria2',
            'server' => $host,
            'server_port' => $port,
            'password' => $password,
            'tls' => [
                'enabled' => true,
                'server_name' => $sni,
                'insecure' => $insecure,
            ],
        ];
        if (! empty($q['obfs'])) {
            $outbound['obfs'] = [
                'type' => (string) $q['obfs'],
                'password' => (string) ($q['obfs-password'] ?? $q['obfs_password'] ?? ''),
            ];
        }

        return $outbound;
    }

    /** @return array<string, mixed> */
    private function parseSocks(string $url): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        $port = (int) ($parts['port'] ?? 1080);
        if ($host === '' || $port < 1) {
            throw ValidationException::withMessages(['share_url' => ['Некорректная socks:// ссылка']]);
        }
        $outbound = [
            'type' => 'socks',
            'server' => $host,
            'server_port' => $port,
        ];
        if (! empty($parts['user'])) {
            $outbound['username'] = rawurldecode($parts['user']);
        }
        if (isset($parts['pass'])) {
            $outbound['password'] = rawurldecode($parts['pass']);
        }

        return $outbound;
    }
}
