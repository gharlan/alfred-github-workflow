<?php

final class FetcherTest extends HttpServerTestCase
{
    private static string $tmpDir = '';
    private static string $hitsFile = '';

    protected static function routerPath(): string
    {
        return __DIR__ . '/fixtures/fetcher-server.php';
    }

    protected static function serverEnv(): array
    {
        return ['FETCHER_HITS_FILE' => self::$hitsFile];
    }

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/agw-fetcher-test-' . bin2hex(random_bytes(6));
        mkdir(self::$tmpDir, 0o755, true);
        putenv('alfred_workflow_data=' . self::$tmpDir);

        Workflow::init();

        self::$hitsFile = self::$tmpDir . '/hits.log';

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        // Avoid the Workflow shutdown handler firing background-refresh subprocesses on PHP exit.
        self::clearRefreshUrls();

        if (is_dir(self::$tmpDir)) {
            $files = glob(self::$tmpDir . '/*') ?: [];
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir(self::$tmpDir);
        }
    }

    protected function setUp(): void
    {
        Workflow::deleteCache();
        file_put_contents(self::$hitsFile, '');
        self::clearRefreshUrls();
    }

    private static function clearRefreshUrls(): void
    {
        $prop = new ReflectionProperty(Workflow::class, 'refreshUrls');
        $prop->setValue(null, []);
    }

    private function totalHits(): int
    {
        $contents = file_get_contents(self::$hitsFile);
        if (false === $contents || '' === $contents) {
            return 0;
        }

        return substr_count($contents, "\n");
    }

    public function testRequestUrlFetchesAndCachesJson(): void
    {
        $url = self::baseUrl() . '/json?id=hello';

        $first = Fetcher::requestUrl($url);
        self::assertInstanceOf(stdClass::class, $first);
        self::assertSame('hello', $first->id);
        self::assertSame('val-hello', $first->value);
        self::assertSame(1, $this->totalHits());

        // Second call within maxAge window: served from cache, no HTTP roundtrip.
        $second = Fetcher::requestUrl($url);
        self::assertInstanceOf(stdClass::class, $second);
        self::assertSame('hello', $second->id);
        self::assertSame(1, $this->totalHits());
    }

    public function testRequestUrlFollowsPaginationViaLinkHeader(): void
    {
        $url = self::baseUrl() . '/paginated?p=1';

        $merged = Fetcher::requestUrl($url);
        self::assertIsArray($merged);
        self::assertCount(6, $merged);
        self::assertSame(1, $merged[0]->page);
        self::assertSame(3, $merged[5]->page);
        self::assertSame(3, $this->totalHits());

        // Re-request: full chain served from cache via parent linkage, no extra hits.
        $cached = Fetcher::requestUrl($url);
        self::assertCount(6, $cached);
        self::assertSame(3, $this->totalHits());
    }

    public function testRequestUrlFirstPageOnlyStopsAfterOneRequest(): void
    {
        $first = Fetcher::requestUrl(
            self::baseUrl() . '/paginated?p=1',
            new FetchOptions(firstPageOnly: true),
        );

        self::assertIsArray($first);
        self::assertCount(2, $first);
        self::assertSame(1, $first[0]->page);
        self::assertSame(1, $this->totalHits());
    }

    public function testRequestUrlNonOkDeletesCacheRow(): void
    {
        $url = self::baseUrl() . '/error500';

        // Pre-populate so we can observe deletion.
        Workflow::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0, NULL)')
            ->execute([$url, time() - 3600, '"x"', json_encode(['x' => 1])]);

        $result = Fetcher::requestUrl($url, new FetchOptions(refreshInBackground: false));
        self::assertSame([], $result);

        $stmt = Workflow::getStatement('SELECT 1 FROM request_cache WHERE url = ?');
        $stmt->execute([$url]);
        self::assertFalse($stmt->fetchColumn());
    }

    public function testRequestUrlReusesCachedContentOn304(): void
    {
        $url = self::baseUrl() . '/etag?v=v1';

        Fetcher::requestUrl($url);
        self::assertSame(1, $this->totalHits());

        // Make stale; refreshInBackground=false forces a synchronous live fetch.
        Workflow::getStatement('UPDATE request_cache SET timestamp = ? WHERE url = ?')
            ->execute([time() - 3600, $url]);

        $second = Fetcher::requestUrl($url, new FetchOptions(refreshInBackground: false));
        self::assertSame('v1', $second->value);
        self::assertSame(2, $this->totalHits());
    }

    public function testRequestUrlStaleWithBackgroundRefreshServesCacheAndMarksUrl(): void
    {
        $url = self::baseUrl() . '/json?id=stale';

        Fetcher::requestUrl($url);
        self::assertSame(1, $this->totalHits());

        // Force stale + outside the 3-minute background-refresh debounce.
        Workflow::getStatement('UPDATE request_cache SET timestamp = ?, refresh = ? WHERE url = ?')
            ->execute([time() - 3600, time() - 3600, $url]);

        $cached = Fetcher::requestUrl($url);
        // Stale-while-revalidate: cached content served, no live fetch.
        self::assertSame(1, $this->totalHits());
        self::assertInstanceOf(stdClass::class, $cached);
        self::assertSame('stale', $cached->id);

        $prop = new ReflectionProperty(Workflow::class, 'refreshUrls');
        $refreshUrls = $prop->getValue();
        self::assertIsArray($refreshUrls);
        self::assertArrayHasKey($url, $refreshUrls);
    }

    public function testRequestUrlUnwrapsItemsKey(): void
    {
        $result = Fetcher::requestUrl(self::baseUrl() . '/items-wrapper');

        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertSame('one', $result[0]->name);
        self::assertSame('two', $result[1]->name);
    }

    public function testRequestUrlAppliesFieldWhitelist(): void
    {
        $options = new FetchOptions(fields: [
            'sha',
            'commit' => ['author' => ['date']],
        ]);

        $result = Fetcher::requestUrl(self::baseUrl() . '/picky', $options);

        self::assertInstanceOf(stdClass::class, $result);
        self::assertSame('abc', $result->sha);
        self::assertObjectNotHasProperty('extra', $result);
        self::assertObjectNotHasProperty('message', $result);
        self::assertInstanceOf(stdClass::class, $result->commit);
        self::assertInstanceOf(stdClass::class, $result->commit->author);
        self::assertSame('2024-01-01', $result->commit->author->date);
        self::assertObjectNotHasProperty('name', $result->commit->author);
    }

    public function testRequestUrlReencodesNonJsonResponse(): void
    {
        $result = Fetcher::requestUrl(self::baseUrl() . '/non-json');

        self::assertSame('Hello, World!', $result);
    }

    public function testRequestRawReturnsBodyAndDoesNotCache(): void
    {
        $url = self::baseUrl() . '/non-json';
        $body = Fetcher::requestRaw($url);

        self::assertSame('Hello, World!', $body);

        $stmt = Workflow::getStatement('SELECT 1 FROM request_cache WHERE url = ?');
        $stmt->execute([$url]);
        self::assertFalse($stmt->fetchColumn());
    }

    public function testStreamUrlYieldsEachItemFromCacheChain(): void
    {
        $url = self::baseUrl() . '/paginated?p=1';
        Fetcher::requestUrl($url);
        $hitsBefore = $this->totalHits();

        $items = iterator_to_array(Fetcher::streamUrl($url), false);

        self::assertCount(6, $items);
        self::assertSame(1, $items[0]->page);
        self::assertSame(1, $items[0]->item);
        self::assertSame(3, $items[5]->page);
        self::assertSame(6, $items[5]->item);
        // Walks cache only — no fresh HTTP.
        self::assertSame($hitsBefore, $this->totalHits());
    }

    public function testStreamUrlFirstPageOnlyStopsAtFirstPage(): void
    {
        $url = self::baseUrl() . '/paginated?p=1';
        Fetcher::requestUrl($url);

        $items = iterator_to_array(
            Fetcher::streamUrl($url, new FetchOptions(firstPageOnly: true)),
            false,
        );

        self::assertCount(2, $items);
        self::assertSame(1, $items[0]->page);
    }

    public function testQueueRunDispatchesAllRequests(): void
    {
        // 5 requests through one Fetcher. Parallelism is structural (curl_multi_* under the
        // hood) — we assert correctness rather than timing, since CI runners' worker scheduling
        // is too noisy to derive a stable wall-clock threshold.
        /** @var array<string, mixed> $bodies */
        $bodies = [];
        $f = new Fetcher();
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $f->queueUrl(
                self::baseUrl() . '/slow?id=' . $id,
                static function ($r) use (&$bodies, $id): void {
                    $bodies[$id] = $r instanceof stdClass ? $r->id : null;
                },
            );
        }
        $f->run();

        ksort($bodies);
        self::assertSame(
            ['a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e'],
            $bodies,
        );
    }
}
