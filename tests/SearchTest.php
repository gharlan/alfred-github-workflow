<?php

require_once __DIR__ . '/../src/search.php';

final class SearchTest extends HttpServerTestCase
{
    private static string $tmpDir = '';

    protected static function routerPath(): string
    {
        return __DIR__ . '/fixtures/search-server.php';
    }

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/agw-search-test-' . bin2hex(random_bytes(6));
        mkdir(self::$tmpDir, 0o755, true);
        putenv('alfred_workflow_data=' . self::$tmpDir);

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

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
        self::resetStaticState();
        Workflow::init();
        Workflow::getStatement('DELETE FROM config')->execute();
        Workflow::deleteCache();

        // Point the enterprise API at the local test server and skip the update check.
        Workflow::setConfig('enterprise_url', self::baseUrl());
        Workflow::setConfig('autoupdate', 0);
    }

    private static function resetStaticState(): void
    {
        foreach (['items', 'refreshUrls', 'statements'] as $name) {
            $prop = new ReflectionProperty(Workflow::class, $name);
            $prop->setValue(null, []);
        }

        foreach ([
            'baseUrl' => 'https://github.com',
            'apiUrl' => 'https://api.github.com',
            'gistUrl' => 'https://gist.github.com',
        ] as $name => $default) {
            $prop = new ReflectionProperty(Workflow::class, $name);
            $prop->setValue(null, $default);
        }
    }

    public function testRunWithRejectedTokenShowsLoginCommands(): void
    {
        Workflow::setConfig('enterprise_access_token', 'expired');

        Search::run('enterprise', ' ', false);

        self::assertNull(Workflow::getAccessToken());
        self::assertStringContainsString('<title>&gt; login &lt;access_token&gt;</title>', (string) Workflow::getItemsAsXml());
    }
}
