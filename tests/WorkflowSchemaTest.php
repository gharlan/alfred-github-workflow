<?php

require_once __DIR__.'/WorkflowTestCase.php';

/**
 * Pins the SQLite schema that Workflow::init creates on a fresh database,
 * plus the fact that init is safe to call against an existing database
 * without recreating tables. Multi-account work will alter request_cache;
 * these assertions are the canary for that migration.
 */
final class WorkflowSchemaTest extends WorkflowTestCase
{
    public function testInitCreatesConfigTableWithExpectedColumns(): void
    {
        Workflow::init();

        $columns = $this->tableColumns('config');

        $this->assertSame(['key', 'value'], array_column($columns, 'name'));
        $this->assertSame(1, $this->columnByName($columns, 'key')['pk']);
        $this->assertSame(1, $this->columnByName($columns, 'key')['notnull']);
    }

    public function testInitCreatesRequestCacheTableWithExpectedColumns(): void
    {
        Workflow::init();

        $columns = $this->tableColumns('request_cache');

        $this->assertSame(
            ['account_id', 'url', 'timestamp', 'etag', 'content', 'refresh', 'parent'],
            array_column($columns, 'name')
        );
        // Composite primary key (account_id, url) — account_id is pk=1, url is pk=2.
        $this->assertSame(1, $this->columnByName($columns, 'account_id')['pk']);
        $this->assertSame(2, $this->columnByName($columns, 'url')['pk']);
    }

    public function testInitCreatesParentUrlIndex(): void
    {
        Workflow::init();

        $pdo = $this->db();
        $row = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'parent_url'")->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('request_cache', $row['sql']);
        $this->assertStringContainsString('parent', $row['sql']);
    }

    public function testInitIsIdempotentAcrossReopen(): void
    {
        Workflow::init();
        Workflow::setConfig('persisted', 'yes');

        agw_test_reset_workflow();
        Workflow::init();

        $this->assertSame('yes', Workflow::getConfig('persisted'));

        $columns = $this->tableColumns('config');
        $this->assertSame(['key', 'value'], array_column($columns, 'name'));
    }

    private function db(): PDO
    {
        return new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
    }

    /** @return array<int,array{name:string,pk:int,notnull:int}> */
    private function tableColumns(string $table): array
    {
        $stmt = $this->db()->query('PRAGMA table_info('.$table.')');
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[] = [
                'name' => $row['name'],
                'pk' => (int) $row['pk'],
                'notnull' => (int) $row['notnull'],
            ];
        }

        return $columns;
    }

    /** @param array<int,array{name:string,pk:int,notnull:int}> $columns */
    private function columnByName(array $columns, string $name): array
    {
        foreach ($columns as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        $this->fail('Column '.$name.' not found');
    }
}
