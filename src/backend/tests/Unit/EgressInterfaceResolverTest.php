<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\Resolver\EgressInterfaceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EgressInterfaceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_setting_wins_over_detection(): void
    {
        Setting::setValue(EgressInterfaceResolver::SETTING_KEY, 'ens3');

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('isContainerRunning')->andReturn(true);
        $awg->shouldReceive('containerName')->andReturn('awggui-awg');

        $docker = Mockery::mock(DockerRuntime::class);
        $docker->shouldReceive('exec')->never();

        $resolver = new EgressInterfaceResolver($awg, $docker);
        $this->assertSame('ens3', $resolver->resolve());
        $this->assertSame('ens3', $resolver->settingValue());
    }

    public function test_auto_uses_detected_default_route_iface(): void
    {
        Setting::setValue(EgressInterfaceResolver::SETTING_KEY, 'auto');

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('isContainerRunning')->andReturn(true);
        $awg->shouldReceive('containerName')->andReturn('awggui-awg');

        $result = Mockery::mock();
        $result->shouldReceive('output')->andReturn("enp0s3\n");

        $docker = Mockery::mock(DockerRuntime::class);
        $docker->shouldReceive('exec')->once()->andReturn($result);

        $resolver = new EgressInterfaceResolver($awg, $docker);
        $this->assertSame('enp0s3', $resolver->resolve());
        $this->assertSame('enp0s3', $resolver->detectDefault());
    }

    public function test_auto_falls_back_when_container_down(): void
    {
        Setting::setValue(EgressInterfaceResolver::SETTING_KEY, 'auto');

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('isContainerRunning')->andReturn(false);

        $docker = Mockery::mock(DockerRuntime::class);
        $docker->shouldReceive('exec')->never();

        $resolver = new EgressInterfaceResolver($awg, $docker);
        $this->assertSame(EgressInterfaceResolver::FALLBACK, $resolver->resolve());
        $this->assertNull($resolver->detectDefault());
    }
}
