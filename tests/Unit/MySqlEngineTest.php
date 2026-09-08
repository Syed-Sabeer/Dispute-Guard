<?php

namespace Tests\Unit;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class MySqlEngineTest extends TestCase
{
    public function test_new_tables_explicitly_use_innodb(): void
    {
        $config = config('database.connections.mysql');
        $connection = new MySqlConnection(fn () => throw new \RuntimeException('No live database needed'), 'test', '', $config);
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint('password_reset_tokens');
        $blueprint->create();
        $blueprint->string('email')->primary();
        $sql = $blueprint->toSql($connection, $connection->getSchemaGrammar());
        $this->assertStringContainsString('engine = InnoDB', $sql[0]);
        $this->assertStringContainsString('utf8mb4', $sql[0]);
    }
}
