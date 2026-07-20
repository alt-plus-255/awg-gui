<?php

namespace App\Services\Resolver;

use App\Models\AwgConfig;
use App\Services\AmneziaWg\AmneziaWgService;

class ResolverPaths
{
    public function __construct(private AmneziaWgService $awg) {}

    public function rulesetDir(): string
    {
        $dir = $this->awg->configDir().'/rulesets';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public function communityRulesetPath(string $tag): string
    {
        return $this->rulesetDir().'/'.$tag.'.srs';
    }

    public function mergedRulesetPath(AwgConfig $config): string
    {
        return $this->rulesetDir().'/merged_cfg_'.$config->id.'.json';
    }

    public function mergedRulesetTag(AwgConfig $config): string
    {
        return 'merged_cfg_'.$config->id;
    }

    public function mergedIpRulesetPath(AwgConfig $config): string
    {
        return $this->rulesetDir().'/merged_cfg_'.$config->id.'_ip.json';
    }

    public function mergedIpRulesetTag(AwgConfig $config): string
    {
        return 'merged_cfg_'.$config->id.'_ip';
    }

    /** Union of list/user CIDRs for iptables MARK + TUN routes (not a sing-box ruleset). */
    public function proxyCidrsAllPath(): string
    {
        return $this->rulesetDir().'/proxy_cidrs_all.lst';
    }

    public function decompiledRulesetCachePath(string $tag): string
    {
        return $this->rulesetDir().'/.decompile_'.$tag.'.json';
    }

    public function decompiledRulesetMetaPath(string $tag): string
    {
        return $this->rulesetDir().'/.decompile_'.$tag.'.meta.json';
    }

    public function singBoxConfigPath(): string
    {
        return $this->awg->configDir().'/sing-box.json';
    }

    public function singBoxPingConfigPath(): string
    {
        return $this->awg->configDir().'/sing-box-ping.json';
    }

    public function resolverIfacesPath(): string
    {
        return $this->awg->configDir().'/resolver-ifaces.txt';
    }

    public function resolverStatusPath(): string
    {
        return $this->awg->configDir().'/resolver-status.json';
    }
}
