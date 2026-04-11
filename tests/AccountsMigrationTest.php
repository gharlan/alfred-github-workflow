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
        // Pre-seed an account row
        $pdo->exec("INSERT INTO accounts (label, token, is_active, created_at) VALUES ('preexisting', 'tok', 1, 1)");
        // Now set a legacy access_token — migration should NOT clobber existing accounts
        Workflow::setConfig('access_token', 'should-not-migrate');

        agw_test_reset_workflow();
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $count = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        $this->assertSame(1, $count);
        $label = $pdo->query('SELECT label FROM accounts LIMIT 1')->fetchColumn();
        $this->assertSame('preexisting', $label);
    }
}
