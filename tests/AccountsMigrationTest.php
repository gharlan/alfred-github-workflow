<?php

require_once __DIR__.'/WorkflowTestCase.php';

final class AccountsMigrationTest extends WorkflowTestCase
{
    public function testLegacyAccessTokenIsMigratedToDefaultAccount(): void
    {
        Workflow::init();
        Workflow::setConfig('access_token', 'legacy-token');

        agw_test_reset_workflow();
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $row = $pdo->query('SELECT label, token, is_active FROM accounts')->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('default', $row['label']);
        $this->assertSame('legacy-token', $row['token']);
        $this->assertSame(1, (int) $row['is_active']);
    }

    public function testMigrationIsIdempotent(): void
    {
        Workflow::init();
        Workflow::setConfig('access_token', 'legacy-token');

        agw_test_reset_workflow();
        Workflow::init();
        agw_test_reset_workflow();
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testNoMigrationWhenNoLegacyToken(): void
    {
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testEnterpriseAccessTokenIsNotMigrated(): void
    {
        Workflow::init(true);
        Workflow::setConfig('enterprise_access_token', 'ghe-token');
        Workflow::setConfig('enterprise_url', 'https://ghe.example.com');

        agw_test_reset_workflow();
        Workflow::init(true);

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        $this->assertSame(0, $count);

        // Enterprise token still reachable via legacy config path
        $this->assertSame('ghe-token', Workflow::getAccessToken());
    }

    public function testMigrationDoesNotRunWhenAccountsAlreadyExist(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        // Pre-seed with label 'default' so the UNIQUE constraint is NOT what
        // prevents a second insert — only the $count > 0 early return can.
        $pdo->exec("INSERT INTO accounts (label, token, is_active, created_at) VALUES ('default', 'first-seeded', 1, 1)");
        Workflow::setConfig('access_token', 'should-not-migrate');

        agw_test_reset_workflow();
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $row = $pdo->query('SELECT label, token FROM accounts')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('default', $row['label']);
        $this->assertSame('first-seeded', $row['token']); // would be 'should-not-migrate' if early return failed
    }

    public function testMigrationRunsOnPreExistingLegacyDatabase(): void
    {
        // Simulate a pre-PR-#2 database: create config + request_cache manually
        // with no accounts table, then set the legacy access_token.
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE config (key TEXT PRIMARY KEY NOT NULL, value TEXT) WITHOUT ROWID');
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
        $pdo->exec("INSERT INTO config VALUES ('access_token', 'legacy-from-prior-version')");
        $pdo = null;

        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $row = $pdo->query('SELECT label, token, is_active FROM accounts')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('default', $row['label']);
        $this->assertSame('legacy-from-prior-version', $row['token']);
        $this->assertSame(1, (int) $row['is_active']);
    }
}
