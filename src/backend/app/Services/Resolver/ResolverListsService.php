<?php

namespace App\Services\Resolver;

use App\Models\AwgConfig;
use App\Models\ResolverCustomList;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Community list sync (interval / force) + custom list CRUD disk files + catalog for settings UI.
 */
class ResolverListsService
{
    public const SETTING_INTERVAL = 'resolver_lists_sync_interval_minutes';

    public const SETTING_LAST_SYNC = 'resolver_lists_last_sync_at';

    public const SETTING_META = 'resolver_list_meta';

    public const DEFAULT_INTERVAL_MINUTES = 360;

    public function __construct(
        private ResolverPaths $paths,
        private ResolverFileHelper $files,
        private MergedRulesetWriter $mergedRulesets,
    ) {}

    public function syncIntervalMinutes(): int
    {
        $v = (int) Setting::getValue(self::SETTING_INTERVAL, self::DEFAULT_INTERVAL_MINUTES);

        return max(5, min(10080, $v > 0 ? $v : self::DEFAULT_INTERVAL_MINUTES));
    }

    public function setSyncIntervalMinutes(int $minutes): void
    {
        $minutes = max(5, min(10080, $minutes));
        Setting::setValue(self::SETTING_INTERVAL, (string) $minutes);
    }

    public function lastSyncAt(): ?string
    {
        $v = Setting::getValue(self::SETTING_LAST_SYNC);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** @return array<string, array{downloaded_at?: string, size?: int}> */
    public function listMeta(): array
    {
        $raw = Setting::getValue(self::SETTING_META, '{}');
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, array{downloaded_at?: string, size?: int}>  $meta */
    public function saveListMeta(array $meta): void
    {
        Setting::setValue(self::SETTING_META, $meta);
    }

    public function customRulesetPath(string $slug): string
    {
        return $this->paths->rulesetDir().'/'.$slug.'.json';
    }

    public function customSrsPath(string $slug): string
    {
        return $this->paths->rulesetDir().'/'.$slug.'.srs';
    }

    public function isCustomTag(string $tag): bool
    {
        return str_starts_with($tag, 'custom_');
    }

    public function customDiskPath(string $slug): ?string
    {
        $srs = $this->customSrsPath($slug);
        if (is_file($srs) && filesize($srs) > 16) {
            return $srs;
        }
        $json = $this->customRulesetPath($slug);
        if (is_file($json) && filesize($json) > 0) {
            return $json;
        }

        return null;
    }

    public function assertListFilePresent(string $tag): void
    {
        if ($this->isCustomTag($tag)) {
            if ($this->customDiskPath($tag) === null) {
                throw new RuntimeException(
                    "Свой список «{$tag}» не найден на диске — откройте Резолвер → Настройки и сохраните/скачайте список."
                );
            }

            return;
        }

        $path = $this->paths->communityRulesetPath($tag);
        if (! is_file($path) || filesize($path) <= 16) {
            throw new RuntimeException(
                "Список «{$tag}» не скачан — откройте Резолвер → Настройки → Скачать."
            );
        }
    }

    /**
     * Ensure every selected tag has a local file (no HTTP).
     *
     * @param  list<string>  $tags
     */
    public function assertSelectedListsOnDisk(array $tags): void
    {
        foreach ($tags as $tag) {
            if (! is_string($tag) || $tag === '') {
                continue;
            }
            $this->assertListFilePresent($tag);
        }
    }

    /** @return list<string> */
    public function knownListTags(): array
    {
        $custom = ResolverCustomList::query()->pluck('slug')->all();

        return array_values(array_unique([...ResolverService::COMMUNITY_LISTS, ...$custom]));
    }

    /**
     * @return list<array{tag: string, label: string, kind: string, exclusive_group: ?string}>
     */
    public function customListCatalog(): array
    {
        return ResolverCustomList::query()
            ->orderBy('id')
            ->get()
            ->map(fn (ResolverCustomList $l) => [
                'tag' => $l->slug,
                'label' => $l->name.' (свой)',
                'kind' => 'custom',
                'exclusive_group' => null,
                'id' => $l->id,
                'source_url' => $l->isRemote() ? $l->source_url : null,
                'domains_count' => count($l->domains ?? []),
                'cidrs_count' => count($l->cidrs ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Rows for settings table / modal.
     *
     * @return list<array<string, mixed>>
     */
    public function listsTableRows(): array
    {
        $meta = $this->listMeta();
        $rows = [];

        foreach (app(ResolverService::class)->communityListCatalog() as $item) {
            $tag = $item['tag'];
            $path = $this->paths->communityRulesetPath($tag);
            $exists = is_file($path) && filesize($path) > 16;
            $size = $exists ? (int) filesize($path) : 0;
            $mtime = $exists ? date('c', (int) filemtime($path)) : null;
            $downloaded = $meta[$tag]['downloaded_at'] ?? $mtime;

            $rows[] = [
                'tag' => $tag,
                'label' => $item['label'],
                'kind' => 'community',
                'source_url' => $item['source_url'] ?? ResolverService::communitySourceUrl($tag),
                'on_disk' => $exists,
                'size' => $size,
                'downloaded_at' => $downloaded,
                'can_sync' => true,
                'can_edit' => false,
                'can_delete' => false,
            ];
        }

        foreach (ResolverCustomList::query()->orderBy('id')->get() as $list) {
            $path = $this->customDiskPath($list->slug);
            $exists = $path !== null;
            $size = $exists ? (int) filesize($path) : 0;
            $mtime = $exists ? date('c', (int) filemtime($path)) : optional($list->updated_at)?->toIso8601String();
            $downloaded = $meta[$list->slug]['downloaded_at'] ?? $mtime;

            $rows[] = [
                'tag' => $list->slug,
                'label' => $list->name,
                'kind' => 'custom',
                'id' => $list->id,
                'source_url' => $list->isRemote() ? $list->source_url : null,
                'on_disk' => $exists,
                'size' => $size,
                'downloaded_at' => $downloaded,
                'domains' => array_values($list->domains ?? []),
                'cidrs' => array_values($list->cidrs ?? []),
                'can_sync' => $list->isRemote(),
                'can_edit' => true,
                'can_delete' => true,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function settingsPayload(): array
    {
        return [
            'sync_interval_minutes' => $this->syncIntervalMinutes(),
            'last_sync_at' => $this->lastSyncAt(),
            'lists' => $this->listsTableRows(),
        ];
    }

    /**
     * Download community .srs (force or missing). Updates meta + last_sync.
     *
     * @param  list<string>|null  $tags  null = all COMMUNITY_LISTS
     */
    public function syncCommunity(?array $tags = null, bool $force = true): void
    {
        $tags = $tags === null
            ? ResolverService::COMMUNITY_LISTS
            : array_values(array_unique(array_filter(array_map('strval', $tags))));

        $errors = [];
        $meta = $this->listMeta();
        $downloadedAny = false;

        foreach ($tags as $tag) {
            if (! in_array($tag, ResolverService::COMMUNITY_LISTS, true)) {
                continue;
            }

            $path = $this->paths->communityRulesetPath($tag);
            if (! $force && is_file($path) && filesize($path) > 16) {
                continue;
            }

            $tmp = $path.'.tmp';
            $url = ResolverService::RULESET_BASE_URL.'/'.$tag.'.srs';

            try {
                $response = Http::timeout(90)->get($url);
                if (! $response->successful()) {
                    throw new RuntimeException("HTTP {$response->status()}");
                }
                $body = $response->body();
                if ($body === '' || strlen($body) < 16) {
                    throw new RuntimeException('пустой или слишком маленький файл');
                }
                file_put_contents($tmp, $body);
                rename($tmp, $path);
                @unlink($this->paths->decompiledRulesetCachePath($tag));
                @unlink($this->paths->decompiledRulesetMetaPath($tag));
                $this->mergedRulesets->forgetDecompileCache($tag);

                $meta[$tag] = [
                    'downloaded_at' => now()->toIso8601String(),
                    'size' => (int) filesize($path),
                ];
                $downloadedAny = true;
            } catch (\Throwable $e) {
                @unlink($tmp);
                if (! is_file($path) || filesize($path) <= 16) {
                    $errors[] = "{$tag}: {$e->getMessage()}";
                } else {
                    Log::warning("ruleset refresh failed for {$tag}, keeping cached file: {$e->getMessage()}");
                }
            }
        }

        $this->saveListMeta($meta);
        if ($force || $downloadedAny) {
            Setting::setValue(self::SETTING_LAST_SYNC, now()->toIso8601String());
        }

        if ($errors !== []) {
            throw new RuntimeException('Не удалось скачать ruleset: '.implode('; ', $errors));
        }
    }

    public function syncIfDue(): bool
    {
        $last = $this->lastSyncAt();
        $interval = $this->syncIntervalMinutes();
        $due = $last === null;
        if (! $due) {
            try {
                $due = now()->greaterThanOrEqualTo(\Carbon\Carbon::parse($last)->addMinutes($interval));
            } catch (\Throwable) {
                $due = true;
            }
        }

        $needed = [];
        foreach (AwgConfig::query()->where('resolver_enabled', true)->get() as $cfg) {
            foreach ($cfg->community_lists ?? [] as $tag) {
                if (! is_string($tag) || $this->isCustomTag($tag)) {
                    continue;
                }
                $path = $this->paths->communityRulesetPath($tag);
                if (! is_file($path) || filesize($path) <= 16) {
                    $needed[] = $tag;
                }
            }
        }
        $needed = array_values(array_unique($needed));

        if (! $due && $needed === []) {
            return false;
        }

        if ($due) {
            $this->syncCommunity(null, true);
            $this->syncAllRemoteCustoms(true);
        } else {
            $this->syncCommunity($needed, true);
            foreach (ResolverCustomList::query()->get() as $list) {
                if (! $list->isRemote()) {
                    continue;
                }
                if ($this->customDiskPath($list->slug) === null) {
                    $this->syncCustomRemote($list, true);
                }
            }
        }

        return true;
    }

    /** Sync one community or remote custom tag. */
    public function syncOneTag(string $tag, bool $force = true): void
    {
        if ($this->isCustomTag($tag)) {
            $list = ResolverCustomList::query()->where('slug', $tag)->first();
            if (! $list || ! $list->isRemote()) {
                throw new RuntimeException('Sync доступен только для своих списков по ссылке');
            }
            $this->syncCustomRemote($list, $force);

            return;
        }

        $this->syncCommunity([$tag], $force);
    }

    public function syncAllRemoteCustoms(bool $force = true): void
    {
        $errors = [];
        foreach (ResolverCustomList::query()->get() as $list) {
            if (! $list->isRemote()) {
                continue;
            }
            try {
                $this->syncCustomRemote($list, $force);
            } catch (\Throwable $e) {
                $errors[] = "{$list->slug}: {$e->getMessage()}";
            }
        }
        if ($errors !== []) {
            throw new RuntimeException('Не удалось скачать свои URL-списки: '.implode('; ', $errors));
        }
    }

    public function syncCustomRemote(ResolverCustomList $list, bool $force = true): void
    {
        if (! $list->isRemote()) {
            throw new RuntimeException('У списка нет URL');
        }
        $url = trim((string) $list->source_url);
        $srsPath = $this->customSrsPath($list->slug);
        $jsonPath = $this->customRulesetPath($list->slug);

        if (! $force && $this->customDiskPath($list->slug) !== null) {
            return;
        }

        $response = Http::timeout(90)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} для {$url}");
        }
        $body = $response->body();
        if ($body === '' || strlen($body) < 4) {
            throw new RuntimeException('пустой ответ по URL');
        }

        if ($this->looksLikeSrs($body, $url)) {
            $tmp = $srsPath.'.tmp';
            file_put_contents($tmp, $body);
            rename($tmp, $srsPath);
            @unlink($jsonPath);
            @unlink($this->paths->decompiledRulesetCachePath($list->slug));
            @unlink($this->paths->decompiledRulesetMetaPath($list->slug));
            $this->mergedRulesets->forgetDecompileCache($list->slug);
            $list->domains = [];
            $list->cidrs = [];
            $list->save();
        } else {
            $parsed = $this->parseRemoteTextList($body);
            $list->domains = $parsed['domains'];
            $list->cidrs = $parsed['cidrs'];
            $list->save();
            $this->writeCustomRulesetFile($list);
            @unlink($srsPath);
            @unlink($this->paths->decompiledRulesetCachePath($list->slug));
            @unlink($this->paths->decompiledRulesetMetaPath($list->slug));
        }

        $meta = $this->listMeta();
        $disk = $this->customDiskPath($list->slug);
        $meta[$list->slug] = [
            'downloaded_at' => now()->toIso8601String(),
            'size' => $disk ? (int) filesize($disk) : 0,
        ];
        $this->saveListMeta($meta);
    }

    private function looksLikeSrs(string $body, string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (str_ends_with($path, '.srs')) {
            return true;
        }
        if (str_contains($body, "\0")) {
            return true;
        }
        if (! mb_check_encoding($body, 'UTF-8')) {
            return true;
        }

        return false;
    }

    /**
     * @return array{domains: list<string>, cidrs: list<string>}
     */
    private function parseRemoteTextList(string $body): array
    {
        $domains = [];
        $cidrs = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                continue;
            }
            // domain=foo.example / ||example.com^ style → take host-ish token
            $line = preg_replace('#^https?://#i', '', $line) ?? $line;
            $line = explode('#', $line, 2)[0];
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_contains($line, '/') || filter_var($line, FILTER_VALIDATE_IP)) {
                try {
                    $n = $this->normalizeCustomEntries([], [$line]);
                    $cidrs = [...$cidrs, ...$n['cidrs']];
                } catch (ValidationException) {
                    // skip bad line
                }
                continue;
            }
            try {
                $n = $this->normalizeCustomEntries([$line], []);
                $domains = [...$domains, ...$n['domains']];
            } catch (ValidationException) {
                // skip
            }
        }
        $domains = array_values(array_unique($domains));
        $cidrs = array_values(array_unique($cidrs));
        if ($domains === [] && $cidrs === []) {
            throw new RuntimeException('В текстовом списке нет доменов/CIDR');
        }

        return ['domains' => $domains, 'cidrs' => $cidrs];
    }

    public function normalizeSourceUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            throw ValidationException::withMessages([
                'source_url' => ['Укажите http(s) URL списка'],
            ]);
        }

        return $url;
    }

    /**
     * @param  list<string>  $domains
     * @param  list<string>  $cidrs
     * @return array{domains: list<string>, cidrs: list<string>}
     */
    public function normalizeCustomEntries(array $domains, array $cidrs): array
    {
        $outDomains = [];
        foreach ($domains as $raw) {
            foreach (preg_split('/[\s,;]+/', (string) $raw) ?: [] as $part) {
                $part = strtolower(trim($part));
                if ($part === '' || str_starts_with($part, '//')) {
                    continue;
                }
                $part = preg_replace('#^https?://#', '', $part) ?? $part;
                $part = explode('/', $part, 2)[0];
                $part = explode(':', $part, 2)[0];
                $part = ltrim($part, '.');
                if ($part === '' || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $part)) {
                    throw ValidationException::withMessages([
                        'domains' => ["Неверный домен: {$raw}"],
                    ]);
                }
                $outDomains[] = $part;
            }
        }

        $outCidrs = [];
        foreach ($cidrs as $raw) {
            $part = trim((string) $raw);
            if ($part === '' || str_starts_with($part, '//')) {
                continue;
            }
            if (! str_contains($part, '/')) {
                if (filter_var($part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $part .= '/32';
                } elseif (filter_var($part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $part .= '/128';
                }
            }
            if (! $this->isValidCidr($part)) {
                throw ValidationException::withMessages([
                    'cidrs' => ["Неверная подсеть: {$raw}"],
                ]);
            }
            $outCidrs[] = $part;
        }

        return [
            'domains' => array_values(array_unique($outDomains)),
            'cidrs' => array_values(array_unique($outCidrs)),
        ];
    }

    private function isValidCidr(string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }
        [$ip, $mask] = explode('/', $cidr, 2);
        if (! ctype_digit($mask)) {
            return false;
        }
        $maskInt = (int) $mask;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $maskInt >= 0 && $maskInt <= 32;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $maskInt >= 0 && $maskInt <= 128;
        }

        return false;
    }

    public function createCustomList(string $name, array $domains, array $cidrs, ?string $sourceUrl = null): ResolverCustomList
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Укажите имя списка']]);
        }
        $sourceUrl = $this->normalizeSourceUrl($sourceUrl);

