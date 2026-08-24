<?php

namespace App\Services\AmneziaWg\Versions;

class AwgVersion15Profile extends AbstractAwgVersionProfile
{
    public function id(): string
    {
        return '1.5';
    }

    public function label(): string
    {
        return 'AmneziaWG 1.5';
    }

    public function vpnUriProtocolVersion(): string
    {
        return '1';
    }

    public function supportedParams(): array
    {
        return array_merge(self::BASE_PARAMS, self::I_PARAMS);
    }
}
