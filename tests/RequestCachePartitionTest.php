<?php

require_once __DIR__.'/WorkflowTestCase.php';

final class RequestCachePartitionTest extends WorkflowTestCase
{
    public function testRequestCacheHasAccountIdColumn(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $columns = $pdo->query('PRAGMA table_info(request_cache)')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertContains('account_id', array_column($columns, 'name'));
    }

    public function testRequestCachePrimaryKeyIncludesAccountId(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $columns = $pdo->query('PRAGMA table_info(request_cache)')->fetchAll(PDO::FETCH_ASSOC);

        $pkColumns = [];
        foreach ($columns as $column) {
            if ((int) $column['pk'] > 0) {
                $pkColumns[(int) $column['pk']] = $column['name'];
            }
        }
        ksort($pkColumns);

        $this->assertSame(['account_id', 'url'], array_values($pkColumns));
    }

    public function testSameUrlCanBeCachedForTwoAccounts(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $insert = $pdo->prepare(
            'REPLACE INTO request_cache (account_id, url, timestamp, etag, content, refresh, parent) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([1, 'https://api.github.com/user', time(), null, '{"login":"a"}', 0, null]);
        $insert->execute([2, 'https://api.github.com/user', time(), null, '{"login":"b"}', 0, null]);

        $rows = $pdo->query('SELECT account_id, content FROM request_cache ORDER BY account_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('{"login":"a"}', $rows[0]['content']);
        $this->assertSame('{"login":"b"}', $rows[1]['content']);
    }

    public function testParentUrlIndexStillExists(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $row = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'parent_url'")->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('request_cache', $row['sql']);
        $this->assertStringContainsString('parent', $row['sql']);
    }

    public function testLegacyDatabaseWithoutAccountIdIsMigrated(): void
    {
        // Simulate a pre-PR-#2 DB: old request_cache schema, with data rows.
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE config (key TEXT PRIMARY KEY NOT NULL, value TEXT) WITHOUT ROWID
        ');
        $pdo->exec('
            CREATE TABLE request_cache (
                url TEXT PRIMARY KEY NOT NULL,
                timestamp INTEGER NOT NULL,
                etag TEXT,
                content TEXT,
                refresh INTEGER,
                parent TEXT
            ) WITHOUT ROWID
        ');
        $pdo->exec('CREATE INDEX parent_url ON request_cache(parent) WHERE parent IS NOT NULL');
        $pdo->exec("INSERT INTO request_cache VALUES ('https://api.github.com/user', ".time().", NULL, '{\"login\":\"legacy\"}', 0, NULL)");
        $pdo = null;

        agw_test_reset_workflow();
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $columns = $pdo->query('PRAGMA table_info(request_cache)')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertContains('account_id', array_column($columns, 'name'));

        $row = $pdo->query('SELECT account_id, url, content FROM request_cache')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $row['account_id']);
        $this->assertSame('https://api.github.com/user', $row['url']);
        $this->assertSame('{"login":"legacy"}', $row['content']);
    }

    public function testMigrationIsIdempotent(): void
    {
        // After one migration run, a second init() must not rebuild again.
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare(
            'REPLACE INTO request_cache (account_id, url, timestamp, etag, content, refresh, parent) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([42, 'https://example.com', time(), null, 'data', 0, null]);

        agw_test_reset_workflow();
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $row = $pdo->query("SELECT account_id, content FROM request_cache WHERE url = 'https://example.com'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(42, (int) $row['account_id']);
        $this->assertSame('data', $row['content']);

        // Affirmative check: if migration ran again, request_cache_new would transiently exist
        // during the transaction. Since we use BEGIN/COMMIT, post-commit it won't be present
        // either way — but an orphan left behind by a crashed rename would stick around.
        $orphan = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'request_cache_new'")->fetchColumn();
        $this->assertFalse($orphan, 'request_cache_new must not exist after an idempotent second init');
    }

    public function testResolveAccountIdForCacheReturnsZeroInEnterpriseMode(): void
    {
        // Even with an active github account, an enterprise init() must produce
        // account_id=0 for request_cache entries, per spec.
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("INSERT INTO accounts (label, token, is_active, created_at) VALUES ('gh', 'tok', 1, 1)");
        Workflow::setConfig('enterprise_url', 'https://ghe.example.com');

        agw_test_reset_workflow();
        Workflow::init(true);

        // We cannot call the private resolveAccountIdForCache directly. Instead,
        // insert a row through the lower-level REPLACE and verify by reading it back.
        // Since requestCache() is network-bound, we can only exercise resolveAccountIdForCache
        // indirectly by confirming that any existing cache behavior in enterprise mode
        // uses account_id=0. A simple way: manually INSERT through the code path is not
        // possible without a network call; instead, use reflection to invoke the private
        // method directly.
        $reflection = new ReflectionMethod(Workflow::class, 'resolveAccountIdForCache');
        $accountId = $reflection->invoke(null);

        $this->assertSame(0, $accountId, 'Enterprise mode must map to sentinel account_id=0');
    }

    public function testResolveAccountIdForCacheReturnsActiveAccountIdInGithubMode(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'tok-a');
        Workflow::setActiveAccount($id);

        $reflection = new ReflectionMethod(Workflow::class, 'resolveAccountIdForCache');
        $accountId = $reflection->invoke(null);

        $this->assertSame($id, $accountId, 'Github mode must return the active account id');
    }

    public function testResolveAccountIdForCacheReturnsZeroWhenNoActiveAccount(): void
    {
        Workflow::init();

        $reflection = new ReflectionMethod(Workflow::class, 'resolveAccountIdForCache');
        $accountId = $reflection->invoke(null);

        $this->assertSame(0, $accountId, 'No active account must fall back to sentinel 0');
    }

    public function testParentUrlIndexSurvivesMigration(): void
    {
        // Seed a legacy DB, run migration, confirm parent_url index is still there.
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('
            CREATE TABLE config (key TEXT PRIMARY KEY NOT NULL, value TEXT) WITHOUT ROWID
        ');
        $pdo->exec('
            CREATE TABLE request_cache (
                url TEXT PRIMARY KEY NOT NULL,
                timestamp INTEGER NOT NULL,
                etag TEXT,
                content TEXT,
                refresh INTEGER,
                parent TEXT
            ) WITHOUT ROWID
        ');
        $pdo->exec('CREATE INDEX parent_url ON request_cache(parent) WHERE parent IS NOT NULL');
        $pdo = null;

        agw_test_reset_workflow();
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $row = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'parent_url'")->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringContainsString('parent', $row['sql']);
    }
}
