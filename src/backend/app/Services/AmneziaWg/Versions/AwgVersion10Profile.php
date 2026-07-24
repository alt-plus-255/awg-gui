<?php

namespace App\Services\AmneziaWg\Versions;

class AwgVersion10Profile extends AbstractAwgVersionProfile
{
    public function id(): string
    {
        return '1.0';
    }

    public function label(): string
    {
        return 'AmneziaWG 1.0';
    }

    public function vpnUriProtocolVersion(): string
    {
        return '1';
    }

    public function supportedParams(): array
    {
        return self::BASE_PARAMS;
    }
}
