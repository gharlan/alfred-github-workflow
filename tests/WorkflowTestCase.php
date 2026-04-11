<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base test case that gives each test a fresh Workflow data directory and
 * resets the static Workflow state. Workflow is a static class, so without
 * this its SQLite handle and cached prepared statements would leak between
 * tests.
 */
abstract class WorkflowTestCase extends TestCase
{
    /** @var string */
    protected $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = agw_test_tmp_dir();
        putenv('alfred_workflow_data='.$this->dataDir);
        agw_test_reset_workflow();
    }

    protected function tearDown(): void
    {
        agw_test_reset_workflow();
        putenv('alfred_workflow_data');
        agw_test_rmrf($this->dataDir);
    }
}
