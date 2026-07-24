<?php

namespace App\Services\AmneziaWg\Versions;

use App\Models\AwgConfig;

interface AwgVersionProfile
{
    public function id(): string;

    public function label(): string;

    /** Value for vpn:// outer container `protocol_version` ("1" or "2"). */
    public function vpnUriProtocolVersion(): string;

    /**
     * DB / form field names supported by this protocol version.
     *
     * @return list<string>
     */
    public function supportedParams(): array;

    /**
     * Full junk payload for persist (unsupported fields cleared/zeroed).
     *
     * @return array<string, string>
     */
    public function generateJunkParams(): array;

    /**
     * Drop or zero fields that this version does not use.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function normalizeForPersist(array $params): array;

    /**
     * Conf [Interface] lines for AWG obfuscation params (without trailing peers).
     *
     * @return list<string>
     */
    public function confObfuscationLines(AwgConfig $config): array;

    /**
     * Same as confObfuscationLines, from a flat junk-params array.
     *
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    public function confObfuscationLinesFromParams(array $params): array;

    /**
     * Keys to include in vpn:// last_config JSON (uppercase Amnezia keys).
     *
     * @return array<string, string>
     */
    public function vpnUriInnerParams(AwgConfig $config): array;

    /** Whether empty/factory-default obfuscation needs regeneration. */
    public function needsObfuscationParams(AwgConfig $config): bool;
}
