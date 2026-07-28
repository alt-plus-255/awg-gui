<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\ResolverConnection;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\Resolver\AmneziaWgClientConfBuilder;
use App\Services\Resolver\AmneziaWgConfParser;
use App\Services\Resolver\ClashApiClient;
use App\Services\Resolver\MergedRulesetWriter;
use App\Services\Resolver\ResolverDiagnostics;
use App\Services\Resolver\ResolverFileHelper;
use App\Services\Resolver\ResolverListsService;
use App\Services\Resolver\ResolverMarkScripts;
use App\Services\Resolver\ResolverPaths;
use App\Services\Resolver\ResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResolverServiceApplyTest extends TestCase
{
    use RefreshDatabase;

    private string $awgDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awgDir = sys_get_temp_dir().'/awg-gui-apply-test-'.uniqid('', true);
        mkdir($this->awgDir.'/rulesets', 0755, true);
        putenv('AWG_CONFIG_DIR='.$this->awgDir);
        $_ENV['AWG_CONFIG_DIR'] = $this->awgDir;
        $_SERVER['AWG_CONFIG_DIR'] = $this->awgDir;
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->awgDir);
        parent::tearDown();
    }

    public function test_apply_refreshes_live_marks_when_mark_script_changes(): void
    {
        $conn = ResolverConnection::query()->create([
            'name' => 'Exit',
            'kind' => ResolverConnection::KIND_PROXY,
            'config_type' => 'json',
            'enabled' => true,
            'outbound' => [
                'type' => 'socks',
                'server' => '127.0.0.1',
                'server_port' => 1080,
            ],
        ]);

        AwgConfig::query()->create([
            'name' => 'Resolver Test',
            'type' => 'server',
            'iface' => 'awg0',
            'listen_port' => 51820,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1',
            'server_private_key' => 'priv',
            'server_public_key' => 'pub',
            'enabled' => true,
            'resolver_enabled' => true,
            'community_lists' => [],
            'user_domains' => [],
            'user_subnets' => [],
            'connection_id' => $conn->id,
        ]);

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('configDir')->andReturn($this->awgDir);

        $mergedRulesets = Mockery::mock(MergedRulesetWriter::class);
        $mergedRulesets->applyProxyCidrsChanged = false;
        $mergedRulesets->applyMergedChanged = false;
        $mergedRulesets->shouldReceive('resetChangeFlags')->once()->andReturnUsing(function () use ($mergedRulesets): void {
            $mergedRulesets->applyProxyCidrsChanged = false;
            $mergedRulesets->applyMergedChanged = false;
        });

        $service = Mockery::mock(ResolverService::class, [
            $awg,
            Mockery::mock(DockerRuntime::class),
            new ResolverPaths($awg),
            new ResolverFileHelper,
            $mergedRulesets,
            Mockery::mock(ResolverMarkScripts::class),
            Mockery::mock(ClashApiClient::class),
            Mockery::mock(ResolverDiagnostics::class),
            Mockery::mock(ResolverListsService::class),
            Mockery::mock(AmneziaWgConfParser::class),
            Mockery::mock(AmneziaWgClientConfBuilder::class),
        ])->makePartial();

        $service->shouldReceive('refreshSubscriptionConnections')->never();
        $service->shouldReceive('buildSingBoxConfig')->once()->andReturn([
            'dns' => ['servers' => [], 'rules' => [], 'final' => 'remote'],
            'inbounds' => [],
            'outbounds' => [],
            'route' => ['rules' => [], 'rule_set' => [], 'final' => 'direct'],
        ]);
        $service->shouldReceive('writeFileIfChanged')->twice()->andReturn(false);
        $service->shouldReceive('ensureResolverMarkScripts')->once()->andReturn(true);
        $service->shouldReceive('refreshResolverMarksOnIfaces')->once()->with(['awg0']);
        $service->shouldReceive('reloadSingBox')->never();
        $service->shouldReceive('waitForClashApi')->never();

        $service->apply(refreshSubscriptions: false);

        $this->assertFileExists($this->awgDir.'/resolver-status.json');
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
