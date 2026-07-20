<?php

namespace App\Services\Resolver;

use App\Models\ResolverConnection;

class SingBoxPingProbeBuilder
{
    public function __construct(private ConnectionOutboundBuilder $outboundBuilder) {}

    /**
     * @return array{config: array<string, mixed>, outbound_count: int, truncated_subscriptions: array<int, bool>}
     */
    public function build(): array
    {
        $connections = ResolverConnection::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        $built = $this->outboundBuilder->buildForConnections($connections);

        $config = [
            'log' => [
                'level' => 'warn',
                'timestamp' => true,
            ],
            'dns' => [
                'servers' => [
                    [
                        'type' => 'udp',
                        'tag' => 'bootstrap',
                        'server' => '8.8.8.8',
                        'server_port' => 53,
                    ],
                ],
                'final' => 'bootstrap',
                'strategy' => 'ipv4_only',
            ],
            'outbounds' => $built['outbounds'],
            'experimental' => [
                'clash_api' => [
                    'external_controller' => ResolverService::CLASH_PROBE_API_ADDR,
                    'default_mode' => 'rule',
                ],
            ],
        ];

        return [
            'config' => $config,
            'outbound_count' => count($built['outbounds']),
            'truncated_subscriptions' => $built['truncated_subscriptions'],
        ];
    }

    public function encode(array $config): string
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Не удалось сериализовать sing-box-ping.json');
        }

        return $json."\n";
    }
}
