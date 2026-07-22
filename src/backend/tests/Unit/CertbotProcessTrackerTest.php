<?php

namespace Tests\Unit;

use App\Services\AmneziaWg\CertbotProcessTracker;
use App\Services\Docker\DockerRuntime;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CertbotProcessTrackerTest extends TestCase
{
    public function test_is_running_uses_pgrep_inside_container(): void
    {
        Process::fake([
            '*' => Process::result(output: "true\n", exitCode: 0),
        ]);

        $tracker = new CertbotProcessTracker(new DockerRuntime);

        $this->assertTrue($tracker->isRunning());
    }

    public function test_exit_code_reads_challenge_file(): void
    {
        $root = sys_get_temp_dir().'/awg-certbot-test-'.uniqid('', true);
        $challenge = $root.'/certbot/challenge';
        mkdir($challenge, 0755, true);
        file_put_contents($challenge.'/exit_code', '0');

        config(['app.env' => 'testing']);
        putenv('HOST_AWG_GUI_DIR='.$root);
        $_ENV['HOST_AWG_GUI_DIR'] = $root;
        $_SERVER['HOST_AWG_GUI_DIR'] = $root;

        $tracker = new CertbotProcessTracker(new DockerRuntime);

        $this->assertSame(0, $tracker->exitCode());

        @unlink($challenge.'/exit_code');
        @rmdir($challenge);
        @rmdir($root.'/certbot');
        @rmdir($root);
    }
}
