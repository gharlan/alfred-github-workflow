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
    public function testGithubTokenStoredUnderAccessTokenKey(): void
    {
        Workflow::init();

        Workflow::setAccessToken('gh-token');

        $this->assertSame('gh-token', Workflow::getAccessToken());
        $this->assertSame('gh-token', Workflow::getConfig('access_token'));
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
}
