<?php

namespace App\Services\Resolver;

use Illuminate\Support\Facades\Process;

class TcpReachabilityProbe
{
    /**
     * @param  array<string, mixed>  $outbound
     */
    public function isReachable(array $outbound, float $timeoutSec = 2.0): bool
    {
        $server = isset($outbound['server']) && is_string($outbound['server']) ? $outbound['server'] : null;
        $port = isset($outbound['server_port']) ? (int) $outbound['server_port'] : 0;
        if ($server === null || $port < 1) {
            return true;
        }

        $ip = $this->resolveIpv4($server);
        if ($ip === null) {
            return false;
        }

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

            return true;
        }

        return false;
    }

    /**
     * Parallel TCP checks; invokes $onResult($key, $reachable) as each completes.
     *
     * @param  array<string, array<string, mixed>>  $keyToOutbound
     * @param  callable(string, bool): void  $onResult
     * @return array<string, bool>
     */
    public function checkManyStreaming(
        array $keyToOutbound,
        float $timeoutSec,
        callable $onResult,
        ?callable $shouldCancel = null,
    ): array {
        $out = [];
        $running = [];

        foreach ($keyToOutbound as $key => $outbound) {
            $server = isset($outbound['server']) && is_string($outbound['server']) ? $outbound['server'] : null;
            $port = isset($outbound['server_port']) ? (int) $outbound['server_port'] : 0;
            if ($server === null || $port < 1) {
                $out[$key] = true;
                $onResult($key, true);

                continue;
            }

            $script = sprintf(
                '$h=%s;$p=%d;$t=%f;$e=0;$s="";$f=@stream_socket_client("tcp://".$h.":".$p,$e,$s,$t);exit($f?0:1);',
                var_export($server, true),
                $port,
                $timeoutSec
            );
            $running[$key] = Process::timeout((int) ceil($timeoutSec) + 3)->start(['php', '-r', $script]);
        }

        while ($running !== []) {
            if ($shouldCancel !== null && $shouldCancel()) {
                foreach ($running as $proc) {
                    if ($proc->running()) {
                        $proc->signal(SIGTERM);
                    }
                }

                return $out;
            }

            foreach ($running as $key => $proc) {
                if ($proc->running()) {
                    continue;
                }

                $reachable = $proc->wait()->successful();
                $out[$key] = $reachable;
                $onResult($key, $reachable);
                unset($running[$key]);
            }

            if ($running !== []) {
                usleep(40_000);
            }
        }

        return $out;
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
}
