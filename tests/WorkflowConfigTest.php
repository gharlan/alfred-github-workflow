<?php

declare(strict_types=1);

require_once __DIR__.'/WorkflowTestCase.php';

final class WorkflowConfigTest extends WorkflowTestCase
{
    public function testSetAndGetConfigRoundTrip(): void
    {
        Workflow::init();

        Workflow::setConfig('greeting', 'hello');

        $this->assertSame('hello', Workflow::getConfig('greeting'));
    }

    public function testGetConfigReturnsDefaultWhenMissing(): void
    {
        Workflow::init();

        $this->assertNull(Workflow::getConfig('missing'));
        $this->assertSame('fallback', Workflow::getConfig('missing', 'fallback'));
    }

    public function testSetConfigOverwritesExistingValue(): void
    {
        Workflow::init();

        Workflow::setConfig('k', 'v1');
        Workflow::setConfig('k', 'v2');

        $this->assertSame('v2', Workflow::getConfig('k'));
    }

    public function testRemoveConfigDeletesKey(): void
    {
        Workflow::init();

        Workflow::setConfig('k', 'v');
        Workflow::removeConfig('k');

        $this->assertNull(Workflow::getConfig('k'));
    }
}
