<?php

namespace App\Services\Resolver;

/**
 * Heuristic TSPU / DPI probe for proxy outbounds (esp. VLESS+Reality).
 *
 * Signature we flag: outbound internet OK, TCP to node:port OK, but TLS ClientHello
 * gets no reply (silent blackhole) — typical ТСПУ DPI behavior on Reality/443.
 */
class TspuProbe
{
    /**
     * @param  array<string, mixed>  $outbound
     * @return array{
     *   status: string,
     *   tspu_likely: bool,
     *   detail: string,
     *   control_ok: bool,
     *   tcp_ok: bool,
     *   tls_response: bool,
     *   proxy_ok: bool,
     *   block_step: ?string,
     *   chain: list<array{id: string, label: string, ok: bool|null, note: string}>,
     *   server: ?string,
     *   ip: ?string,
     *   port: ?int,
     *   sni: ?string
     * }
     */
    public function probe(array $outbound, bool $proxyOk = false): array
    {
        $server = isset($outbound['server']) && is_string($outbound['server']) ? $outbound['server'] : null;
        $port = isset($outbound['server_port']) ? (int) $outbound['server_port'] : 0;
        $sni = (string) data_get($outbound, 'tls.server_name', $server ?? '');
        $isReality = (bool) data_get($outbound, 'tls.reality.enabled', false)
            || filled(data_get($outbound, 'tls.reality.public_key'));

        if ($server === null || $port < 1) {
            return $this->pack(
                status: 'skipped',
                tspuLikely: false,
                detail: 'Нет server/port в outbound',
                controlOk: false,
                tcpOk: false,
                tlsResponse: false,
                proxyOk: false,
                blockStep: 'config',
                server: null,
                ip: null,
                port: null,
                sni: null,
            );
        }

        if ($proxyOk) {
            return $this->pack(
                status: 'ok',
                tspuLikely: false,
                detail: 'Прокси отвечает — блокировки ТСПУ не видно',
                controlOk: true,
                tcpOk: true,
                tlsResponse: true,
                proxyOk: true,
                blockStep: null,
                server: $server,
                ip: null,
                port: $port,
                sni: $sni !== '' ? $sni : $server,
            );
        }

        $controlOk = $this->controlInternetOk();
        $ip = $this->resolveIpv4($server) ?? (filter_var($server, FILTER_VALIDATE_IP) ? $server : null);
        if ($ip === null) {
            return $this->pack(
                status: 'uncertain',
                tspuLikely: false,
                detail: "Не удалось резолвить {$server}",
                controlOk: $controlOk,
                tcpOk: false,
                tlsResponse: false,
                proxyOk: false,
                blockStep: 'dns',
                server: $server,
                ip: null,
                port: $port,
                sni: $sni !== '' ? $sni : $server,
            );
        }

        $tcp = $this->tcpConnect($ip, $port, 3.0);
        if (! $tcp['ok']) {
            return $this->pack(
                status: 'tcp_fail',
                tspuLikely: false,
                detail: $controlOk
                    ? "Обрыв на TCP: до {$server} ({$ip}:{$port}) не достучались — хост/фаервол/блок IP"
                    : "Обрыв на TCP до {$server} ({$ip}:{$port}); исходящий интернет с GUI-сервера тоже плохой",
                controlOk: $controlOk,
                tcpOk: false,
                tlsResponse: false,
                proxyOk: false,
                blockStep: 'tcp',
                server: $server,
                ip: $ip,
                port: $port,
                sni: $sni !== '' ? $sni : $server,
            );
        }

        $tls = $this->tlsClientHelloProbe($ip, $port, $sni !== '' ? $sni : $server, 4.0);
        if ($tls['response']) {
            return $this->pack(
                status: 'tls_ok_proxy_fail',
                tspuLikely: false,
                detail: $isReality
                    ? "Обрыв на VLESS: TLS до {$ip}:{$port} отвечает, Reality/VLESS не поднялся — uuid/pbk/sid/flow/SNI"
                    : "Обрыв на прокси: TCP/TLS до {$ip}:{$port} живы, outbound не отвечает",
                controlOk: $controlOk,
                tcpOk: true,
                tlsResponse: true,
                proxyOk: false,
                blockStep: 'proxy',
                server: $server,
                ip: $ip,
                port: $port,
                sni: $sni !== '' ? $sni : $server,
            );
        }

        if ($controlOk) {
            return $this->pack(
                status: 'tspu_likely',
                tspuLikely: true,
                detail: $isReality
                    ? "Обрыв на TLS/Reality: TCP до {$ip}:{$port} OK, ClientHello без ответа — типичный DPI ТСПУ"
                    : "Обрыв на TLS: TCP до {$ip}:{$port} OK, ClientHello без ответа — похоже на ТСПУ/DPI",
                controlOk: true,
                tcpOk: true,
                tlsResponse: false,
                proxyOk: false,
                blockStep: 'tls',
                server: $server,
                ip: $ip,
                port: $port,
                sni: $sni !== '' ? $sni : $server,
            );
        }

        return $this->pack(
            status: 'uncertain',
            tspuLikely: false,
            detail: "TCP до {$ip}:{$port} OK, TLS молчит; контроль интернета с GUI-сервера тоже плохой",
            controlOk: false,
            tcpOk: true,
            tlsResponse: false,
            proxyOk: false,
            blockStep: 'tls',
            server: $server,
            ip: $ip,
            port: $port,
            sni: $sni !== '' ? $sni : $server,
        );
    }

