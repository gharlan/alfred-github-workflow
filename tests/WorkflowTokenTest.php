<?php

require_once __DIR__.'/WorkflowTestCase.php';

/**
 * Pins the dual-slot token behavior: the github.com token and the enterprise
 * token live in two separate config keys and MUST NOT collide. Multi-account
 * work (PR #2) will change the storage model; these tests ensure the
 * migration preserves the current isolation guarantees.
 */
final class WorkflowTokenTest extends WorkflowTestCase
{
    public function testGithubTokenStoredInActiveAccount(): void
    {
        Workflow::init();

        Workflow::setAccessToken('gh-token');

        $this->assertSame('gh-token', Workflow::getAccessToken());
        $active = Workflow::getActiveAccount();
        $this->assertNotNull($active);
        $this->assertSame('default', $active['label']);
        $this->assertSame('gh-token', $active['token']);
        $this->assertNull(Workflow::getConfig('enterprise_access_token'));
    }

    public function testEnterpriseTokenStoredUnderEnterpriseKey(): void
    {
        Workflow::init(true);

        Workflow::setAccessToken('ghe-token');

        $this->assertSame('ghe-token', Workflow::getAccessToken());
        $this->assertSame('ghe-token', Workflow::getConfig('enterprise_access_token'));
        $this->assertNull(Workflow::getConfig('access_token'));
    }

    public function testGithubAndEnterpriseTokensDoNotCollide(): void
    {
        Workflow::init();
        Workflow::setAccessToken('gh-token');

        agw_test_reset_workflow();
        Workflow::init(true);
        Workflow::setAccessToken('ghe-token');

        $this->assertSame('ghe-token', Workflow::getAccessToken());

        agw_test_reset_workflow();
        Workflow::init();
        $this->assertSame('gh-token', Workflow::getAccessToken());
    }

    public function testRemoveAccessTokenOnlyAffectsActiveSlot(): void
    {
        Workflow::init();
        Workflow::setAccessToken('gh-token');

        agw_test_reset_workflow();
        Workflow::init(true);
        Workflow::setAccessToken('ghe-token');
        Workflow::removeAccessToken();

        $this->assertNull(Workflow::getAccessToken());

        agw_test_reset_workflow();
        Workflow::init();
        $this->assertSame('gh-token', Workflow::getAccessToken());
    }

    public function testGithubSetAccessTokenCreatesDefaultAccountWhenNoneActive(): void
    {
        Workflow::init();
        Workflow::setAccessToken('fresh-token');

        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('default', $accounts[0]['label']);
        $this->assertSame('fresh-token', $accounts[0]['token']);
        $this->assertSame(1, (int) $accounts[0]['is_active']);
    }

    public function testGithubSetAccessTokenUpdatesActiveAccountWhenOneExists(): void
    {
        Workflow::init();
        Workflow::setAccessToken('first-token');
        Workflow::setAccessToken('second-token');

        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('second-token', $accounts[0]['token']);
    }

    public function testGithubRemoveAccessTokenClearsTokenButKeepsRow(): void
    {
        Workflow::init();
        Workflow::setAccessToken('to-be-removed');
        Workflow::removeAccessToken();

        $this->assertNull(Workflow::getAccessToken());
        // Row is preserved so the label survives re-login
        $this->assertCount(1, Workflow::listAccounts());
    }

    public function testGetAccessTokenReturnsNullForEmptyStringToken(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('alice', '');
        Workflow::setActiveAccount($id);

        $this->assertNull(Workflow::getAccessToken());
    }

    public function testGithubRemoveAccessTokenIsNoOpWhenNoActiveAccount(): void
    {
        Workflow::init();
        Workflow::removeAccessToken(); // must not throw
        $this->assertNull(Workflow::getAccessToken());
    }

    public function testGithubSetAccessTokenRecoversExistingInactiveDefaultAccount(): void
    {
        Workflow::init();
        // Seed a 'default' account but leave it INACTIVE
        $id = Workflow::addAccount('default', 'old-token');
        // Verify there is no active account
        $this->assertNull(Workflow::getActiveAccount());

        // setAccessToken must find the existing 'default' row and reuse it
        Workflow::setAccessToken('new-token');

        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('default', $accounts[0]['label']);
        $this->assertSame('new-token', $accounts[0]['token']);
        $this->assertSame(1, (int) $accounts[0]['is_active']);
    }

    public function testGithubSetAccessTokenRecoversPostLogoutDefaultAccount(): void
    {
        Workflow::init();
        // Log in, then log out (clears token to '' but keeps row active)
        Workflow::setAccessToken('original');
        Workflow::removeAccessToken();

        // Log in again — should reuse the same row
        Workflow::setAccessToken('fresh-login');

        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('default', $accounts[0]['label']);
        $this->assertSame('fresh-login', $accounts[0]['token']);
        $this->assertSame(1, (int) $accounts[0]['is_active']);
    }

    public function testLegacyLoginCommandCreatesDefaultAccount(): void
    {
        Workflow::init();
        Action::dispatch(['>', 'login', 'brand-new-token'], false);

        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts);
        $this->assertSame('default', $accounts[0]['label']);
        $this->assertSame('brand-new-token', $accounts[0]['token']);
        $this->assertSame(1, (int) $accounts[0]['is_active']);
    }

    public function testLegacyLogoutClearsTokenButKeepsAccount(): void
    {
        Workflow::init();
        Action::dispatch(['>', 'login', 'tok'], false);
        Action::dispatch(['>', 'logout'], false);

        $accounts = Workflow::listAccounts();
        $this->assertCount(1, $accounts); // account row preserved
        $this->assertNull(Workflow::getAccessToken()); // but no usable token
    }
}
