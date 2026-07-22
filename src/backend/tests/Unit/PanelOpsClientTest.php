<?php

namespace Tests\Unit;

use App\Services\Docker\PanelOpsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PanelOpsClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('PANEL_OPS_URL=http://panel-ops:8090');
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_URL'] = 'http://panel-ops:8090';
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';
    }

    public function test_recreate_caddy_posts_to_panel_ops(): void
    {
        Http::fake([
            'panel-ops:8090/ops/caddy/recreate' => Http::response(['ok' => true], 200),
        ]);

        $client = new PanelOpsClient;
        $client->recreateCaddy();

        Http::assertSent(function ($request) {
            return $request->url() === 'http://panel-ops:8090/ops/caddy/recreate'
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_recreate_caddy_throws_on_error_response(): void
    {
        Http::fake([
            'panel-ops:8090/ops/caddy/recreate' => Http::response(['ok' => false, 'error' => 'boom'], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        (new PanelOpsClient)->recreateCaddy();
    }
}
