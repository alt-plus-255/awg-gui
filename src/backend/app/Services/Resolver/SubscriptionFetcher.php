<?php

namespace App\Services\Resolver;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubscriptionFetcher
{
    private const SCHEME_PATTERN = '(?:vless|vmess|ss|trojan|hysteria2|hy2|socks5?|socks)';

    private const USER_AGENT = 'v2rayN/6.38';

    public function __construct(
        private SingBoxOutboundParser $parser,
        private ClashSubscriptionParser $clashParser,
    ) {}

    /**
     * Download the exact subscription URL and parse every node line-by-line.
     *
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

        return $this->parseBody($this->download($url));
    }

    /**
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>
     */
    public function fetchMerged(string $url, ?string $body = null): array
    {
        $url = trim($url);
        $body = $body !== null ? trim($body) : '';

        // Prefer the exact URL content. Body is only a fallback when URL is empty.
        if ($url !== '') {
            return $this->fetch($url);
        }

        if ($body !== '') {
            return $this->parseBody($body);
        }

        throw new RuntimeException(__('resolver.subscription_parse_failed'));
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

        $decoded = $this->tryBase64Decode($body);
        if ($decoded !== null) {
            $body = $decoded;
        }

        // 1) Line-by-line share URIs (vless://, ss://, ...) — primary path for vpnd-like subs.
        $nodes = $this->parseShareUriLines($body);
        if ($nodes !== []) {
            return $nodes;
        }

        // 2) Clash YAML
        $clashNodes = $this->clashParser->parse($body);
        if (is_array($clashNodes) && $clashNodes !== []) {
            return $clashNodes;
        }

        // 3) sing-box JSON
        $singBoxNodes = $this->parseSingBoxJsonOutbounds($body);
        if ($singBoxNodes !== []) {
            return $singBoxNodes;
        }

        throw new RuntimeException(__('resolver.subscription_no_nodes'));
    }

    /**
     * One node per non-empty share-URI line (comments / blanks skipped).
     *
     * @return list<array{key: string, name: string, type: string, server: string, port: int, outbound: array<string, mixed>}>
     */
    private function parseShareUriLines(string $body): array
    {
        $nodes = [];
        $seen = [];

        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! $this->isShareUri($line)) {
                continue;
            }

            try {
                $outbound = $this->parser->normalize($this->parser->fromShareUrl($line));
            } catch (\Throwable) {
                continue;
            }
            if (empty($outbound['type']) || empty($outbound['server'])) {
                continue;
            }

            $key = $this->nodeKey($line);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $nodes[] = [
                'key' => $key,
                'name' => $this->nodeName($line, $outbound),
                'type' => (string) $outbound['type'],
                'server' => (string) $outbound['server'],
                'port' => (int) ($outbound['server_port'] ?? 0),
                'outbound' => $outbound,
            ];
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
            if (! is_array($ob) || empty($ob['type'])) {
                continue;
            }
            $type = (string) $ob['type'];
            if (in_array($type, ['direct', 'block', 'dns', 'selector', 'urltest', 'fallback'], true)) {
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
