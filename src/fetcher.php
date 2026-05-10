<?php

require_once __DIR__ . '/curl.php';

/** @phpstan-type TFields array<int|string, string|array<int|string, mixed>> */
final readonly class FetchOptions
{
    /**
     * @param TFields|null $fields declarative field whitelist applied before storing in the cache, so large list
     *     endpoints (commits, issues) only persist what the consumer actually reads. Mix flat names and nested
     *     specs: `['sha', 'commit' => ['message', 'author' => ['date']]]`. An empty sub-spec keeps the property
     *     as an empty stdClass marker.
     * @param bool $stream return a Generator that yields items page-by-page from the cache instead of merging
     *     all pages into one array. Bounds peak memory at one page even on huge paginated endpoints. Single-pass
     *     only — Generators can't be re-iterated.
     */
    public function __construct(
        public bool $firstPageOnly = false,
        public ?array $fields = null,
        public int $maxAgeMinutes = 10,
        public bool $refreshInBackground = true,
        public bool $stream = false,
    ) {}

    public function withStream(bool $stream): self
    {
        return new self($this->firstPageOnly, $this->fields, $this->maxAgeMinutes, $this->refreshInBackground, $stream);
    }
}

/** @phpstan-import-type TFields from FetchOptions */
final readonly class Fetcher
{
    private Curl $curl;

    public function __construct()
    {
        $this->curl = new Curl();
    }

    /** Synchronously fetches a raw response body, no cache. */
    public static function requestRaw(string $url, bool $auth = true): ?string
    {
        Workflow::log('loading content for %s', $url);
        $curl = new Curl();
        $captured = null;
        $token = $auth ? Workflow::getAccessToken() : null;
        $curl->add(new CurlRequest($url, null, $token, static function (CurlResponse $response) use (&$captured): void {
            if (isset($response->content)) {
                $captured = $response->content;
            }
        }));
        $curl->execute();

        return $captured;
    }

    /** Synchronously requests a JSON-decoded, cached response from an absolute URL. */
    public static function requestUrl(string $url, ?FetchOptions $options = null): mixed
    {
        $f = new self();
        $captured = null;
        $f->queueUrl($url, static function ($content) use (&$captured): void {
            $captured = $content;
        }, $options);
        $f->run();

        return $captured;
    }

    /** Synchronously requests a JSON-decoded, cached response from a GitHub API path. */
    public static function requestApi(string $path, ?FetchOptions $options = null): mixed
    {
        return self::requestUrl(Workflow::getApiUrl($path), $options);
    }

    /**
     * Synchronously requests a paginated absolute URL and returns a Generator that yields list items
     * page-by-page from the cache. Forces stream mode regardless of `$options->stream`. Single-pass.
     *
     * @return Generator<int, mixed> typically yields stdClass items for GitHub list endpoints
     */
    public static function streamUrl(string $url, ?FetchOptions $options = null): Generator
    {
        $options = ($options ?? new FetchOptions())->withStream(true);

        $f = new self();
        $captured = null;
        $f->queueUrl($url, static function (Generator $generator) use (&$captured): void {
            $captured = $generator;
        }, $options);
        $f->run();

        return $captured;
    }

    /**
     * Like {@see streamUrl()} but resolves a GitHub API path.
     *
     * @return Generator<int, mixed>
     */
    public static function streamApi(string $path, ?FetchOptions $options = null): Generator
    {
        return self::streamUrl(Workflow::getApiUrl($path), $options);
    }

    /**
     * Queue a GitHub API request for batch execution (path is resolved against the configured API base URL).
     *
     * @param (callable(mixed): void)|null $callback receives the JSON-decoded response when {@see run()} executes;
     *     receives a `Generator<int, mixed>` instead if `$options->stream` is true
     */
    public function queueApi(string $path, ?callable $callback = null, ?FetchOptions $options = null): self
    {
        return $this->queueUrl(Workflow::getApiUrl($path), $callback, $options);
    }

    /**
     * Queue an absolute-URL request for batch execution.
     *
     * @param (callable(mixed): void)|null $callback receives the JSON-decoded response when {@see run()} executes;
     *     receives a `Generator<int, mixed>` instead if `$options->stream` is true
     */
    public function queueUrl(string $url, ?callable $callback = null, ?FetchOptions $options = null): self
    {
        $this->enqueue($url, $callback, $options ?? new FetchOptions());

        return $this;
    }

    /** Execute every queued request in parallel; callbacks fire as responses come in. */
    public function run(): void
    {
        $this->curl->execute();
    }

    /** @param (callable(mixed): void)|null $callback */
    private function enqueue(string $url, ?callable $callback, FetchOptions $options): void
    {
        $stmt = Workflow::getStatement('SELECT * FROM request_cache WHERE url = ?');
        $stmt->execute([$url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $timestamp = (int) ($row['timestamp'] ?? 0);
        $etag = $row['etag'] ?? null;
        $cachedContent = $row['content'] ?? null;
        $lastBackgroundRefresh = (int) ($row['refresh'] ?? 0);

        $shouldRefresh = $timestamp < time() - 60 * $options->maxAgeMinutes;
        $useBackgroundRefresh = $options->refreshInBackground && null !== $cachedContent;

        if ($shouldRefresh && $useBackgroundRefresh && $lastBackgroundRefresh < time() - 3 * 60) {
            Workflow::getStatement('UPDATE request_cache SET refresh = ? WHERE url = ?')->execute([time(), $url]);
            Workflow::markUrlForBackgroundRefresh($url);
        }

        if (!$shouldRefresh || $useBackgroundRefresh) {
            $this->serveFromCache($url, $cachedContent, $callback, $options);

            return;
        }

        Workflow::log('loading content for %s', $url);
        $this->dispatch($url, $etag, $cachedContent, null, $url, [], $callback, $options);
    }

    /** @param (callable(mixed): void)|null $callback */
    private function serveFromCache(string $url, ?string $rawContent, ?callable $callback, FetchOptions $options): void
    {
        Workflow::log('using cached content for %s', $url);

        if ($options->stream) {
            if ($callback) {
                $callback($this->streamFromCache($url, $options->firstPageOnly));
            }

            return;
        }

        $merged = json_decode((string) $rawContent);

        if (!$options->firstPageOnly) {
            $stmt = Workflow::getStatement('SELECT url, content FROM request_cache WHERE parent = ? ORDER BY `timestamp` DESC');
            $hasChildren = false;
            while ($stmt->execute([$url]) && $data = $stmt->fetchObject()) {
                if (!$hasChildren) {
                    $merged = self::asList($merged);
                    $hasChildren = true;
                }
                $merged = array_merge($merged, self::asList(json_decode($data->content)));
                $url = $data->url;
            }
        }

        if ($callback) {
            $callback($merged);
        }
    }

    /**
     * @param list<mixed> $accumulator decoded JSON pages collected so far in this paginated chain
     * @param (callable(mixed): void)|null $callback
     */
    private function dispatch(string $url, ?string $etag, ?string $cachedContent, ?string $parent, string $rootUrl, array $accumulator, ?callable $callback, FetchOptions $options): void
    {
        $this->curl->add(new CurlRequest($url, $etag, Workflow::getAccessToken(), function (CurlResponse $response) use ($cachedContent, $parent, $rootUrl, $accumulator, $callback, $options): void {
            $this->handleResponse($response, $cachedContent, $parent, $rootUrl, $accumulator, $callback, $options);
        }));
    }

    /**
     * @param list<mixed> $accumulator
     * @param (callable(mixed): void)|null $callback
     */
    private function handleResponse(CurlResponse $response, ?string $cachedContent, ?string $parent, string $rootUrl, array $accumulator, ?callable $callback, FetchOptions $options): void
    {
        $url = $response->request->url;

        if (!in_array($response->status, [200, 304], true)) {
            Workflow::getStatement('DELETE FROM request_cache WHERE url = ?')->execute([$url]);
            $this->finish($rootUrl, $accumulator, $callback, $options);

            return;
        }

        $isNotModified = 304 === $response->status;
        if ($isNotModified) {
            $response->content = $cachedContent;
        } elseif (false === stripos((string) $response->contentType, 'json')) {
            $response->content = json_encode($response->content, JSON_THROW_ON_ERROR);
        }

        $decoded = json_decode((string) $response->content);
        if (isset($decoded->items)) {
            $decoded = $decoded->items;
        }
        if ($options->fields && !$isNotModified) {
            $decoded = self::pickFields($options->fields, $decoded);
        }
        if (!$options->stream) {
            $accumulator[] = $decoded;
        }

        Workflow::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0, ?)')
            ->execute([$url, time(), $response->etag, json_encode($decoded), $parent]);

        if ($options->firstPageOnly) {
            $this->finish($rootUrl, $accumulator, $callback, $options);

            return;
        }

        [$nextUrl, $nextEtag, $nextCached] = $this->resolveNextPage($url, $response, $isNotModified);

        if (null !== $nextUrl) {
            $this->dispatch($nextUrl, $nextEtag, $nextCached, $url, $rootUrl, $accumulator, $callback, $options);

            return;
        }

        Workflow::getStatement('DELETE FROM request_cache WHERE parent = ?')->execute([$url]);
        $this->finish($rootUrl, $accumulator, $callback, $options);
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function resolveNextPage(string $url, CurlResponse $response, bool $isNotModified): array
    {
        $stmt = Workflow::getStatement('SELECT url, etag, content FROM request_cache WHERE parent = ?');
        $stmt->execute([$url]);
        $cachedChild = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($isNotModified) {
            if (!$cachedChild) {
                return [null, null, null];
            }

            return [$cachedChild['url'], $cachedChild['etag'], $cachedChild['content']];
        }

        if ($response->link && preg_match('/<([^<>]+)>; rel="next"/U', $response->link, $match)) {
            return [$match[1], $cachedChild['etag'] ?? null, $cachedChild['content'] ?? null];
        }

        return [null, null, null];
    }

    /**
     * @param list<mixed> $accumulator
     * @param (callable(mixed): void)|null $callback
     */
    private function finish(string $rootUrl, array $accumulator, ?callable $callback, FetchOptions $options): void
    {
        if (!$callback) {
            return;
        }
        if ($options->stream) {
            $callback($this->streamFromCache($rootUrl, $options->firstPageOnly));

            return;
        }
        if (empty($accumulator)) {
            $callback([]);

            return;
        }
        if (1 === count($accumulator)) {
            $callback($accumulator[0]);

            return;
        }
        $callback(array_reduce($accumulator, static fn ($carry, $page) => array_merge($carry, self::asList($page)), []));
    }

    /**
     * Walk the cache chain starting at $rootUrl and yield items page-by-page.
     *
     * Safe wrt the shared Workflow statement cache because each cache row is read in full via fetchColumn()
     * before any yield, so no PDO cursor stays open across consumer-controlled pause points.
     *
     * @return Generator<int, mixed>
     */
    private function streamFromCache(string $rootUrl, bool $firstPageOnly): Generator
    {
        $contentStmt = Workflow::getStatement('SELECT content FROM request_cache WHERE url = ?');
        $childStmt = Workflow::getStatement('SELECT url FROM request_cache WHERE parent = ? ORDER BY `timestamp` DESC');

        $url = $rootUrl;
        while (true) {
            $contentStmt->execute([$url]);
            $content = $contentStmt->fetchColumn();
            if (false === $content) {
                return;
            }
            assert(is_string($content));
            $page = json_decode($content);
            if (is_array($page)) {
                foreach ($page as $item) {
                    yield $item;
                }
            } else {
                yield $page;
            }
            if ($firstPageOnly) {
                return;
            }
            $childStmt->execute([$url]);
            $next = $childStmt->fetchColumn();
            if (false === $next) {
                return;
            }
            $url = $next;
        }
    }

    /**
     * Coerce decoded JSON to a list so it can be safely merged during pagination.
     *
     * Defends against corrupt cache rows (null) or endpoints that occasionally return a single
     * object where a list was expected — both used to crash array_merge.
     *
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        return is_array($value) ? array_values($value) : [$value];
    }

    /**
     * Apply a declarative pick-fields whitelist to an API response, preserving the original nested structure.
     *
     * Spec format: a list of property names, optionally `name => subSpec` for nested objects.
     * An empty subSpec keeps the property as an empty stdClass (useful for `isset` markers).
     *
     * @param TFields $spec
     * @param mixed $value stdClass, array of stdClass, or scalar — arrays are mapped element-wise
     */
    private static function pickFields(array $spec, mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn ($item) => self::pickFields($spec, $item), $value);
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        $picked = new stdClass();
        foreach ($spec as $key => $sub) {
            if (is_int($key)) {
                assert(is_string($sub));
                if (property_exists($value, $sub)) {
                    $picked->$sub = $value->$sub;
                }

                continue;
            }

            assert(is_array($sub));
            if (property_exists($value, $key)) {
                $picked->$key = self::pickFields($sub, $value->$key);
            }
        }

        return $picked;
    }
}
