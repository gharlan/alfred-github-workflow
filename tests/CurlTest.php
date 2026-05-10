<?php

use PHPUnit\Framework\Attributes\DataProvider;

final class CurlTest extends HttpServerTestCase
{
    protected static function routerPath(): string
    {
        return __DIR__ . '/fixtures/curl-server.php';
    }

    #[DataProvider('dataGetHeader')]
    public function testGetHeader(string $headers, string $key, ?string $expected): void
    {
        self::assertSame($expected, Curl::getHeader($headers, $key));
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function dataGetHeader(): iterable
    {
        yield 'simple header' => [
            "Content-Type: text/plain\r\n",
            'Content-Type',
            'text/plain',
        ];
        yield 'key match is case-insensitive' => [
            "content-type: foo\r\n",
            'Content-Type',
            'foo',
        ];
        yield 'finds header among many' => [
            "Foo: 1\r\nBar: 2\r\nBaz: 3\r\n",
            'Bar',
            '2',
        ];
        yield 'value preserves inner spaces' => [
            "Link: <https://api.example.com/x?page=2>; rel=\"next\"\r\n",
            'Link',
            '<https://api.example.com/x?page=2>; rel="next"',
        ];
        yield 'returns null when missing' => [
            "Foo: 1\r\n",
            'Bar',
            null,
        ];
        yield 'returns first occurrence on duplicate' => [
            "X: first\r\nX: second\r\n",
            'X',
            'first',
        ];
        yield 'empty header string returns null' => [
            '',
            'X',
            null,
        ];
        yield 'value stops at line break' => [
            "ETag: \"abc\"\r\nNext: ignored\r\n",
            'ETag',
            '"abc"',
        ];
    }

    public function testExecuteFetchesBodyStatusAndResponseHeaders(): void
    {
        /** @var list<CurlResponse> $received */
        $received = [];
        $request = new CurlRequest(
            self::baseUrl() . '/hello',
            null,
            null,
            static function (CurlResponse $r) use (&$received): void {
                $received[] = $r;
            },
        );
        $curl = new Curl();
        $curl->add($request);
        self::assertTrue($curl->execute());

        self::assertCount(1, $received);
        $response = $received[0];

        self::assertSame(200, $response->status);
        self::assertSame('Hello, World!', $response->content);
        self::assertSame('"etag-hello"', $response->etag);
        self::assertNotNull($response->contentType);
        self::assertStringStartsWith('text/plain', $response->contentType);
        self::assertSame('<https://example.com/next>; rel="next"', $response->link);
        self::assertSame($request, $response->request);
    }

    public function testExecuteSendsAuthorizationTokenAndUserAgent(): void
    {
        /** @var list<CurlResponse> $received */
        $received = [];
        $curl = new Curl();
        $curl->add(new CurlRequest(
            self::baseUrl() . '/echo-headers',
            null,
            'secret-token',
            static function (CurlResponse $r) use (&$received): void {
                $received[] = $r;
            },
        ));
        $curl->execute();

        self::assertCount(1, $received);
        self::assertNotNull($received[0]->content);
        $body = json_decode($received[0]->content, true);
        self::assertIsArray($body);
        self::assertSame('token secret-token', $body['authorization']);
        self::assertSame('alfred-github-workflow', $body['user-agent']);
        self::assertSame(self::baseUrl() . '/echo-headers', $body['x-url']);
    }

    public function testExecuteOmitsAuthorizationHeaderWhenTokenIsNull(): void
    {
        /** @var list<CurlResponse> $received */
        $received = [];
        $curl = new Curl();
        $curl->add(new CurlRequest(
            self::baseUrl() . '/echo-headers',
            null,
            null,
            static function (CurlResponse $r) use (&$received): void {
                $received[] = $r;
            },
        ));
        $curl->execute();

        self::assertNotNull($received[0]->content);
        $body = json_decode($received[0]->content, true);
        self::assertIsArray($body);
        self::assertSame('', $body['authorization']);
    }

    public function testExecuteSendsIfNoneMatchAndHandles304(): void
    {
        /** @var list<CurlResponse> $received */
        $received = [];
        $curl = new Curl();
        $curl->add(new CurlRequest(
            self::baseUrl() . '/etag',
            '"v1"',
            null,
            static function (CurlResponse $r) use (&$received): void {
                $received[] = $r;
            },
        ));
        $curl->execute();

        self::assertCount(1, $received);
        self::assertSame(304, $received[0]->status);
        self::assertNull($received[0]->content);
        self::assertSame('"v1"', $received[0]->etag);
    }

    public function testExecuteFetchesFreshContentWhenEtagDoesNotMatch(): void
    {
        /** @var list<CurlResponse> $received */
        $received = [];
        $curl = new Curl();
        $curl->add(new CurlRequest(
            self::baseUrl() . '/etag',
            '"stale"',
            null,
            static function (CurlResponse $r) use (&$received): void {
                $received[] = $r;
            },
        ));
        $curl->execute();

        self::assertSame(200, $received[0]->status);
        self::assertSame('fresh content', $received[0]->content);
        self::assertSame('"v1"', $received[0]->etag);
    }

    public function testExecuteDoesNotStoreContentForNon200Status(): void
    {
        /** @var list<CurlResponse> $received */
        $received = [];
        $curl = new Curl();
        $curl->add(new CurlRequest(
            self::baseUrl() . '/server-error',
            null,
            null,
            static function (CurlResponse $r) use (&$received): void {
                $received[] = $r;
            },
        ));
        $curl->execute();

        self::assertSame(500, $received[0]->status);
        self::assertNull($received[0]->content);
    }

    public function testExecuteRunsRequestsInParallel(): void
    {
        /** @var array<string, string> $bodies */
        $bodies = [];
        $curl = new Curl();
        $ids = ['a', 'b', 'c', 'd', 'e'];
        foreach ($ids as $id) {
            $curl->add(new CurlRequest(
                self::baseUrl() . '/echo-id?id=' . $id,
                null,
                null,
                static function (CurlResponse $r) use (&$bodies, $id): void {
                    $bodies[$id] = (string) $r->content;
                },
            ));
        }

        $start = microtime(true);
        $curl->execute();
        $elapsed = microtime(true) - $start;

        // Order of arrival depends on parallel completion; normalize before comparing.
        ksort($bodies);
        self::assertSame(
            ['a' => 'id-a', 'b' => 'id-b', 'c' => 'id-c', 'd' => 'id-d', 'e' => 'id-e'],
            $bodies,
        );
        // Each request sleeps 20ms server-side. Sequential = ~100ms; parallel ≈ ~20-40ms.
        // Allow a generous ceiling of 80ms so we still catch a regression to sequential.
        self::assertLessThan(0.08, $elapsed, "5 parallel requests took {$elapsed}s, expected < 0.08s");
    }
}
