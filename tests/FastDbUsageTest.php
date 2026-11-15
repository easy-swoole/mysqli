<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config};
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class FastDbUsageTest extends TestCase
{
    private ?Client $connection = null;
    private string $table;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set FAST_DB_TEST_* or MYSQLI_TEST_* to the EasySwoole FastDb test database.');
        }
        $this->connection = new Client(new Config(MYSQL_CONFIG));
        $this->connection->connect();
        $this->table = 'mysqli_fastdb_usage_' . bin2hex(random_bytes(6));
        $this->connection->rawQuery("CREATE TABLE `{$this->table}` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            value VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            try {
                $protocol = $this->connection->mysqlClient();
                if ($protocol?->inTransaction()) {
                    $protocol->rollback();
                }
                $this->connection->rawQuery("DROP TABLE IF EXISTS `{$this->table}`");
            } finally {
                $this->connection->close();
                $this->connection = null;
            }
        }
    }

    public function testPoolHealthCheckAndQueryBuilderCalls(): void
    {
        self::assertTrue($this->connection->ping());
        self::assertTrue($this->connection->query((new QueryBuilder())->insert($this->table, ['value' => 'fast-db'])));
        self::assertSame(1, $this->connection->getLastInsertId());
        self::assertSame(1, $this->connection->getLastAffectRows());
        self::assertSame('fast-db', $this->connection->query((new QueryBuilder())->where('id', 1)->getOne($this->table))[0]['value']);
    }

    public function testDirectMysqlClientTransactionCalls(): void
    {
        self::assertTrue($this->connection->connect());
        $protocol = $this->connection->mysqlClient();
        self::assertTrue($protocol->begin_transaction(TransactionStartFlags::ConsistentSnapshotReadWrite));
        self::assertTrue($this->connection->rawQuery("INSERT INTO `{$this->table}` (value) VALUES ('rollback')"));
        self::assertTrue($protocol->rollback(TransactionCompletionFlags::NoChain));
        self::assertSame([['total' => 0]], $this->connection->rawQuery("SELECT COUNT(*) AS total FROM `{$this->table}`"));

        self::assertTrue($protocol->begin_transaction(TransactionStartFlags::ConsistentSnapshotReadWrite));
        self::assertTrue($this->connection->rawQuery("INSERT INTO `{$this->table}` (value) VALUES ('commit')"));
        self::assertTrue($protocol->commit(TransactionCompletionFlags::NoChain));
        self::assertSame([['total' => 1]], $this->connection->rawQuery("SELECT COUNT(*) AS total FROM `{$this->table}`"));
    }
}
