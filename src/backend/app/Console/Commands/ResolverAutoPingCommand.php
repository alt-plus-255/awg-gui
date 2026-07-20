<?php

namespace App\Console\Commands;

use App\Models\ResolverConnection;
use App\Services\Resolver\PingProbeConfigSync;
use App\Services\Resolver\ResolverService;
use App\Services\Resolver\SubscriptionPingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolverAutoPingCommand extends Command
{
    protected $signature = 'resolver:auto-ping';

    protected $description = 'Background ping for connections due by ping_check_interval_min';

    public function handle(
        SubscriptionPingService $ping,
        ResolverService $resolver,
        PingProbeConfigSync $probeSync,
    ): int {
        $due = ResolverConnection::query()
            ->where('enabled', true)
            ->where('ping_check_interval_min', '>', 0)
            ->orderBy('id')
            ->get()
            ->filter(fn (ResolverConnection $c) => $c->isPingCheckDue());

        foreach ($due as $conn) {
            try {
                if ($conn->isSubscription()) {
                    $nodes = is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [];
                    if ($nodes === []) {
                        continue;
                    }
                    $ping->pingNodes($conn);
                    $switch = $ping->applyBestPickIfChanged($conn->fresh());
                    $ping->syncActivePickAfterPing($conn->fresh());
                    if ($switch['switched'] ?? false) {
                        $resolver->apply(refreshSubscriptions: false);
                        $probeSync->rebuildAndMaybeReload();
                        Log::info('resolver:auto-ping switched single node', [
                            'connection_id' => $conn->id,
                            'pick' => $switch['pick']['name'] ?? null,
                        ]);
                    }
                } else {
                    $ping->pingConnection($conn);
                }
            } catch (\Throwable $e) {
                Log::warning('resolver:auto-ping failed', [
                    'connection_id' => $conn->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