        $list = new ResolverCustomList([
            'name' => $name,
            'slug' => 'tmp_'.bin2hex(random_bytes(8)),
            'domains' => [],
            'cidrs' => [],
            'source_url' => $sourceUrl,
        ]);
        $list->save();
        $list->slug = ResolverCustomList::makeSlug((int) $list->id);
        $list->save();

        if ($sourceUrl !== null) {
            $this->syncCustomRemote($list, true);
        } else {
            $normalized = $this->normalizeCustomEntries($domains, $cidrs);
            if ($normalized['domains'] === [] && $normalized['cidrs'] === []) {
                $list->delete();
                throw ValidationException::withMessages([
                    'domains' => ['Добавьте домены/CIDR в textarea или укажите ссылку на список'],
                ]);
            }
            $list->domains = $normalized['domains'];
            $list->cidrs = $normalized['cidrs'];
            $list->save();
            $this->writeCustomRulesetFile($list);
        }

        return $list->fresh();
    }

    public function updateCustomList(ResolverCustomList $list, string $name, array $domains, array $cidrs, ?string $sourceUrl = null): ResolverCustomList
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Укажите имя списка']]);
        }
        $sourceUrl = $this->normalizeSourceUrl($sourceUrl);

        $list->name = $name;
        if (! str_starts_with((string) $list->slug, 'custom_')) {
            $list->slug = ResolverCustomList::makeSlug((int) $list->id);
        }
        $list->source_url = $sourceUrl;

        if ($sourceUrl !== null) {
            $list->domains = [];
            $list->cidrs = [];
            $list->save();
            $this->syncCustomRemote($list, true);
        } else {
            $normalized = $this->normalizeCustomEntries($domains, $cidrs);
            if ($normalized['domains'] === [] && $normalized['cidrs'] === []) {
                throw ValidationException::withMessages([
                    'domains' => ['Добавьте домены/CIDR в textarea или укажите ссылку на список'],
                ]);
            }
            $list->domains = $normalized['domains'];
            $list->cidrs = $normalized['cidrs'];
            $list->save();
            @unlink($this->customSrsPath($list->slug));
            @unlink($this->paths->decompiledRulesetCachePath($list->slug));
            @unlink($this->paths->decompiledRulesetMetaPath($list->slug));
            $this->writeCustomRulesetFile($list);
        }

        return $list->fresh();
    }

    public function deleteCustomList(ResolverCustomList $list): void
    {
        $slug = $list->slug;
        $this->detachCustomTagFromConfigs($slug);
        $this->deleteCustomRulesetFile($slug);
        $list->delete();
    }

    public function writeCustomRulesetFile(ResolverCustomList $list): void
    {
        $domains = array_values(array_unique(array_filter(array_map(
            fn ($d) => strtolower(trim((string) $d)),
            $list->domains ?? []
        ))));
        $cidrs = array_values(array_unique(array_filter(array_map(
            fn ($c) => trim((string) $c),
            $list->cidrs ?? []
        ))));

        $rules = [];
        if ($domains !== []) {
            $rules[] = ['domain_suffix' => $domains];
        }
        if ($cidrs !== []) {
            $rules[] = ['ip_cidr' => $cidrs];
        }
        if ($rules === []) {
            $rules[] = ['domain_suffix' => ['invalid.invalid']];
        }

        $payload = [
            'version' => 3,
            'rules' => $rules,
        ];
        $path = $this->customRulesetPath($list->slug);
        $this->files->writeFileIfChanged(
            $path,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
    }

    public function deleteCustomRulesetFile(string $slug): void
    {
        @unlink($this->customRulesetPath($slug));
        @unlink($this->customSrsPath($slug));
        @unlink($this->paths->decompiledRulesetCachePath($slug));
        @unlink($this->paths->decompiledRulesetMetaPath($slug));
        $this->mergedRulesets->forgetDecompileCache($slug);
    }

    /** Remove slug from all awg_configs.community_lists. */
    public function detachCustomTagFromConfigs(string $slug): void
    {
        foreach (AwgConfig::query()->get() as $cfg) {
            $lists = array_values($cfg->community_lists ?? []);
            if (! in_array($slug, $lists, true)) {
                continue;
            }
            $cfg->community_lists = array_values(array_filter($lists, fn ($t) => $t !== $slug));
            $cfg->save();
        }
    }
}
