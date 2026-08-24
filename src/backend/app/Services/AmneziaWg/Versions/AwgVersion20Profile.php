<?php

namespace App\Services\AmneziaWg\Versions;

class AwgVersion20Profile extends AbstractAwgVersionProfile
{
    public function id(): string
    {
        return '2.0';
    }

    public function label(): string
    {
        return 'AmneziaWG 2.0';
    }

    public function vpnUriProtocolVersion(): string
    {
        return '2';
    }

    public function supportedParams(): array
    {
        return array_merge(self::BASE_PARAMS, self::S34_PARAMS, self::I_PARAMS);
    }
}
