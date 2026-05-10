<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkflowTest extends TestCase
{
    private static string $tmpDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/agw-workflow-test-' . bin2hex(random_bytes(6));
        mkdir(self::$tmpDir, 0o755, true);
        putenv('alfred_workflow_data=' . self::$tmpDir);
    }

    public static function tearDownAfterClass(): void
    {
        self::resetStaticState();

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
        self::reInit();
        Workflow::getStatement('DELETE FROM config')->execute();
        Workflow::deleteCache();
    }

    private static function resetStaticState(): void
    {
        foreach (['items', 'refreshUrls', 'statements'] as $name) {
            $prop = new ReflectionProperty(Workflow::class, $name);
            $prop->setValue(null, []);
        }

        // Workflow::init() only mutates the URL state inside the enterprise branch,
        // never restores it. Reset to class defaults so iterations don't leak into each other.
        foreach ([
            'baseUrl' => 'https://github.com',
            'apiUrl' => 'https://api.github.com',
            'gistUrl' => 'https://gist.github.com',
        ] as $name => $default) {
            $prop = new ReflectionProperty(Workflow::class, $name);
            $prop->setValue(null, $default);
        }
    }

    /** Re-init Workflow after wiping state, so prepared statements bind to the freshly-created PDO. */
    private static function reInit(bool $enterprise = false, ?string $query = null): void
    {
        self::resetStaticState();
        Workflow::init(enterprise: $enterprise, query: $query);
    }

    #[DataProvider('dataGetApiUrl')]
    public function testGetApiUrl(?string $path, string $expected): void
    {
        self::assertSame($expected, Workflow::getApiUrl($path));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function dataGetApiUrl(): iterable
    {
        yield 'no path returns base api url' => [null, 'https://api.github.com'];
        yield 'path without query gets ?per_page=100' => ['/user', 'https://api.github.com/user?per_page=100'];
        yield 'path with query gets &per_page=100' => ['/search?q=foo', 'https://api.github.com/search?q=foo&per_page=100'];
    }

    public function testAccessTokenIsScopedByEnterpriseMode(): void
    {
        Workflow::setAccessToken('user-token');
        self::assertSame('user-token', Workflow::getAccessToken());
        self::assertSame('user-token', Workflow::getConfig('access_token'));
        self::assertNull(Workflow::getConfig('enterprise_access_token'));

        // Switching to enterprise mode swaps the config key getAccessToken reads.
        self::reInit(enterprise: true);
        self::assertNull(Workflow::getAccessToken(), 'enterprise mode must not see the user token');

        Workflow::setAccessToken('enterprise-token');
        self::assertSame('enterprise-token', Workflow::getAccessToken());
        self::assertSame('enterprise-token', Workflow::getConfig('enterprise_access_token'));
        // Writes in enterprise mode must not touch the user token.
        self::assertSame('user-token', Workflow::getConfig('access_token'));

        // removeAccessToken targets the current mode's key only.
        Workflow::removeAccessToken();
        self::assertNull(Workflow::getAccessToken());
        self::assertSame('user-token', Workflow::getConfig('access_token'));
    }

    #[DataProvider('dataGetConfigDefault')]
    public function testGetConfigDefault(?string $stored, mixed $default, mixed $expected): void
    {
        if (null !== $stored) {
            Workflow::setConfig('key', $stored);
        }
        self::assertSame($expected, Workflow::getConfig('key', $default));
    }

    /** @return iterable<string, array{?string, mixed, mixed}> */
    public static function dataGetConfigDefault(): iterable
    {
        yield 'missing key returns null without default' => [null, null, null];
        yield 'missing key returns the supplied default' => [null, 'fallback', 'fallback'];
        yield 'present key wins over default' => ['stored', 'fallback', 'stored'];
    }

    public function testCleanCacheRemovesOnlyOldRows(): void
    {
        $now = time();
        $oldUrl = 'https://example.com/old';
        $recentUrl = 'https://example.com/recent';

        $insert = Workflow::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0, NULL)');
        $insert->execute([$oldUrl, $now - 101 * 86400, null, '{}']);
        $insert->execute([$recentUrl, $now - 99 * 86400, null, '{}']);

        Workflow::cleanCache();

        $stmt = Workflow::getStatement('SELECT url FROM request_cache ORDER BY url');
        $stmt->execute();
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([$recentUrl], $remaining);
    }

    public function testAddItemIfMatchesFiltersByQuery(): void
    {
        self::reInit(query: 'foo');

        Workflow::addItemIfMatches(Item::create()->title('foo'));
        Workflow::addItemIfMatches(Item::create()->title('bar'));

        $rendered = Workflow::getItemsAsXml();
        self::assertIsString($rendered);
        $xml = simplexml_load_string($rendered);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        self::assertCount(1, $xml->item);
        self::assertSame('foo', (string) $xml->item[0]->title);
    }

    #[DataProvider('dataInitEnterpriseUrls')]
    public function testInitEnterpriseUrls(?string $enterpriseUrl, ?string $expectedBaseUrl, ?string $expectedApiUrl, ?string $expectedGistUrl): void
    {
        if (null !== $enterpriseUrl) {
            Workflow::setConfig('enterprise_url', $enterpriseUrl);
        }
        self::reInit(enterprise: true);

        self::assertSame($expectedBaseUrl, Workflow::getBaseUrl());
        self::assertSame($expectedApiUrl, Workflow::getApiUrl());
        self::assertSame($expectedGistUrl, Workflow::getGistUrl());
    }

    /** @return iterable<string, array{?string, ?string, ?string, ?string}> */
    public static function dataInitEnterpriseUrls(): iterable
    {
        yield 'unset enterprise_url collapses URLs to null' => [null, null, null, null];
        yield 'custom host derives api/gist subpaths' => [
            'https://ghe.example.com',
            'https://ghe.example.com',
            'https://ghe.example.com/api/v3',
            'https://ghe.example.com/gist',
        ];
        yield 'github.com /enterprises/<slug> keeps default URLs (cloud mode)' => [
            'https://github.com/enterprises/foo',
            'https://github.com',
            'https://api.github.com',
            'https://gist.github.com',
        ];
    }

    #[DataProvider('dataCheckUpdate')]
    public function testCheckUpdate(int $autoupdate, ?string $cachedTag, bool $expected): void
    {
        Workflow::setConfig('autoupdate', $autoupdate);

        if (null !== $cachedTag) {
            // Pre-populate the release endpoint so Fetcher serves from cache instead of
            // hitting the real api.github.com.
            Workflow::getStatement('REPLACE INTO request_cache VALUES(?, ?, ?, ?, 0, NULL)')
                ->execute([
                    'https://api.github.com/repos/gharlan/alfred-github-workflow/releases/latest',
                    time(),
                    null,
                    json_encode(['tag_name' => $cachedTag]),
                ]);
        }

        self::assertSame($expected, Workflow::checkUpdate());
    }

    /** @return iterable<string, array{int, ?string, bool}> */
    public static function dataCheckUpdate(): iterable
    {
        yield 'autoupdate disabled never reports an update' => [0, null, false];
        yield 'cached current version is not an update' => [1, 'v' . Workflow::VERSION, false];
        yield 'cached older version is not an update' => [1, 'v0.0.1', false];
        yield 'cached newer version is an update' => [1, 'v99.99.99', true];
    }
}
