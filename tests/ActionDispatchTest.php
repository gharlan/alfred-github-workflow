<?php

require_once __DIR__.'/WorkflowTestCase.php';

final class ActionDispatchTest extends WorkflowTestCase
{
    public function testLoginWithTokenSavesAndConfirms(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'login', 'new-token'], false);

        $this->assertStringContainsString('logged in', $output);
        $this->assertSame('new-token', Workflow::getAccessToken());
    }

    // Skipped: testLoginWithoutTokenReturnsEmptyString — triggers real OAuth browser
    // open + PHP built-in server via exec(). Covered in Phase I manual QA.

    public function testLogoutRemovesTokenAndConfirms(): void
    {
        Workflow::init();
        Workflow::setAccessToken('existing');
        $output = Action::dispatch(['>', 'logout'], false);

        $this->assertStringContainsString('logged out', $output);
        $this->assertNull(Workflow::getAccessToken());
    }

    public function testDeleteCacheConfirms(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'delete-cache'], false);
        $this->assertStringContainsString('deleted cache', $output);
    }

    public function testDeleteDatabaseConfirms(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'delete-database'], false);
        $this->assertStringContainsString('deleted database', $output);
    }

    public function testActivateAutoupdateSetsConfig(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'activate-autoupdate'], false);
        $this->assertStringContainsString('Activated', $output);
        $this->assertSame(1, (int) Workflow::getConfig('autoupdate'));
    }

    public function testDeactivateAutoupdateSetsConfig(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'deactivate-autoupdate'], false);
        $this->assertStringContainsString('Deactivated', $output);
        $this->assertSame(0, (int) Workflow::getConfig('autoupdate'));
    }

    public function testEnterpriseUrlSetsConfig(): void
    {
        Workflow::init(true);
        // Test config storage directly — dispatch would trigger osascript exec.
        Workflow::setConfig('enterprise_url', rtrim('https://ghe.example.com/', '/'));
        $this->assertSame('https://ghe.example.com', Workflow::getConfig('enterprise_url'));
    }

    public function testEnterpriseResetClearsConfig(): void
    {
        Workflow::init(true);
        Workflow::setConfig('enterprise_url', 'https://ghe.example.com');
        Workflow::setConfig('enterprise_access_token', 'tok');
        Action::dispatch(['>', 'enterprise-reset'], true);
        $this->assertNull(Workflow::getConfig('enterprise_url'));
        $this->assertNull(Workflow::getConfig('enterprise_access_token'));
    }

    public function testUnknownCommandReturnsEmptyString(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'never-heard-of-this'], false);
        $this->assertSame('', $output);
    }

    // --- Phase D: gh user subcommands ---

    public function testUserAddRejectsDuplicateLabel(): void
    {
        Workflow::init();
        Workflow::addAccount('work', 'existing');

        $output = Action::dispatch(['>', 'user', 'add', 'work'], false);
        $this->assertStringContainsString('already exists', $output);
    }

    public function testUserAddRejectsEmptyLabel(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'add'], false);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testUserAddRejectsEnterprise(): void
    {
        Workflow::init(true);
        $output = Action::dispatch(['>', 'user', 'add', 'test'], true);
        $this->assertStringContainsString('only supported for github.com', $output);
    }

    public function testUserSwitchActivatesAccount(): void
    {
        Workflow::init();
        Workflow::addAccount('alice', 'tok-a');
        Workflow::addAccount('bob', 'tok-b');

        $output = Action::dispatch(['>', 'user', 'switch', 'bob'], false);
        $this->assertStringContainsString('Switched to bob', $output);
        $this->assertSame('bob', Workflow::getActiveAccount()['label']);
    }

    public function testUserSwitchRejectsUnknownLabel(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'switch', 'nobody'], false);
        $this->assertStringContainsString('not found', $output);
    }

    public function testUserSwitchRejectsEmptyLabel(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'switch'], false);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testUserDeleteRemovesInactiveAccount(): void
    {
        Workflow::init();
        Workflow::addAccount('work', 'tok');

        $output = Action::dispatch(['>', 'user', 'delete', 'work'], false);
        $this->assertStringContainsString('Deleted', $output);
        $this->assertCount(0, Workflow::listAccounts());
    }

    public function testUserDeleteRefusesActiveAccount(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('work', 'tok');
        Workflow::setActiveAccount($id);

        $output = Action::dispatch(['>', 'user', 'delete', 'work'], false);
        $this->assertStringContainsString('active', $output);
        $this->assertCount(1, Workflow::listAccounts());
    }

    public function testUserDeleteRejectsUnknownLabel(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'delete', 'ghost'], false);
        $this->assertStringContainsString('not found', $output);
    }

    public function testUserDeleteRejectsEmptyLabel(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'delete'], false);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testUserUpdateRejectsUnknownLabel(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'update', 'ghost'], false);
        $this->assertStringContainsString('not found', $output);
    }

    public function testUserUpdateRejectsEmptyLabel(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'update'], false);
        $this->assertStringContainsString('Usage', $output);
    }

    public function testUserUpdateRejectsEnterprise(): void
    {
        Workflow::init(true);
        $output = Action::dispatch(['>', 'user', 'update', 'work'], true);
        $this->assertStringContainsString('only supported for github.com', $output);
    }

    public function testUnknownUserSubcommandReturnsError(): void
    {
        Workflow::init();
        $output = Action::dispatch(['>', 'user', 'nonsense'], false);
        $this->assertStringContainsString('Unknown user command', $output);
    }
}
