<?php

use PHPUnit\Framework\TestCase;

/**
 * CurlRequest is the per-request token carrier. The fact that the token is
 * a constructor argument (not global state) is the load-bearing property
 * that lets multi-account work avoid touching the network layer — pin it.
 */
final class CurlRequestTest extends TestCase
{
    public function testConstructorAssignsAllFields(): void
    {
        $callback = static function () {};
        $request = new CurlRequest('https://api.github.com/user', 'etag-1', 'tok', $callback);

        $this->assertSame('https://api.github.com/user', $request->url);
        $this->assertSame('etag-1', $request->etag);
        $this->assertSame('tok', $request->token);
        $this->assertSame($callback, $request->callback);
    }

    public function testTokenMayBeNullForUnauthenticatedRequests(): void
    {
        $request = new CurlRequest('https://api.github.com/', null, null, static function () {});

        $this->assertNull($request->token);
        $this->assertNull($request->etag);
    }
}
