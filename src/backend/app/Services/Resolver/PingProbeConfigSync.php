<?php

namespace App\Services\Resolver;

use App\Models\ResolverConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PingProbeConfigSync
{
    public const SIZE_WARN_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private SingBoxPingProbeBuilder $builder,
        private PingProbeManager $probe,
        private ResolverPaths $paths,
        private ResolverFileHelper $files,
        private ResolverMarkScripts $markScripts,
    ) {}

    public function ensureScripts(): void
    {
        $this->markScripts->ensurePingProbeScript();
    }

    /**
     * @return array{written: bool, outbound_count: int, bytes: int}
     */
    public function rebuildAndMaybeReload(): array
    {
        $this->ensureScripts();

        $built = $this->builder->build();
        $contents = $this->builder->encode($built['config']);
        $path = $this->paths->singBoxPingConfigPath();

        $written = $this->files->atomicWriteIfChanged($path, $contents);
        $bytes = strlen($contents);

        if ($bytes > self::SIZE_WARN_BYTES) {
            Log::warning("sing-box-ping.json large: {$bytes} bytes, {$built['outbound_count']} outbounds");
        }

        if ($written) {
            $this->probe->reloadIfRunning();
        }

        return [
            'written' => $written,
            'outbound_count' => $built['outbound_count'],
            'bytes' => $bytes,
        ];
    }
}
