<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Services\AmneziaWg\AmneziaWgService;
use PHPUnit\Framework\TestCase;

class AmneziaWgObfuscationTest extends TestCase
{
    public function test_needs_obfuscation_params_when_fields_are_empty(): void
    {
        $service = new AmneziaWgService;
        $config = new AwgConfig([
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
        $service = new AmneziaWgService;
        $config = new AwgConfig([
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

    public function test_generate_junk_params_match_documented_ranges(): void
    {
        $service = new AmneziaWgService;

        for ($i = 0; $i < 20; $i++) {
            $junk = $service->generateJunkParams();

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
}
