<?php

require_once __DIR__.'/WorkflowTestCase.php';

final class SearchHeaderTest extends WorkflowTestCase
{
    public function testRenderGhHeaderReturnsPlainGhWhenNoActiveAccount(): void
    {
        Workflow::init();

        $reflection = new ReflectionMethod(Search::class, 'renderGhHeader');
        $result = $reflection->invoke(null);

        $this->assertSame('gh', $result);
    }

    public function testRenderGhHeaderShowsActiveLabelInParens(): void
    {
        Workflow::init();
        $id = Workflow::addAccount('dokun1', 'tok');
        Workflow::setActiveAccount($id);

        $reflection = new ReflectionMethod(Search::class, 'renderGhHeader');
        $result = $reflection->invoke(null);

        $this->assertSame('gh (dokun1)', $result);
    }
}
