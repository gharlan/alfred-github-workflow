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

    public function testLoginWithoutTokenReturnsEmptyString(): void
    {
        // Non-enterprise login without a token triggers the OAuth flow;
        // the return value is empty string (output happens via exec/startServer).
        Workflow::init();
        $output = Action::dispatch(['>', 'login'], false);
        $this->assertSame('', $output);
    }

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
        // Note: this also triggers `osascript` exec which is harmless in tests.
        Action::dispatch(['>', 'enterprise-url', 'https://ghe.example.com/'], true);
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
}
