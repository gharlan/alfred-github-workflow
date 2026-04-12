<?php

require_once __DIR__.'/WorkflowTestCase.php';

final class AccountsSchemaTest extends WorkflowTestCase
{
    public function testInitCreatesAccountsTable(): void
    {
        Workflow::init();

        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $columns = $pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame(
            ['id', 'label', 'token', 'is_active', 'created_at'],
            array_column($columns, 'name')
        );
    }

    public function testLabelIsUnique(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->exec("INSERT INTO accounts (label, token, is_active, created_at) VALUES ('a', 't1', 0, 1)");

        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO accounts (label, token, is_active, created_at) VALUES ('a', 't2', 0, 2)");
    }

    public function testOnlyOneActiveAccountAllowed(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $pdo->exec("INSERT INTO accounts (label, token, is_active, created_at) VALUES ('a', 't1', 1, 1)");

        $this->expectException(PDOException::class);
        $pdo->exec("INSERT INTO accounts (label, token, is_active, created_at) VALUES ('b', 't2', 1, 2)");
    }

    public function testNotNullConstraintsArePinned(): void
    {
        Workflow::init();
        $pdo = new PDO('sqlite:'.$this->dataDir.'/db.sqlite');
        $columns = $pdo->query('PRAGMA table_info(accounts)')->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($columns as $column) {
            $byName[$column['name']] = (int) $column['notnull'];
        }

        $this->assertSame(1, $byName['label']);
        $this->assertSame(1, $byName['token']);
        $this->assertSame(1, $byName['is_active']);
        $this->assertSame(1, $byName['created_at']);
    }
}