    /**
     * @return array{
     *   status: string,
     *   tspu_likely: bool,
     *   detail: string,
     *   control_ok: bool,
     *   tcp_ok: bool,
     *   tls_response: bool,
     *   proxy_ok: bool,
     *   block_step: ?string,
     *   chain: list<array{id: string, label: string, ok: bool|null, note: string}>,
     *   server: ?string,
     *   ip: ?string,
     *   port: ?int,
     *   sni: ?string
     * }
     */
    private function pack(
        string $status,
        bool $tspuLikely,
        string $detail,
        bool $controlOk,
        bool $tcpOk,
        bool $tlsResponse,
        bool $proxyOk,
        ?string $blockStep,
        ?string $server,
        ?string $ip,
        ?int $port,
        ?string $sni,
    ): array {
        $endpoint = ($ip ?: $server ?: '?').($port ? ':'.$port : '');

        $chain = [
            [
                'id' => 'control',
                'label' => 'Интернет',
                'ok' => $status === 'skipped' ? null : $controlOk,
                'note' => $controlOk ? 'GUI → 1.1.1.1 OK' : 'GUI без исходящего интернета',
            ],
            [
                'id' => 'dns',
                'label' => 'DNS',
                'ok' => $status === 'skipped' ? null : ($ip !== null),
                'note' => $ip ? (($server && $server !== $ip) ? "{$server} → {$ip}" : $ip) : 'резолв не удался',
            ],
            [
                'id' => 'tcp',
                'label' => 'TCP',
                'ok' => $status === 'skipped' || $ip === null ? null : $tcpOk,
                'note' => $tcpOk ? "SYN/ACK {$endpoint}" : "нет TCP до {$endpoint}",
            ],
            [
                'id' => 'tls',
                'label' => 'TLS',
                'ok' => (! $tcpOk || $status === 'skipped') ? null : $tlsResponse,
                'note' => $tlsResponse
                    ? 'ответ на ClientHello'
                    : ($tcpOk ? 'ClientHello без ответа'.($tspuLikely ? ' ← ТСПУ' : '') : 'не проверяли'),
            ],
            [
                'id' => 'proxy',
                'label' => 'VLESS',
                'ok' => $status === 'ok' ? true : (($tcpOk && $tlsResponse) || $status === 'tls_ok_proxy_fail' ? $proxyOk : null),
                'note' => $proxyOk
                    ? 'delay OK'
                    : ($tlsResponse ? 'прокси не ответил' : ($tspuLikely ? 'не дошли (обрыв на TLS)' : 'не проверяли / fail')),
            ],
        ];

        return [
            'status' => $status,
            'tspu_likely' => $tspuLikely,
            'detail' => $detail,
            'control_ok' => $controlOk,
            'tcp_ok' => $tcpOk,
            'tls_response' => $tlsResponse,
            'proxy_ok' => $proxyOk,
            'block_step' => $blockStep,
            'chain' => $chain,
            'server' => $server,
            'ip' => $ip,
            'port' => $port,
            'sni' => $sni,
        ];
    }

