<?php

declare(strict_types=1);

require_once __DIR__.'/WorkflowTestCase.php';

/**
 * Pins the URL derivation performed by Workflow::init when the enterprise
 * flag is set: baseUrl is read from the enterprise_url config key, and the
 * API/gist URLs are derived from it. When no config value is present the
 * derived URLs become null — this is the current (pre-multi-account) shape
 * and callers depend on it.
 */
final class WorkflowEnterpriseUrlTest extends WorkflowTestCase
{
    public function testDefaultGithubUrls(): void
    {
        Workflow::init();

        $this->assertSame('https://github.com', Workflow::getBaseUrl());
        $this->assertSame('https://api.github.com', Workflow::getApiUrl());
        $this->assertSame('https://gist.github.com', Workflow::getGistUrl());
    }

    public function testEnterpriseUrlsDerivedFromConfig(): void
    {
        Workflow::init();
        Workflow::setConfig('enterprise_url', 'https://ghe.example.com');

        agw_test_reset_workflow();
        Workflow::init(true);

        $this->assertSame('https://ghe.example.com', Workflow::getBaseUrl());
        $this->assertSame('https://ghe.example.com/api/v3', Workflow::getApiUrl());
        $this->assertSame('https://ghe.example.com/gist', Workflow::getGistUrl());
    }

    public function testEnterpriseUrlsAreNullWhenConfigMissing(): void
    {
        Workflow::init(true);

        $this->assertNull(Workflow::getBaseUrl());
        $this->assertNull(Workflow::getApiUrl());
        $this->assertNull(Workflow::getGistUrl());
    }

    public function testGetApiUrlAppendsPathAndPerPage(): void
    {
        Workflow::init();

        $this->assertSame(
            'https://api.github.com/user/repos?per_page=100',
            Workflow::getApiUrl('/user/repos')
        );
        $this->assertSame(
            'https://api.github.com/search/repositories?q=foo&per_page=100',
            Workflow::getApiUrl('/search/repositories?q=foo')
        );
    }
}
