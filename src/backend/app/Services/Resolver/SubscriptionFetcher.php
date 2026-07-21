<?php

namespace App\Services\Resolver;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubscriptionFetcher
{
    private const SCHEME_PATTERN = '(?:vless|ss|trojan|hysteria2|hy2|socks5?|socks)';

    private const USER_AGENT = 'v2rayN/6.38';

    public function __construct(
        private SingBoxOutboundParser $parser,
        private ClashSubscriptionParser $clashParser,
    ) {}

    /**
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>
     */
    public function fetch(string $url): array
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'subscription_url' => [__('resolver.subscription_url_invalid')],
            ]);
        }

        $bodies = [];
        foreach ($this->subscriptionUrlVariants($url) as $variantUrl) {
            try {
                $body = $this->download($variantUrl);
                if ($body !== '') {
                    $bodies[] = $body;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($bodies === []) {
            throw new RuntimeException(__('resolver.subscription_fetch_failed'));
        }

        return $this->parseBodies($bodies);
    }

    /**
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>
     */
    public function fetchMerged(string $url, ?string $body = null): array
    {
        $nodes = [];
        $seen = [];
        $errors = [];

        $url = trim($url);
        if ($url !== '') {
            try {
                foreach ($this->fetch($url) as $node) {
                    if (isset($seen[$node['key']])) {
                        continue;
                    }
                    $seen[$node['key']] = true;
                    $nodes[] = $node;
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($body !== null && trim($body) !== '') {
            try {
                foreach ($this->parseBody(trim($body)) as $node) {
                    if (isset($seen[$node['key']])) {
                        continue;
                    }
                    $seen[$node['key']] = true;
                    $nodes[] = $node;
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($nodes === []) {
            throw new RuntimeException(
                $errors !== [] ? implode('; ', $errors) : __('resolver.subscription_parse_failed')
            );
        }

        return $nodes;
    }

    /**
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>
     */
    public function parseBody(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new RuntimeException(__('resolver.subscription_body_empty'));
        }

        return $this->parseBodies([$body]);
    }

    /**
     * @param  list<string>  $bodies
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>
     */
    private function parseBodies(array $bodies): array
    {
        $nodes = [];
        $seen = [];

        foreach ($bodies as $body) {
            $clashNodes = $this->clashParser->parse($body);
            if (is_array($clashNodes)) {
                foreach ($clashNodes as $node) {
                    if (isset($seen[$node['key']])) {
                        continue;
                    }
                    $seen[$node['key']] = true;
                    $nodes[] = $node;
                }
            }

            $uris = $this->extractUris($body);
            foreach ($uris as $uri) {
                try {
                    $outbound = $this->parser->normalize($this->parser->fromShareUrl($uri));
                } catch (\Throwable) {
                    continue;
                }
                if (empty($outbound['type']) || empty($outbound['server'])) {
                    continue;
                }
                $key = $this->nodeKey($uri);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $nodes[] = [
                    'key' => $key,
                    'name' => $this->nodeName($uri, $outbound),
                    'type' => (string) $outbound['type'],
                    'server' => (string) $outbound['server'],
                    'port' => (int) ($outbound['server_port'] ?? 0),
                    'outbound' => $outbound,
                ];
            }

            $singBoxNodes = $this->parseSingBoxJsonOutbounds($body);
            foreach ($singBoxNodes as $node) {
                if (isset($seen[$node['key']])) {
                    continue;
                }
                $seen[$node['key']] = true;
                $nodes[] = $node;
            }
        }

        if ($nodes === []) {
            throw new RuntimeException(__('resolver.subscription_no_nodes'));
        }

        return $nodes;
    }

    private function download(string $url): string
    {
        $lastError = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(800000 * $attempt);
            }
            try {
                $response = Http::timeout(20)
                    ->withOptions(['allow_redirects' => true])
                    ->withHeaders([
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => '*/*',
                    ])
                    ->get($url);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();

                continue;
            }

            if (! $response->successful()) {
                $lastError = 'HTTP '.$response->status();

                continue;
            }

            $body = trim((string) $response->body());
            if ($body !== '' && strlen($body) > 8) {
                return $body;
            }
            $lastError = __('resolver.empty_response');
        }

        throw new RuntimeException(__('resolver.subscription_fetch_failed_with_error', ['error' => $lastError ?? 'unknown']), 0);
    }

    /** @return list<string> */
    private function subscriptionUrlVariants(string $url): array
    {
        $variants = [$url];
        if (preg_match('~^(https?://[^/]+/subscription)/(?:vless|clash|mihomo|meta|sing-box|singbox)/([^/?]+)~i', $url, $m)) {
            $base = $m[1];
            $token = $m[2];
            foreach (['vless', 'clash', 'mihomo', 'sing-box', ''] as $fmt) {
                $variants[] = $fmt === ''
                    ? "{$base}/{$token}"
                    : "{$base}/{$fmt}/{$token}";
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return list<string>
     */
    private function extractUris(string $body): array
    {
        $decoded = $this->tryBase64Decode($body);
        if ($decoded !== null) {
            $body = $decoded;
        }

        $uris = [];
        $pattern = '#'.self::SCHEME_PATTERN.'://#i';
        if (preg_match_all($pattern, $body, $matches, PREG_OFFSET_CAPTURE)) {
            $starts = $matches[0];
            $count = count($starts);
            for ($i = 0; $i < $count; $i++) {
                $begin = $starts[$i][1];
                $end = ($i + 1 < $count) ? $starts[$i + 1][1] : strlen($body);
                $uri = trim(substr($body, $begin, $end - $begin));
                if ($uri !== '' && $this->isShareUri($uri)) {
                    $uris[] = $uri;
                }
            }
        }

        if ($uris !== []) {
            return array_values(array_unique($uris));
        }

        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($this->isShareUri($line)) {
                $uris[] = $line;
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>
     */
    private function parseSingBoxJsonOutbounds(string $body): array
    {
        if (! str_contains($body, '"outbounds"')) {
            return [];
        }

        $json = json_decode($body, true);
        if (! is_array($json)) {
            return [];
        }

        $outbounds = $json['outbounds'] ?? null;
        if (! is_array($outbounds)) {
            return [];
        }

        $nodes = [];
        foreach ($outbounds as $ob) {
            if (! is_array($ob) || empty($ob['type']) || ($ob['type'] ?? '') === 'direct') {
                continue;
            }
            try {
                $outbound = $this->parser->normalize($ob);
            } catch (\Throwable) {
                continue;
            }
            if (empty($outbound['server'])) {
                continue;
            }
            $tag = (string) ($ob['tag'] ?? $outbound['server'] ?? 'node');
            $key = substr(hash('sha256', json_encode($ob)), 0, 16);
            $nodes[] = [
                'key' => $key,
                'name' => mb_substr($tag, 0, 120),
                'type' => (string) $outbound['type'],
                'server' => (string) $outbound['server'],
                'port' => (int) ($outbound['server_port'] ?? 0),
                'outbound' => $outbound,
            ];
        }

        return $nodes;
    }

    private function tryBase64Decode(string $body): ?string
    {
        $compact = preg_replace('/\s+/', '', $body) ?? $body;
        if ($compact === '' || strlen($compact) < 8) {
            return null;
        }
        if ($this->isShareUri($body) || str_contains($body, '://')) {
            return null;
        }
        $decoded = base64_decode($compact, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        if (! str_contains($decoded, '://') && ! str_contains($decoded, 'proxies:')) {
            return null;
        }

        return $decoded;
    }

    private function isShareUri(string $value): bool
    {
        return (bool) preg_match('#^'.self::SCHEME_PATTERN.'://#i', $value);
    }

    private function nodeKey(string $uri): string
    {
        return substr(hash('sha256', $uri), 0, 16);
    }

    /** @param  array<string, mixed>  $outbound */
    private function nodeName(string $uri, array $outbound): string
    {
        $name = $this->fragmentFromUri($uri);
        if ($name === '') {
            $name = $this->remarkFromUri($uri);
        }
        if ($name !== '') {
            return mb_substr($name, 0, 120);
        }

        $type = (string) ($outbound['type'] ?? 'node');
        $server = (string) ($outbound['server'] ?? '?');
        $port = (int) ($outbound['server_port'] ?? 0);

        return "{$type}://{$server}:{$port}";
    }

    private function fragmentFromUri(string $uri): string
    {
        $hashPos = strrpos($uri, '#');
        if ($hashPos === false) {
            return '';
        }

        $name = rawurldecode(substr($uri, $hashPos + 1));

        return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    }

    private function remarkFromUri(string $uri): string
    {
        $query = parse_url($uri, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $q);
        foreach (['remarks', 'remark', 'ps', 'note'] as $field) {
            if (! empty($q[$field]) && is_string($q[$field])) {
                $name = rawurldecode($q[$field]);

                return preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
            }
        }

        return '';
    }
}