    private function controlInternetOk(): bool
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $body = @file_get_contents('https://1.1.1.1/cdn-cgi/trace', false, $ctx);

        return is_string($body) && str_contains($body, 'ip=');
    }

    private function resolveIpv4(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (! empty($rec['ip']) && filter_var($rec['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return (string) $rec['ip'];
                }
            }
        }

        $ip = gethostbyname($host);
        if (is_string($ip) && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }

        return null;
    }

    /** @return array{ok: bool} */
    private function tcpConnect(string $ip, int $port, float $timeoutSec): array
    {
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            "tcp://{$ip}:{$port}",
            $errno,
            $errstr,
            $timeoutSec,
            STREAM_CLIENT_CONNECT
        );
        if (is_resource($fp)) {
            fclose($fp);

            return ['ok' => true];
        }

        return ['ok' => false];
    }

    /** @return array{response: bool} */
    private function tlsClientHelloProbe(string $ip, int $port, string $sni, float $timeoutSec): array
    {
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            "tcp://{$ip}:{$port}",
            $errno,
            $errstr,
            $timeoutSec,
            STREAM_CLIENT_CONNECT
        );
        if (! is_resource($fp)) {
            return ['response' => false];
        }

        stream_set_timeout($fp, (int) max(1, floor($timeoutSec)), (int) (($timeoutSec - floor($timeoutSec)) * 1_000_000));

        $hello = $this->buildClientHello($sni);
        $written = @fwrite($fp, $hello);
        if ($written === false || $written < strlen($hello)) {
            fclose($fp);

            return ['response' => false];
        }

        $data = '';
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $chunk = @fread($fp, 2048);
            if (is_string($chunk) && $chunk !== '') {
                $data .= $chunk;
                break;
            }
            $meta = stream_get_meta_data($fp);
            if (! empty($meta['timed_out']) || feof($fp)) {
                break;
            }
            usleep(50_000);
        }
        fclose($fp);

        return ['response' => $data !== ''];
    }

    private function buildClientHello(string $sni): string
    {
        $sni = $sni !== '' ? $sni : 'example.com';

        $random = random_bytes(32);
        $sessionId = '';
        $cipherSuites = hex2bin('0004130113021303c02bc02fc02cc030cca9cca8c013c014009c009d002f0035') ?: '';
        $compression = "\x01\x00";

        $sniHost = $sni;
        $sniListEntry = "\x00".pack('n', strlen($sniHost)).$sniHost;
        $sniList = pack('n', strlen($sniListEntry)).$sniListEntry;
        $sniExt = "\x00\x00".pack('n', strlen($sniList)).$sniList;

        $ecPoint = "\x00\x0b\x00\x02\x01\x00";
        $supportedGroups = "\x00\x0a\x00\x08\x00\x06\x00\x1d\x00\x17\x00\x18";
        $signatureAlgs = "\x00\x0d\x00\x12\x00\x10\x04\x03\x08\x04\x04\x01\x05\x03\x08\x05\x05\x01\x08\x06\x06\x01";
        $supportedVersions = "\x00\x2b\x00\x03\x02\x03\x03";

        $extensions = $sniExt.$ecPoint.$supportedGroups.$signatureAlgs.$supportedVersions;

        $body = "\x03\x03".$random
            .chr(strlen($sessionId)).$sessionId
            .pack('n', strlen($cipherSuites)).$cipherSuites
            .$compression
            .pack('n', strlen($extensions)).$extensions;

        $handshake = "\x01".substr(pack('N', strlen($body)), 1).$body;

        return "\x16\x03\x01".pack('n', strlen($handshake)).$handshake;
    }
}
