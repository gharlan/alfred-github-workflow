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
}
