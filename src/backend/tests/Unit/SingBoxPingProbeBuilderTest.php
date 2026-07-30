<?php

namespace Tests\Unit;

use App\Services\Resolver\ResolverService;
use App\Services\Resolver\SingBoxPingProbeBuilder;
use App\Services\Resolver\ConnectionOutboundBuilder;
use App\Services\Resolver\SingBoxOutboundParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingBoxPingProbeBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_produces_minimal_config_without_cache_file(): void
    {
        $builder = new SingBoxPingProbeBuilder(
            new ConnectionOutboundBuilder(new SingBoxOutboundParser),
        );

        $result = $builder->build();
        $config = $result['config'];

        $this->assertArrayHasKey('outbounds', $config);
        $this->assertArrayHasKey('dns', $config);
        $this->assertSame('bootstrap', $config['dns']['final']);
        $this->assertArrayHasKey('experimental', $config);
        $this->assertSame(
            ResolverService::CLASH_PROBE_API_ADDR,
            $config['experimental']['clash_api']['external_controller']
        );
        $this->assertArrayNotHasKey('inbounds', $config);
        $this->assertArrayHasKey('route', $config);
        $this->assertFalse($config['route']['auto_detect_interface']);
        $this->assertNotSame('', $config['route']['default_interface']);
        $this->assertArrayNotHasKey('exclude_interface', $config['route']);
        $this->assertFalse(isset($config['experimental']['cache_file']));

        $json = $builder->encode($config);
        $this->assertStringNotContainsString("\n  ", $json);
    }
}
