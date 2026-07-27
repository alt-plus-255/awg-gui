<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;
use App\Services\Docker\DockerRuntime;
use PHPUnit\Framework\TestCase;

class AmneziaWgObfuscationTest extends TestCase
{
    private function service(): AmneziaWgService
    {
        $docker = $this->createMock(DockerRuntime::class);

        return new AmneziaWgService($docker, new AwgVersionRegistry);
    }

    public function test_needs_obfuscation_params_when_fields_are_empty(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'protocol_version' => '2.0',
            'jc' => '',
            'jmin' => '',
            'jmax' => '',
            's1' => '0',
            's2' => '0',
            's3' => '0',
            's4' => '0',
            'h1' => '1',
            'h2' => '2',
            'h3' => '3',
            'h4' => '4',
        ]);

        $this->assertTrue($service->needsObfuscationParams($config));
    }

    public function test_needs_obfuscation_params_for_factory_defaults(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'protocol_version' => '2.0',
            'jc' => '4',
            'jmin' => '64',
            'jmax' => '80',
            's1' => '0',
            's2' => '0',
            's3' => '0',
            's4' => '0',
            'h1' => '1',
            'h2' => '2',
            'h3' => '3',
            'h4' => '4',
        ]);

        $this->assertTrue($service->needsObfuscationParams($config));
    }

    public function test_generate_junk_params_match_documented_ranges_for_v2(): void
    {
        $service = $this->service();

        for ($i = 0; $i < 20; $i++) {
            $junk = $service->generateJunkParams('2.0');

            $this->assertGreaterThanOrEqual(1, (int) $junk['jc']);
            $this->assertLessThanOrEqual(10, (int) $junk['jc']);

            $jmin = (int) $junk['jmin'];
            $jmax = (int) $junk['jmax'];
            $this->assertGreaterThanOrEqual(64, $jmin);
            $this->assertLessThanOrEqual(1023, $jmin);
            $this->assertGreaterThan($jmin, $jmax);
            $this->assertLessThanOrEqual(1024, $jmax);

            $this->assertGreaterThanOrEqual(0, (int) $junk['s1']);
            $this->assertLessThanOrEqual(64, (int) $junk['s1']);
            $this->assertGreaterThanOrEqual(0, (int) $junk['s2']);
            $this->assertLessThanOrEqual(64, (int) $junk['s2']);
            $this->assertNotSame((int) $junk['s1'] + 56, (int) $junk['s2']);
            $this->assertGreaterThanOrEqual(0, (int) $junk['s3']);
            $this->assertLessThanOrEqual(64, (int) $junk['s3']);
            $this->assertGreaterThanOrEqual(0, (int) $junk['s4']);
            $this->assertLessThanOrEqual(32, (int) $junk['s4']);

            $headers = [(int) $junk['h1'], (int) $junk['h2'], (int) $junk['h3'], (int) $junk['h4']];
            $this->assertCount(4, array_unique($headers));
            foreach ($headers as $header) {
                $this->assertGreaterThanOrEqual(1, $header);
                $this->assertLessThanOrEqual(2147483647, $header);
            }
        }
    }

    public function test_generate_junk_params_for_v10_zeros_s3_s4(): void
    {
        $junk = $this->service()->generateJunkParams('1.0');

        $this->assertSame('0', $junk['s3']);
        $this->assertSame('0', $junk['s4']);
        $this->assertSame('', $junk['i1']);
        $this->assertNotSame('', $junk['jc']);
    }

    public function test_default_generate_junk_uses_latest_version_fields(): void
    {
        $junk = $this->service()->generateJunkParams();

        // latest is 2.0 → S3/S4 present and numeric
        $this->assertArrayHasKey('s3', $junk);
        $this->assertArrayHasKey('s4', $junk);
        $this->assertIsNumeric($junk['s3']);
        $this->assertIsNumeric($junk['s4']);
    }

    public function test_client_import_label_peer_name_style(): void
    {
        $config = new AwgConfig([
            'protocol_version' => '1.5',
        ]);
        $membership = new AwgConfigPeer;
        $membership->setRelation('config', $config);
        $membership->setRelation('client', new VpnClient(['name' => 'alice']));

        $label = $this->service()->clientImportLabel(
            $membership,
            'vpn.example.com',
            AmneziaWgService::CLIENT_IMPORT_NAME_PEER
        );

        $this->assertSame('awg-alice', $label);
        $this->assertSame(
            'awg-alice.conf',
            $this->service()->clientImportFilename($membership, 'vpn.example.com', AmneziaWgService::CLIENT_IMPORT_NAME_PEER)
        );
    }

    public function test_client_import_label_version_host_style(): void
    {
        $config = new AwgConfig([
            'protocol_version' => '1.5',
        ]);
        $membership = new AwgConfigPeer;
        $membership->setRelation('config', $config);
        $membership->setRelation('client', new VpnClient(['name' => 'alice']));

        $label = $this->service()->clientImportLabel(
            $membership,
            'vpn.example.com',
            AmneziaWgService::CLIENT_IMPORT_NAME_VERSION_HOST
        );

        $this->assertSame('AWG-v1.5-vpn.example.com', $label);
        $this->assertSame(
            'AWG-v1.5-vpn.example.com.conf',
            $this->service()->clientImportFilename($membership, 'vpn.example.com', AmneziaWgService::CLIENT_IMPORT_NAME_VERSION_HOST)
        );
    }
}
