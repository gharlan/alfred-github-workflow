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
