<?php

namespace App\Services\Resolver;

use App\Models\AwgConfig;
use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class MergedRulesetWriter
{
    /** @var array<string, array<string, mixed>> */
    private array $decompileCache = [];

    public bool $applyProxyCidrsChanged = false;

    public bool $applyMergedChanged = false;

    public function __construct(
        private AmneziaWgService $awg,
        private ResolverPaths $paths,
        private ResolverFileHelper $files,
    ) {}

    public function resetChangeFlags(): void
    {
        $this->applyProxyCidrsChanged = false;
        $this->applyMergedChanged = false;
    }

    public function forgetDecompileCache(string $tag): void
    {
        unset($this->decompileCache[$tag]);
    }

    /**
     * Decompile community .srs → array.
     * In-request cache + on-disk cache keyed by .srs size/mtime (no docker when fresh).
     *
     * @return array{version?: int, rules?: list<array<string, mixed>>}
     */
    public function decompileCommunityRuleset(string $tag): array
    {
        if (isset($this->decompileCache[$tag])) {
            return $this->decompileCache[$tag];
        }

        $srs = $this->paths->communityRulesetPath($tag);
        if (! is_file($srs)) {
            throw new RuntimeException(
                "Ruleset не найден на диске: {$tag}.srs — откройте Резолвер → Настройки → Скачать."
            );
        }

        $srsSize = (int) filesize($srs);
        $srsMtime = (int) filemtime($srs);
        $cachePath = $this->paths->decompiledRulesetCachePath($tag);
        $metaPath = $this->paths->decompiledRulesetMetaPath($tag);

        if (is_file($cachePath) && is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true);
            if (is_array($meta)
                && (int) ($meta['size'] ?? -1) === $srsSize
                && (int) ($meta['mtime'] ?? -1) === $srsMtime
            ) {
                $decoded = json_decode((string) file_get_contents($cachePath), true);
                if (is_array($decoded)) {
                    $this->decompileCache[$tag] = $decoded;

                    return $decoded;
                }
            }
        }

        $outName = '.decompile_'.$tag.'.json';
        $container = $this->awg->containerName();
        $r = Process::timeout(60)->run([
            'docker', 'exec', $container,
            'sing-box', 'rule-set', 'decompile',
            '-o', '/config/rulesets/'.$outName,
            '/config/rulesets/'.$tag.'.srs',
        ]);

        if (! $r->successful() || ! is_file($cachePath)) {
            @unlink($cachePath);
            @unlink($metaPath);
            throw new RuntimeException("Не удалось decompile {$tag}.srs: ".trim($r->errorOutput() ?: $r->output()));
        }

        $decoded = json_decode((string) file_get_contents($cachePath), true);
        if (! is_array($decoded)) {
            @unlink($cachePath);
            @unlink($metaPath);
            throw new RuntimeException("Некорректный JSON после decompile {$tag}");
        }

        file_put_contents($metaPath, json_encode([
            'size' => $srsSize,
            'mtime' => $srsMtime,
            'tag' => $tag,
        ], JSON_UNESCAPED_SLASHES)."\n");

        $this->decompileCache[$tag] = $decoded;

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadRulesForTag(string $tag): array
    {
        if (str_starts_with($tag, 'custom_')) {
            $srs = $this->paths->rulesetDir().'/'.$tag.'.srs';
            if (is_file($srs) && filesize($srs) > 16) {
                $decoded = $this->decompileCommunityRuleset($tag);
                $rules = $decoded['rules'] ?? [];

                return is_array($rules) ? $rules : [];
            }

            $path = $this->paths->rulesetDir().'/'.$tag.'.json';
            if (! is_file($path)) {
                throw new RuntimeException(
                    "Свой список «{$tag}» не найден на диске — откройте Резолвер → Настройки."
                );
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            $rules = is_array($decoded) ? ($decoded['rules'] ?? []) : [];

            return is_array($rules) ? $rules : [];
        }

        $decoded = $this->decompileCommunityRuleset($tag);
        $rules = $decoded['rules'] ?? [];

        return is_array($rules) ? $rules : [];
    }

    /**
     * Collect string matchers from decompiled rules (any list: domains and/or IPs).
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<string>
     */
    public function collectRuleField(array $rules, string $key, bool $lowercase = false): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            foreach ($rule[$key] ?? [] as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $out[] = $lowercase ? strtolower($value) : $value;
            }
        }

        return $out;
    }

    /**
     * Merge any selected community lists + user_domains / user_subnets.
     * Domains → FakeIP DNS; IP CIDRs → selective MARK (not client AllowedIPs).
     *
     * @return array{tag: string, ip_tag: ?string, ip_cidrs: list<string>}
     */
    public function writeMergedRulesetForConfig(AwgConfig $config): array
    {
        $domainSuffix = [];
        $domainExact = [];
        $domainKeyword = [];
        $domainRegex = [];
        $ipCidrs = [];

        foreach ($config->community_lists ?? [] as $tag) {
            if (! is_string($tag) || $tag === '') {
                continue;
            }
            $rules = $this->loadRulesForTag($tag);
            $domainSuffix = [...$domainSuffix, ...$this->collectRuleField($rules, 'domain_suffix', true)];
            $domainExact = [...$domainExact, ...$this->collectRuleField($rules, 'domain', true)];
            $domainKeyword = [...$domainKeyword, ...$this->collectRuleField($rules, 'domain_keyword', true)];
            $domainRegex = [...$domainRegex, ...$this->collectRuleField($rules, 'domain_regex')];
            $ipCidrs = [...$ipCidrs, ...$this->collectRuleField($rules, 'ip_cidr')];
        }

        foreach ($config->user_domains ?? [] as $d) {
            $d = strtolower(trim((string) $d));
            if ($d !== '') {
                $domainSuffix[] = $d;
            }
        }

        foreach ($config->user_subnets ?? [] as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr !== '') {
                $ipCidrs[] = $cidr;
            }
        }

        $domainSuffix = array_values(array_unique($domainSuffix));
        $domainExact = array_values(array_unique($domainExact));
        $domainKeyword = array_values(array_unique($domainKeyword));
        $domainRegex = array_values(array_unique($domainRegex));
        $ipCidrs = array_values(array_unique($this->normalizeIpv4CidrsForProxy($ipCidrs)));

        $domainRule = [];
        if ($domainSuffix !== []) {
            $domainRule['domain_suffix'] = $domainSuffix;
        }
        if ($domainExact !== []) {
            $domainRule['domain'] = $domainExact;
        }
        if ($domainKeyword !== []) {
            $domainRule['domain_keyword'] = $domainKeyword;
        }
        if ($domainRegex !== []) {
            $domainRule['domain_regex'] = $domainRegex;
        }

        $payload = [
            'version' => 3,
            'rules' => $domainRule !== [] ? [$domainRule] : [['domain_suffix' => ['invalid.invalid']]],
        ];

        $path = $this->paths->mergedRulesetPath($config);
        $domainJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        $changed = $this->files->writeFileIfChanged($path, $domainJson);

        $ipTag = null;
        if ($ipCidrs !== []) {
            $ipPath = $this->paths->mergedIpRulesetPath($config);
            $ipPayload = [
                'version' => 3,
                'rules' => [
                    ['ip_cidr' => $ipCidrs],
                ],
            ];
            $ipJson = json_encode($ipPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
            if ($this->files->writeFileIfChanged($ipPath, $ipJson)) {
                $changed = true;
            }
            $ipTag = $this->paths->mergedIpRulesetTag($config);
        } else {
            $legacyIp = $this->paths->mergedIpRulesetPath($config);
            if (is_file($legacyIp)) {
                @unlink($legacyIp);
                $changed = true;
            }
        }

        if ($changed) {
            $this->applyMergedChanged = true;
        }

        return [
            'tag' => $this->paths->mergedRulesetTag($config),
            'ip_tag' => $ipTag,
            'ip_cidrs' => $ipCidrs,
        ];
    }

    /**
     * Keep IPv4 CIDRs for selective MARK (DNS strategy is ipv4_only).
     *
     * @param  list<string>  $cidrs
     * @return list<string>
     */
    public function normalizeIpv4CidrsForProxy(array $cidrs): array
    {
        $out = [];
        foreach ($cidrs as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '') {
                continue;
            }
            if (! str_contains($cidr, '/')) {
                if (filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $cidr .= '/32';
                } else {
                    continue;
                }
            }
            [$host, $mask] = array_pad(explode('/', $cidr, 2), 2, null);
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $mask === null || ! ctype_digit((string) $mask)) {
                continue;
            }
            $maskInt = (int) $mask;
            if ($maskInt < 0 || $maskInt > 32) {
                continue;
            }
            // Skip FakeIP itself if it ever appears in a list
            if ($host === '198.18.0.0' && $maskInt === 15) {
                continue;
            }
            $out[] = $host.'/'.$maskInt;
        }

        return array_values(array_unique($out));
    }

    /**
     * Write UNION of all proxy CIDRs for MARK/routes (one line per CIDR).
     *
     * @param  list<string>  $cidrs
     * @return bool true if file content changed
     */
    public function writeProxyCidrsAll(array $cidrs): bool
    {
        $cidrs = array_values(array_unique($this->normalizeIpv4CidrsForProxy($cidrs)));
        sort($cidrs);
        $contents = $cidrs === [] ? '' : implode("\n", $cidrs)."\n";
        $changed = $this->files->writeFileIfChanged($this->paths->proxyCidrsAllPath(), $contents);
        if ($changed) {
            $this->applyProxyCidrsChanged = true;
        }

        return $changed;
    }
}
