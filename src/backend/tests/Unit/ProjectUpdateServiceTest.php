<?php

namespace Tests\Unit;

use App\Services\Docker\PanelOpsClient;
use App\Services\System\ProjectUpdateService;
use RuntimeException;
use Tests\TestCase;

class ProjectUpdateServiceTest extends TestCase
{
    public function test_status_reads_version_from_install_state(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();

        file_put_contents($hostDir.'/install.state', implode("\n", [
            'completed_at=2026-07-27T09:00:00Z',
            'bundle_version=1.2.3',
        ]));

        $service = new ProjectUpdateService(new PanelOpsClient, $hostDir, $composeDir);

        $status = $service->status();

        $this->assertSame('1.2.3', $status['current_version']);
        $this->assertSame('install_state', $status['version_source']);
        $this->assertSame('idle', $status['status']);
        $this->assertFalse($status['running']);
    }

    public function test_status_falls_back_to_compose_image_tag(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();

        file_put_contents($composeDir.'/docker-compose.yml', "services:\n  app:\n    image: awggui-app:9.9.9\n");

        $service = new ProjectUpdateService(new PanelOpsClient, $hostDir, $composeDir);

        $status = $service->status();

        $this->assertSame('9.9.9', $status['current_version']);
        $this->assertSame('compose', $status['version_source']);
    }

    public function test_start_rejects_when_update_is_already_running(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();

        file_put_contents($hostDir.'/install.state', implode("\n", [
            'completed_at=2026-07-27T09:00:00Z',
            'bundle_version=1.0.0',
        ]));

        file_put_contents($hostDir.'/update.state', json_encode([
            'pid' => getmypid(),
            'status' => 'running',
            'message' => 'busy',
        ], JSON_PRETTY_PRINT));

        \Illuminate\Support\Facades\Http::fake([
            'api.github.com/repos/*' => \Illuminate\Support\Facades\Http::response([
                'tag_name' => 'v2.0.0',
            ], 200),
        ]);

        $client = $this->createMock(PanelOpsClient::class);
        $client->expects($this->never())->method('startUpdate');

        $service = new ProjectUpdateService($client, $hostDir, $composeDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_already_running');

        $service->start();
    }

    public function test_start_rejects_when_already_on_latest_version(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();

        file_put_contents($hostDir.'/install.state', implode("\n", [
            'completed_at=2026-07-27T09:00:00Z',
            'bundle_version=2.0.0',
        ]));

        \Illuminate\Support\Facades\Http::fake([
            'api.github.com/repos/*' => \Illuminate\Support\Facades\Http::response([
                'tag_name' => 'v2.0.0',
            ], 200),
        ]);

        $client = $this->createMock(PanelOpsClient::class);
        $client->expects($this->never())->method('startUpdate');

        $service = new ProjectUpdateService($client, $hostDir, $composeDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_not_available');

        $service->start();
    }

    public function test_check_for_updates_reads_latest_release_from_github(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();

        file_put_contents($hostDir.'/install.state', implode("\n", [
            'bundle_version=1.0.0',
        ]));

        \Illuminate\Support\Facades\Http::fake([
            'api.github.com/repos/*' => \Illuminate\Support\Facades\Http::response([
                'tag_name' => 'v2.0.0',
            ], 200),
        ]);

        $service = new ProjectUpdateService(new PanelOpsClient, $hostDir, $composeDir);
        $status = $service->checkForUpdates();

        $this->assertSame('2.0.0', $status['latest_version']);
        $this->assertTrue($status['update_available']);
        $this->assertNotNull($status['release_checked_at']);
    }

    public function test_check_for_updates_marks_unavailable_when_versions_match(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();

        file_put_contents($hostDir.'/install.state', implode("\n", [
            'bundle_version=2.0.0',
        ]));

        \Illuminate\Support\Facades\Http::fake([
            'api.github.com/repos/*' => \Illuminate\Support\Facades\Http::response([
                'tag_name' => 'v2.0.0',
            ], 200),
        ]);

        $service = new ProjectUpdateService(new PanelOpsClient, $hostDir, $composeDir);
        $status = $service->checkForUpdates();

        $this->assertSame('2.0.0', $status['latest_version']);
        $this->assertFalse($status['update_available']);
        $this->assertTrue($status['can_update']);
    }

    public function test_start_uses_latest_release_when_update_is_available(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();

        file_put_contents($hostDir.'/install.state', implode("\n", [
            'completed_at=2026-07-27T09:00:00Z',
            'bundle_version=1.0.0',
        ]));

        \Illuminate\Support\Facades\Http::fake([
            'api.github.com/repos/*' => \Illuminate\Support\Facades\Http::response([
                'tag_name' => 'v2.0.0',
            ], 200),
        ]);

        $client = $this->createMock(PanelOpsClient::class);
        $client->expects($this->once())
            ->method('startUpdate')
            ->with('2.0.0')
            ->willReturn(['ok' => true]);

        $service = new ProjectUpdateService($client, $hostDir, $composeDir);
        $status = $service->start();

        $this->assertTrue($status['running']);
        $this->assertSame('2.0.0', $status['target_version']);
    }

    public function test_read_log_tail_returns_last_lines(): void
    {
        putenv('PANEL_OPS_TOKEN=test-token');
        $_ENV['PANEL_OPS_TOKEN'] = 'test-token';

        $hostDir = $this->makeTempDir();
        $composeDir = $this->makeTempDir();
        file_put_contents($hostDir.'/update.log', "line1\nline2\nline3\n");

        $service = new ProjectUpdateService(new PanelOpsClient, $hostDir, $composeDir);
        $status = $service->status();

        $this->assertStringContainsString('line3', $status['log_tail']);
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir().'/awg-update-'.bin2hex(random_bytes(6));
        mkdir($path, 0777, true);

        return $path;
    }
}
