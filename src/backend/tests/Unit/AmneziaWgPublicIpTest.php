<?php

namespace Tests\Unit;

use App\Services\AmneziaWg\AmneziaWgService;
use Tests\TestCase;

class AmneziaWgPublicIpTest extends TestCase
{
    public function test_is_public_ipv4_rejects_private_and_reserved(): void
    {
        $awg = app(AmneziaWgService::class);

        $this->assertTrue($awg->isPublicIpv4('8.8.8.8'));
        $this->assertTrue($awg->isPublicIpv4('2.59.161.79'));

        $this->assertFalse($awg->isPublicIpv4('10.0.0.1'));
        $this->assertFalse($awg->isPublicIpv4('127.0.0.1'));
        $this->assertFalse($awg->isPublicIpv4('192.168.1.1'));
        $this->assertFalse($awg->isPublicIpv4('172.16.5.1'));
        $this->assertFalse($awg->isPublicIpv4('169.254.169.254'));
        $this->assertFalse($awg->isPublicIpv4('0.0.0.0'));
        $this->assertFalse($awg->isPublicIpv4('not-an-ip'));
        $this->assertFalse($awg->isPublicIpv4(''));
    }
}
