<?php

namespace Tests\Unit;

use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\Resolver\ResolverFileHelper;
use App\Services\Resolver\ResolverMarkScripts;
use App\Services\Resolver\ResolverService;
use Mockery;
use Tests\TestCase;

class ResolverMarkScriptsTest extends TestCase
{
    private string $awgDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awgDir = sys_get_temp_dir().'/awg-gui-mark-test-'.uniqid('', true);
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

    public function test_mark_script_uses_redirect_tcp_and_udp_tproxy(): void
    {
        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('configDir')->andReturn($this->awgDir);

        $scripts = new ResolverMarkScripts(
            $awg,
            Mockery::mock(DockerRuntime::class),
            new ResolverFileHelper,
        );

        $this->assertTrue($scripts->ensureResolverMarkScripts());

        $mark = (string) file_get_contents($this->awgDir.'/resolver-mark.sh');
        $this->assertStringContainsString('REJECT_QUIC="${2:-0}"', $mark);
        $this->assertStringNotContainsString('if [ "$REJECT_QUIC" = "0" ]; then', $mark);
        $this->assertStringContainsString('REDIR_PORT='.ResolverService::TPROXY_PORT, $mark);
        $this->assertStringContainsString('UDP_PORT='.ResolverService::UDP_TPROXY_PORT, $mark);
        $this->assertStringContainsString('REDIRECT --to-ports "$REDIR_PORT"', $mark);
        $this->assertStringContainsString('redir_add "$FAKEIP"', $mark);
        $this->assertStringContainsString('redir_add "$cidr"', $mark);
        $this->assertStringContainsString(
            '-d "$FAKEIP" -p udp -j TPROXY',
            $mark
        );
        $this->assertStringContainsString('--on-port "$UDP_PORT"', $mark);
        $this->assertStringContainsString('-d "$FAKEIP" -p udp -m socket -j DIVERT', $mark);
        $this->assertStringContainsString('TCPMSS --clamp-mss-to-pmtu', $mark);
        $this->assertStringContainsString(
            'while iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY',
            $mark
        );
        $this->assertStringNotContainsString(
            'iptables -t mangle -I PREROUTING 1 -i "$IFACE" -d "$FAKEIP" -p tcp -m socket -j DIVERT',
            $mark
        );
        $this->assertStringNotContainsString(
            'iptables -I FORWARD 1 -i "$IFACE" -d "$FAKEIP" -p udp -j REJECT',
            $mark
        );
        $this->assertStringNotContainsString('tproxy_add "$FAKEIP" tcp', $mark);

        $unmark = (string) file_get_contents($this->awgDir.'/resolver-unmark.sh');
        $this->assertStringContainsString('UDP_PORT='.ResolverService::UDP_TPROXY_PORT, $unmark);
        $this->assertStringContainsString('RSNAT_', $unmark);
        $this->assertStringContainsString('TCPMSS --clamp-mss-to-pmtu', $unmark);
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
