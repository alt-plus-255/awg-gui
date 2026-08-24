<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Services\AmneziaWg\Versions\AwgVersion10Profile;
use App\Services\AmneziaWg\Versions\AwgVersion15Profile;
use App\Services\AmneziaWg\Versions\AwgVersion20Profile;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;
use PHPUnit\Framework\TestCase;

class AwgVersionRegistryTest extends TestCase
{
    public function test_latest_is_newest_registered_version(): void
    {
        $registry = new AwgVersionRegistry;

        $this->assertSame(['1.0', '1.5', '2.0'], $registry->ids());
        $this->assertSame('2.0', $registry->latest());
        $this->assertSame('2.0', $registry->latestProfile()->id());
    }

    public function test_profiles_expose_expected_params_and_vpn_uri_versions(): void
    {
        $v10 = new AwgVersion10Profile;
        $v15 = new AwgVersion15Profile;
        $v20 = new AwgVersion20Profile;

        $this->assertSame('1', $v10->vpnUriProtocolVersion());
        $this->assertSame('1', $v15->vpnUriProtocolVersion());
        $this->assertSame('2', $v20->vpnUriProtocolVersion());

        $this->assertSame(
            ['jc', 'jmin', 'jmax', 's1', 's2', 'h1', 'h2', 'h3', 'h4'],
            $v10->supportedParams()
        );
        $this->assertContains('i1', $v15->supportedParams());
        $this->assertNotContains('s3', $v15->supportedParams());
        $this->assertContains('s3', $v20->supportedParams());
        $this->assertContains('s4', $v20->supportedParams());
        $this->assertContains('i1', $v20->supportedParams());
    }

    public function test_junk_generation_clears_unsupported_fields(): void
    {
        $v10 = new AwgVersion10Profile;
        $junk = $v10->generateJunkParams();

        $this->assertSame('0', $junk['s3']);
        $this->assertSame('0', $junk['s4']);
        $this->assertSame('', $junk['i1']);
        $this->assertNotSame('', $junk['jc']);
        $this->assertNotSame('0', $junk['jmin']);
    }

    public function test_conf_lines_omit_unsupported_keys(): void
    {
        $config = new AwgConfig([
            'jc' => '4',
            'jmin' => '64',
            'jmax' => '80',
            's1' => '1',
            's2' => '2',
            's3' => '3',
            's4' => '4',
            'h1' => '11',
            'h2' => '12',
            'h3' => '13',
            'h4' => '14',
            'i1' => '<b 0x01>',
        ]);

        $lines10 = implode("\n", (new AwgVersion10Profile)->confObfuscationLines($config));
        $this->assertStringContainsString('Jc = 4', $lines10);
        $this->assertStringNotContainsString('S3 =', $lines10);
        $this->assertStringNotContainsString('I1 =', $lines10);

        $lines15 = implode("\n", (new AwgVersion15Profile)->confObfuscationLines($config));
        $this->assertStringContainsString('I1 = <b 0x01>', $lines15);
        $this->assertStringNotContainsString('S3 =', $lines15);

        $lines20 = implode("\n", (new AwgVersion20Profile)->confObfuscationLines($config));
        $this->assertStringContainsString('S3 = 3', $lines20);
        $this->assertStringContainsString('S4 = 4', $lines20);
        $this->assertStringContainsString('I1 = <b 0x01>', $lines20);
    }

    public function test_vpn_uri_inner_params_follow_version(): void
    {
        $config = new AwgConfig([
            'jc' => '4',
            'jmin' => '64',
            'jmax' => '80',
            's1' => '1',
            's2' => '2',
            's3' => '3',
            's4' => '4',
            'h1' => '11',
            'h2' => '12',
            'h3' => '13',
            'h4' => '14',
            'i1' => '<b 0x01>',
        ]);

        $inner10 = (new AwgVersion10Profile)->vpnUriInnerParams($config);
        $this->assertArrayNotHasKey('S3', $inner10);
        $this->assertArrayNotHasKey('I1', $inner10);

        $inner20 = (new AwgVersion20Profile)->vpnUriInnerParams($config);
        $this->assertSame('3', $inner20['S3']);
        $this->assertSame('<b 0x01>', $inner20['I1']);
    }
}
