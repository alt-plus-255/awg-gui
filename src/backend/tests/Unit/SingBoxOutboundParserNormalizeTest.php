<?php

namespace Tests\Unit;

use App\Services\Resolver\SingBoxOutboundParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SingBoxOutboundParserNormalizeTest extends TestCase
{
    public function test_aliases_and_strips_removed_fields(): void
    {
        $parser = new SingBoxOutboundParser;

        $ss = $parser->normalize([
            'type' => 'ss',
            'server' => '1.2.3.4',
            'server_port' => 8388,
            'method' => 'aes-256-gcm',
            'password' => 'x',
            'sniff' => true,
        ]);
        $this->assertSame('shadowsocks', $ss['type']);
        $this->assertArrayNotHasKey('sniff', $ss);
        $this->assertArrayNotHasKey('domain_resolver', $ss);

        $named = $parser->normalize([
            'type' => 'socks',
            'server' => 'proxy.example.com',
            'server_port' => 1080,
        ]);
        $this->assertSame('bootstrap', $named['domain_resolver'] ?? null);

        $direct = $parser->normalize([
            'type' => 'direct',
            'override_address' => '1.1.1.1',
            'override_port' => 53,
        ]);
        $this->assertSame('direct', $direct['type']);
        $this->assertArrayNotHasKey('override_address', $direct);
        $this->assertArrayNotHasKey('override_port', $direct);
    }

    public function test_rejects_removed_outbound_types(): void
    {
        $parser = new SingBoxOutboundParser;

        try {
            $parser->normalize(['type' => 'wireguard', 'server' => '1.1.1.1', 'server_port' => 51820]);
            $this->fail('Expected ValidationException for wireguard');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('outbound_json', $e->errors());
        }

        try {
            $parser->normalize(['type' => 'dns']);
            $this->fail('Expected ValidationException for dns outbound');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('outbound_json', $e->errors());
        }
    }
}
