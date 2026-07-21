<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Services\AmneziaWg\AmneziaWgService;
use PHPUnit\Framework\TestCase;

class AmneziaWgKeysTest extends TestCase
{
    public function test_generate_key_pair_returns_wireguard_length_base64(): void
    {
        $service = new AmneziaWgService;
        $keys = $service->generateKeyPair();

        $this->assertSame(44, strlen($keys['private']));
        $this->assertSame(44, strlen($keys['public']));
        $this->assertNotSame($keys['private'], $keys['public']);
    }

    public function test_needs_server_and_peer_keys_detect_empty_values(): void
    {
        $service = new AmneziaWgService;

        $config = new AwgConfig([
            'server_private_key' => '',
            'server_public_key' => '',
        ]);
        $this->assertTrue($service->needsServerKeys($config));

        $keys = $service->generateKeyPair();
        $config->server_private_key = $keys['private'];
        $config->server_public_key = $keys['public'];
        $this->assertFalse($service->needsServerKeys($config));

        $membership = new AwgConfigPeer([
            'private_key' => '',
            'public_key' => '',
        ]);
        $this->assertTrue($service->needsPeerKeys($membership));

        $peerKeys = $service->generateKeyPair();
        $membership->private_key = $peerKeys['private'];
        $membership->public_key = $peerKeys['public'];
        $this->assertFalse($service->needsPeerKeys($membership));
    }
}
