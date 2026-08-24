<?php

namespace App\Services\AmneziaWg\Versions;

use App\Models\AwgConfig;

abstract class AbstractAwgVersionProfile implements AwgVersionProfile
{
    /** @var list<string> */
    protected const BASE_PARAMS = ['jc', 'jmin', 'jmax', 's1', 's2', 'h1', 'h2', 'h3', 'h4'];

    /** @var list<string> */
    protected const S34_PARAMS = ['s3', 's4'];

    /** @var list<string> */
    protected const I_PARAMS = ['i1', 'i2', 'i3', 'i4', 'i5'];

    /** @var list<string> */
    protected const ALL_JUNK_PARAMS = [
        'jc', 'jmin', 'jmax',
        's1', 's2', 's3', 's4',
        'h1', 'h2', 'h3', 'h4',
        'i1', 'i2', 'i3', 'i4', 'i5',
    ];

    public function generateJunkParams(): array
    {
        return $this->normalizeForPersist($this->generateBaseJunk());
    }

    public function normalizeForPersist(array $params): array
    {
        $supported = array_flip($this->supportedParams());
        $out = [];

        foreach ($params as $key => $value) {
            if (! in_array($key, self::ALL_JUNK_PARAMS, true)) {
                $out[$key] = $value;

                continue;
            }
            if (! isset($supported[$key])) {
                continue;
            }
            $out[$key] = $value === null ? '' : (string) $value;
        }

        // Always clear/zero fields this version does not use.
        foreach (self::ALL_JUNK_PARAMS as $key) {
            if (isset($supported[$key])) {
                continue;
            }
            $out[$key] = in_array($key, self::I_PARAMS, true) ? '' : '0';
        }

        return $out;
    }

    public function confObfuscationLines(AwgConfig $config): array
    {
        $params = [];
        foreach (self::ALL_JUNK_PARAMS as $key) {
            $params[$key] = $config->{$key} ?? '';
        }

        return $this->confObfuscationLinesFromParams($params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    public function confObfuscationLinesFromParams(array $params): array
    {
        $lines = [];
        $map = [
            'jc' => 'Jc',
            'jmin' => 'Jmin',
            'jmax' => 'Jmax',
            's1' => 'S1',
            's2' => 'S2',
            's3' => 'S3',
            's4' => 'S4',
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
        ];

        foreach ($map as $field => $confKey) {
            if (! in_array($field, $this->supportedParams(), true)) {
                continue;
            }
            $lines[] = $confKey.' = '.($params[$field] ?? '');
        }

        foreach (self::I_PARAMS as $ikey) {
            if (! in_array($ikey, $this->supportedParams(), true)) {
                continue;
            }
            $val = trim((string) ($params[$ikey] ?? ''));
            if ($val !== '') {
                $lines[] = strtoupper($ikey).' = '.$val;
            }
        }

        return $lines;
    }

    public function vpnUriInnerParams(AwgConfig $config): array
    {
        $inner = [];
        $map = [
            'h1' => 'H1',
            'h2' => 'H2',
            'h3' => 'H3',
            'h4' => 'H4',
            'jc' => 'Jc',
            'jmin' => 'Jmin',
            'jmax' => 'Jmax',
            's1' => 'S1',
            's2' => 'S2',
            's3' => 'S3',
            's4' => 'S4',
        ];

        foreach ($map as $field => $key) {
            if (! in_array($field, $this->supportedParams(), true)) {
                continue;
            }
            $inner[$key] = (string) $config->{$field};
        }

        if (in_array('i1', $this->supportedParams(), true)) {
            $i1 = trim((string) ($config->i1 ?? ''));
            if ($i1 !== '') {
                $inner['I1'] = $i1;
            }
        }

        return $inner;
    }

    public function needsObfuscationParams(AwgConfig $config): bool
    {
        $required = array_values(array_filter(
            $this->supportedParams(),
            fn (string $p) => ! in_array($p, self::I_PARAMS, true)
        ));

        foreach ($required as $field) {
            if (trim((string) $config->{$field}) === '') {
                return true;
            }
        }

        return $config->jc === '4'
            && $config->jmin === '64'
            && $config->jmax === '80'
            && $config->s1 === '0'
            && $config->s2 === '0'
            && $config->h1 === '1'
            && $config->h2 === '2'
            && $config->h3 === '3'
            && $config->h4 === '4'
            && (
                ! in_array('s3', $this->supportedParams(), true)
                || ($config->s3 === '0' && $config->s4 === '0')
            );
    }

    /** @return array<string, string> */
    protected function generateBaseJunk(): array
    {
        $jc = (string) random_int(1, 10);
        $jmin = random_int(64, 1023);
        $jmax = (string) random_int($jmin + 1, 1024);
        $jmin = (string) $jmin;

        $s1 = (string) random_int(0, 64);
        do {
            $s2 = (string) random_int(0, 64);
        } while ((int) $s1 + 56 === (int) $s2);

        $s3 = (string) random_int(0, 64);
        $s4 = (string) random_int(0, 32);

        $hs = [];
        while (count($hs) < 4) {
            $h = (string) random_int(1, 2147483647);
            if (! in_array($h, $hs, true)) {
                $hs[] = $h;
            }
        }

        return [
            'jc' => $jc,
            'jmin' => $jmin,
            'jmax' => $jmax,
            's1' => $s1,
            's2' => $s2,
            's3' => $s3,
            's4' => $s4,
            'h1' => $hs[0],
            'h2' => $hs[1],
            'h3' => $hs[2],
            'h4' => $hs[3],
            'i1' => '',
            'i2' => '',
            'i3' => '',
            'i4' => '',
            'i5' => '',
        ];
    }
}
