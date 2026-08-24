<?php

namespace Tests\Unit;

use App\Services\AmneziaWg\Versions\AwgVersionRegistry;
use App\Services\Resolver\AmneziaWgClientConfBuilder;
use App\Services\Resolver\AmneziaWgConfParser;
use PHPUnit\Framework\TestCase;

class AmneziaWgConfParserTest extends TestCase
{
    private function sampleConf(bool $withAwg2 = true): string
    {
        $lines = [
            '[Interface]',
            'PrivateKey = YAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'Address = 10.8.1.2/32',
            'DNS = 1.1.1.1',
            'Jc = 4',
            'Jmin = 64',
            'Jmax = 80',
            'S1 = 10',
            'S2 = 20',
            'H1 = 1',
            'H2 = 2',
            'H3 = 3',
            'H4 = 4',
        ];
        if ($withAwg2) {
            $lines[] = 'S3 = 30';
            $lines[] = 'S4 = 15';
            $lines[] = 'I1 = <b 0x01>';
        }
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = ZBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';
        $lines[] = 'Endpoint = vpn.example.com:51820';
        $lines[] = 'AllowedIPs = 0.0.0.0/0, ::/0';
        $lines[] = 'PersistentKeepalive = 25';

        return implode("\n", $lines)."\n";
    }

    public function test_parse_extracts_interface_peer_and_junk(): void
    {
        $parsed = (new AmneziaWgConfParser)->parse($this->sampleConf());

        $this->assertSame('YAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', $parsed['private_key']);
        $this->assertSame('10.8.1.2/32', $parsed['address']);
        $this->assertSame('1.1.1.1', $parsed['dns']);
        $this->assertSame('4', $parsed['jc']);
        $this->assertSame('30', $parsed['s3']);
        $this->assertSame('<b 0x01>', $parsed['i1']);
        $this->assertSame('ZBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=', $parsed['peer']['public_key']);
        $this->assertSame('vpn.example.com:51820', $parsed['peer']['endpoint']);
        $this->assertSame('25', $parsed['peer']['persistent_keepalive']);
    }

    public function test_client_builder_strips_unsupported_params_for_v10(): void
    {
        $parser = new AmneziaWgConfParser;
        $builder = new AmneziaWgClientConfBuilder(new AwgVersionRegistry);
        $parsed = $parser->parse($this->sampleConf(true));
        $conf = $builder->build($parsed, '1.0');

        $this->assertStringContainsString('Table = off', $conf);
        $this->assertStringContainsString('Jc = 4', $conf);
        $this->assertStringContainsString('S1 = 10', $conf);
        $this->assertStringNotContainsString('S3 =', $conf);
        $this->assertStringNotContainsString('S4 =', $conf);
        $this->assertStringNotContainsString('I1 =', $conf);
        $this->assertStringContainsString('Endpoint = vpn.example.com:51820', $conf);
    }

    public function test_client_builder_keeps_awg2_params(): void
    {
        $parser = new AmneziaWgConfParser;
        $builder = new AmneziaWgClientConfBuilder(new AwgVersionRegistry);
        $parsed = $parser->parse($this->sampleConf(true));
        $conf = $builder->build($parsed, '2.0');

        $this->assertStringContainsString('S3 = 30', $conf);
        $this->assertStringContainsString('S4 = 15', $conf);
        $this->assertStringContainsString('I1 = <b 0x01>', $conf);
    }

    public function test_outbound_and_iface_naming(): void
    {
        $builder = new AmneziaWgClientConfBuilder(new AwgVersionRegistry);
        $this->assertSame('awgc12', $builder->ifaceName(12));
        $this->assertSame([
            'type' => 'direct',
            'bind_interface' => 'awgc12',
        ], $builder->outboundFor(12));
    }
}
