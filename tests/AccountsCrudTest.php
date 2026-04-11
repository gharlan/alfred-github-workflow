<?php

require_once __DIR__.'/WorkflowTestCase.php';

final class AccountsCrudTest extends WorkflowTestCase
{
    public function testAddAccountReturnsIdAndInsertsRow(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'token-a');

        $this->assertGreaterThan(0, $id);
        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('alice', $accounts[0]['label']);
        $this->assertSame('token-a', $accounts[0]['token']);
        $this->assertSame(0, (int) $accounts[0]['is_active']);
    }

    public function testAddAccountRejectsDuplicateLabel(): void
    {
        Workflow::init();
        Workflow::addAccount('alice', 'token-a');

        $this->expectException(PDOException::class);
        Workflow::addAccount('alice', 'token-b');
    }

    public function testListAccountsReturnsEmptyArrayWhenNoAccounts(): void
    {
        Workflow::init();
        $this->assertSame([], Workflow::listAccounts());
    }

    public function testListAccountsSortsByLabel(): void
    {
        Workflow::init();
        Workflow::addAccount('zulu', 'tok-z');
        Workflow::addAccount('alpha', 'tok-a');
        Workflow::addAccount('mike', 'tok-m');

        $accounts = Workflow::listAccounts();
        $this->assertSame(['alpha', 'mike', 'zulu'], array_column($accounts, 'label'));
    }

    public function testGetActiveAccountReturnsNullWhenNoneActive(): void
    {
        Workflow::init();
        Workflow::addAccount('alice', 'token-a');

        $this->assertNull(Workflow::getActiveAccount());
    }

    public function testSetActiveAccountMarksSingleActive(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'token-a');
        Workflow::setActiveAccount($id);

        $active = Workflow::getActiveAccount();
        $this->assertNotNull($active);
        $this->assertSame('alice', $active['label']);
        $this->assertSame(1, (int) $active['is_active']);
    }

    public function testSwitchingActiveClearsPreviousActive(): void
    {
        Workflow::init();
        $a = Workflow::addAccount('alice', 'token-a');
        $b = Workflow::addAccount('bob', 'token-b');

        Workflow::setActiveAccount($a);
        Workflow::setActiveAccount($b);

        $this->assertSame('bob', Workflow::getActiveAccount()['label']);
        $accounts = Workflow::listAccounts();
        $activeCount = 0;
        foreach ($accounts as $account) {
            if (1 === (int) $account['is_active']) {
                ++$activeCount;
            }
        }
        $this->assertSame(1, $activeCount);
    }

    public function testSetActiveAccountThrowsOnUnknownId(): void
    {
        Workflow::init();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        Workflow::setActiveAccount(999);
    }

    public function testSetActiveAccountRollsBackOnFailure(): void
    {
        Workflow::init();
        $a = Workflow::addAccount('alice', 'token-a');
        Workflow::setActiveAccount($a);

        // Attempt to set a non-existent account; the transaction should roll back
        // and leave 'alice' still active.
        try {
            Workflow::setActiveAccount(999);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            // expected
        }

        $active = Workflow::getActiveAccount();
        $this->assertNotNull($active);
        $this->assertSame('alice', $active['label']);
    }

    public function testRemoveAccountDeletesRow(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'token-a');
        Workflow::removeAccount($id);

        $this->assertCount(0, Workflow::listAccounts());
    }

    public function testRemoveAccountRefusesActiveAccount(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'token-a');
        Workflow::setActiveAccount($id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/active/i');
        Workflow::removeAccount($id);
    }

    public function testRemoveAccountDropsCacheRows(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'token-a');
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare(
            'REPLACE INTO request_cache (account_id, url, timestamp, etag, content, refresh, parent) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, 'https://api.github.com/user', time(), null, '{}', 0, null]);

        Workflow::removeAccount($id);

        $count = (int) $pdo->query("SELECT COUNT(*) FROM request_cache WHERE account_id = $id")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testUpdateAccountTokenReplacesToken(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'old-token');
        Workflow::updateAccountToken($id, 'new-token');

        $accounts = Workflow::listAccounts();
        $this->assertSame('new-token', $accounts[0]['token']);
    }

    public function testUpdateAccountTokenThrowsOnUnknownId(): void
    {
        Workflow::init();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');
        Workflow::updateAccountToken(999, 'irrelevant');
    }

    public function testDeleteDatabasePreservesAccounts(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'tok-a');
        Workflow::setActiveAccount($id);

        Workflow::deleteDatabase();

        agw_test_reset_workflow();
        Workflow::init();

        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('alice', $accounts[0]['label']);
        $this->assertSame('tok-a', $accounts[0]['token']);
        $this->assertSame(1, (int) $accounts[0]['is_active']);
    }

    public function testDeleteDatabaseClearsRequestCache(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', 'tok-a');
        Workflow::setActiveAccount($id);

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare(
            'REPLACE INTO request_cache (account_id, url, timestamp, etag, content, refresh, parent) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, 'https://api.github.com/user', time(), null, '{}', 0, null]);

        Workflow::deleteDatabase();

        $count = (int) $pdo->query('SELECT COUNT(*) FROM request_cache')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDeleteDatabaseClearsConfig(): void
    {
        Workflow::init();
        Workflow::setConfig('autoupdate', '0');
        Workflow::setConfig('version', 'test-version');

        Workflow::deleteDatabase();

        agw_test_reset_workflow();
        Workflow::init();
        $this->assertNull(Workflow::getConfig('autoupdate'));
        $this->assertNull(Workflow::getConfig('version'));
    }

    public function testDeleteDatabaseDoesNotUnlinkFile(): void
    {
        Workflow::init();
        $dbFile = $this->dataDir.'/db.sqlite';
        $this->assertFileExists($dbFile);

        Workflow::deleteDatabase();

        $this->assertFileExists($dbFile);
    }

    public function testDeleteDatabaseFollowedByAddAccountWorks(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('pre', 'tok-pre');
        Workflow::setActiveAccount($id);

        Workflow::deleteDatabase();

        // No re-init — the PDO handle should still be live and accounts CRUD should still work.
        $newId = Workflow::addAccount('post', 'tok-post');
        $this->assertGreaterThan(0, $newId);

        $accounts = Workflow::listAccounts();
        $this->assertCount(2, $accounts);
    }
}
