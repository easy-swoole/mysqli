<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class TransactionTest extends TestCase
{
    private ?Client $admin = null;
    private string $table;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set FAST_DB_TEST_* or MYSQLI_TEST_* to the EasySwoole FastDb test database.');
        }
        $this->admin = $this->newClient();
        $this->table = 'mysqli_transaction_' . bin2hex(random_bytes(6));
        $this->admin->rawQuery("CREATE TABLE `{$this->table}` (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            value INT NOT NULL
        ) ENGINE=InnoDB");
        $this->admin->rawQuery("INSERT INTO `{$this->table}` (id, value) VALUES (1, 0), (2, 0)");
    }

    protected function tearDown(): void
    {
        if ($this->admin !== null) {
            try {
                $this->admin->rawQuery("DROP TABLE IF EXISTS `{$this->table}`");
            } finally {
                $this->admin->close();
                $this->admin = null;
            }
        }
    }

    public function testCommitBecomesVisibleToAnotherConnection(): void
    {
        $writer = $this->newClient();
        $observer = $this->newClient();
        try {
            self::assertTrue($writer->mysqlClient()->begin_transaction());
            self::assertTrue($writer->rawQuery("UPDATE `{$this->table}` SET value = 10 WHERE id = 1"));
            self::assertSame(1, $writer->getLastAffectRows());
            self::assertSame(0, $this->value($observer, 1));

            self::assertTrue($writer->mysqlClient()->commit());
            self::assertSame(10, $this->value($observer, 1));
        } finally {
            $writer->close();
            $observer->close();
        }
    }

    public function testRollbackDiscardsChanges(): void
    {
        $writer = $this->newClient();
        try {
            self::assertTrue($writer->mysqlClient()->begin_transaction());
            self::assertTrue($writer->rawQuery("UPDATE `{$this->table}` SET value = 20 WHERE id = 1"));
            self::assertSame(20, $this->value($writer, 1));
            self::assertTrue($writer->mysqlClient()->rollback());
            self::assertSame(0, $this->value($this->admin, 1));
        } finally {
            $writer->close();
        }
    }

    public function testRollbackToSavepointKeepsEarlierChanges(): void
    {
        $writer = $this->newClient();
        try {
            self::assertTrue($writer->mysqlClient()->begin_transaction());
            self::assertTrue($writer->rawQuery("UPDATE `{$this->table}` SET value = 10 WHERE id = 1"));
            self::assertTrue($writer->rawQuery('SAVEPOINT before_second_update'));
            self::assertTrue($writer->rawQuery("UPDATE `{$this->table}` SET value = 20 WHERE id = 1"));
            self::assertTrue($writer->rawQuery('ROLLBACK TO SAVEPOINT before_second_update'));
            self::assertTrue($writer->mysqlClient()->commit());
            self::assertSame(10, $this->value($this->admin, 1));
        } finally {
            $writer->close();
        }
    }

    public function testLockWaitTimeoutReturnsMysqlErrorAndConnectionSurvives(): void
    {
        $locker = $this->newClient();
        $contender = $this->newClient();
        try {
            self::assertTrue($locker->mysqlClient()->begin_transaction());
            self::assertTrue($locker->rawQuery("UPDATE `{$this->table}` SET value = 30 WHERE id = 1"));
            self::assertTrue($contender->rawQuery('SET SESSION innodb_lock_wait_timeout = 1'));

            $startedAt = microtime(true);
            try {
                $contender->rawQuery("UPDATE `{$this->table}` SET value = 40 WHERE id = 1", 3.0);
                self::fail('Expected InnoDB lock wait timeout');
            } catch (Exception $error) {
                self::assertSame(1205, $error->getCode());
                self::assertStringContainsString('Lock wait timeout', $error->getMessage());
                self::assertGreaterThan(0.8, microtime(true) - $startedAt);
                self::assertLessThan(3.0, microtime(true) - $startedAt);
            }

            self::assertTrue($contender->ping());
            self::assertTrue($locker->mysqlClient()->rollback());
            self::assertTrue($contender->rawQuery("UPDATE `{$this->table}` SET value = 40 WHERE id = 1"));
            self::assertSame(40, $this->value($contender, 1));
        } finally {
            try {
                $locker->mysqlClient()?->rollback();
            } catch (\Throwable) {
            }
            $locker->close();
            $contender->close();
        }
    }

    public function testRealDeadlockReturns1213AndWinningTransactionCommits(): void
    {
        $ready = new Channel(2);
        $start = new Channel(2);
        $results = new Channel(2);
        $table = $this->table;

        $worker = function (int $firstId, int $secondId, int $value) use ($ready, $start, $results, $table): void {
            $client = $this->newClient();
            try {
                $client->mysqlClient()->begin_transaction();
                $client->rawQuery("UPDATE `{$table}` SET value = {$value} WHERE id = {$firstId}");
                $ready->push(true);
                $start->pop(3.0);
                $client->rawQuery("UPDATE `{$table}` SET value = {$value} WHERE id = {$secondId}", 5.0);
                $client->mysqlClient()->commit();
                $results->push(['status' => 'committed', 'value' => $value]);
            } catch (\Throwable $error) {
                try {
                    $client->mysqlClient()?->rollback();
                } catch (\Throwable) {
                }
                $results->push(['status' => 'error', 'code' => $error->getCode(), 'message' => $error->getMessage()]);
            } finally {
                $client->close();
            }
        };

        Coroutine::create(fn() => $worker(1, 2, 100));
        Coroutine::create(fn() => $worker(2, 1, 200));
        self::assertTrue($ready->pop(3.0));
        self::assertTrue($ready->pop(3.0));
        $start->push(true);
        $start->push(true);

        $first = $results->pop(6.0);
        $second = $results->pop(6.0);
        self::assertIsArray($first);
        self::assertIsArray($second);
        $outcomes = [$first, $second];
        $errors = array_values(array_filter($outcomes, static fn(array $result): bool => $result['status'] === 'error'));
        $commits = array_values(array_filter($outcomes, static fn(array $result): bool => $result['status'] === 'committed'));
        self::assertCount(1, $errors);
        self::assertCount(1, $commits);
        self::assertSame(1213, $errors[0]['code']);
        self::assertStringContainsString('Deadlock found', $errors[0]['message']);

        $rows = $this->admin->rawQuery("SELECT id, value FROM `{$this->table}` ORDER BY id");
        self::assertSame($commits[0]['value'], $rows[0]['value']);
        self::assertSame($commits[0]['value'], $rows[1]['value']);
        self::assertTrue($this->admin->ping());
    }

    public function testKilledTransactionBlocksAllExecutionPathsUntilExplicitClose(): void
    {
        $writer = $this->newClient();
        try {
            $connection = $writer->mysqlClient();
            $statement = $writer->prepare("UPDATE `{$this->table}` SET value = ? WHERE id = 2");
            $writer->rawQuery('BEGIN');
            self::assertTrue($connection->inTransaction());
            $writer->rawQuery("UPDATE `{$this->table}` SET value = 10 WHERE id = 1");
            $id = $writer->rawQuery('SELECT CONNECTION_ID() AS id')[0]['id'];
            $this->admin->rawQuery("KILL CONNECTION {$id}");
            try {
                $writer->rawQuery('SELECT 1');
                self::fail('Expected disconnected transaction');
            } catch (Exception) {
                self::assertTrue($connection->isTransactionLost());
            }
            $builder = new \EasySwoole\Mysqli\QueryBuilder();
            $builder->raw("UPDATE `{$this->table}` SET value = ? WHERE id = 2", [20]);
            foreach ([fn() => $writer->rawQuery("UPDATE `{$this->table}` SET value = 20 WHERE id = 2"),
                fn() => $writer->query($builder), fn() => $writer->prepare('SELECT 1'),
                fn() => $writer->connect(), fn() => $connection->connect(),
                fn() => $connection->query('SELECT 1'), fn() => $connection->prepare('SELECT 1'),
                fn() => $connection->commit(), fn() => $connection->rollback(),
                fn() => $statement->execute([20])] as $operation) {
                try {
                    $operation();
                    self::fail('Lost transaction must block execution');
                } catch (\EasySwoole\Mysqli\Exception\TransactionLostException) {
                    self::assertFalse($connection->isConnected());
                }
            }
            $statement->close();
            self::assertTrue($connection->isTransactionLost());
            self::assertSame(0, $this->value($this->admin, 1));
            self::assertSame(0, $this->value($this->admin, 2));
            $writer->close();
            $writer->connect();
            $writer->mysqlClient()->begin_transaction();
            $writer->rawQuery("UPDATE `{$this->table}` SET value = 30 WHERE id = 2");
            $writer->mysqlClient()->commit();
            self::assertSame(30, $this->value($this->admin, 2));
        } finally {
            $writer->close();
        }
    }

    public function testTimeoutInsideImplicitTransactionBlocksFollowingWrites(): void
    {
        $writer = $this->newClient();
        try {
            $writer->rawQuery('SET autocommit = 0');
            self::assertFalse($writer->mysqlClient()->inTransaction());
            $statement = $writer->prepare("SELECT value FROM `{$this->table}` WHERE id = ?");
            $statement->execute([1]);
            self::assertTrue($writer->mysqlClient()->inTransaction());
            $statement->close();
            $writer->rawQuery("UPDATE `{$this->table}` SET value = 10 WHERE id = 1");
            try {
                $writer->rawQuery('SELECT SLEEP(0.2)', 0.03);
                self::fail('Expected transaction timeout');
            } catch (\EasySwoole\Mysqli\Exception\TimeoutException) {
                self::assertTrue($writer->mysqlClient()->isTransactionLost());
            }
            try {
                $writer->rawQuery("UPDATE `{$this->table}` SET value = 20 WHERE id = 2");
                self::fail('Expected blocked write');
            } catch (\EasySwoole\Mysqli\Exception\TransactionLostException) {
                self::assertSame(0, $this->value($this->admin, 1));
                self::assertSame(0, $this->value($this->admin, 2));
            }
        } finally {
            $writer->close();
        }
    }

    public function testCompletedTransactionsAllowReconnectAndDdlClearsStatus(): void
    {
        $writer = $this->newClient();
        try {
            foreach (['commit', 'rollback'] as $finish) {
                $writer->mysqlClient()->begin_transaction();
                self::assertTrue($writer->mysqlClient()->inTransaction());
                $writer->mysqlClient()->{$finish}();
                self::assertFalse($writer->mysqlClient()->inTransaction());
                $id = $writer->rawQuery('SELECT CONNECTION_ID() AS id')[0]['id'];
                $this->admin->rawQuery("KILL CONNECTION {$id}");
                try {
                    $writer->rawQuery('SELECT 1');
                    self::fail('Expected disconnected connection');
                } catch (Exception) {
                    self::assertFalse($writer->mysqlClient()->isTransactionLost());
                }
                self::assertSame([['value' => 0]], $writer->rawQuery("SELECT value FROM `{$this->table}` WHERE id = 1"));
            }
            $writer->rawQuery('START TRANSACTION');
            $writer->rawQuery("ALTER TABLE `{$this->table}` COMMENT = 'implicit commit test'");
            self::assertFalse($writer->mysqlClient()->inTransaction());
        } finally {
            $writer->close();
        }
    }

    public function testReadOnlyReadWriteAndConsistentSnapshotFlags(): void
    {
        $writer = $this->newClient();
        try {
            $connection = $writer->mysqlClient();
            $connection->begin_transaction(TransactionStartFlags::ReadOnly); // MYSQLI_TRANS_START_READ_ONLY。
            try {
                $writer->rawQuery("UPDATE `{$this->table}` SET value = 10 WHERE id = 1");
                self::fail('Read-only transaction must reject writes');
            } catch (Exception $error) {
                self::assertSame(1792, $error->getCode());
            }
            self::assertTrue($connection->inTransaction());
            $connection->rollback();
            $writer->rawQuery('SET SESSION TRANSACTION READ ONLY');
            $connection->begin_transaction(TransactionStartFlags::ReadWrite); // 显式 READ WRITE 覆盖会话默认只读。
            self::assertTrue($writer->rawQuery("UPDATE `{$this->table}` SET value = 10 WHERE id = 1"));
            $connection->commit();
            self::assertSame(10, $this->value($this->admin, 1));
            $writer->rawQuery('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $connection->begin_transaction(TransactionStartFlags::ConsistentSnapshotReadOnly); // 快照在开始时建立，尚未 SELECT。
            $this->admin->rawQuery("UPDATE `{$this->table}` SET value = 20 WHERE id = 1");
            self::assertSame(10, $this->value($writer, 1));
            $connection->rollback();
            self::assertSame(20, $this->value($writer, 1));
        } finally {
            $writer->close();
        }
    }

    public function testCommitAndRollbackChainFlagsMaintainNewTransaction(): void
    {
        $writer = $this->newClient();
        try {
            $connection = $writer->mysqlClient();
            $connection->begin_transaction();
            $writer->rawQuery("UPDATE `{$this->table}` SET value = 10 WHERE id = 1");
            $connection->commit(TransactionCompletionFlags::ChainNoRelease); // AND CHAIN NO RELEASE。
            self::assertTrue($connection->inTransaction());
            self::assertSame(10, $this->value($this->admin, 1));
            $writer->rawQuery("UPDATE `{$this->table}` SET value = 20 WHERE id = 2");
            $connection->rollback(TransactionCompletionFlags::Chain);
            self::assertTrue($connection->inTransaction());
            self::assertSame(0, $this->value($this->admin, 2));
            $writer->rawQuery('SET SESSION completion_type = 1');
            $connection->commit(TransactionCompletionFlags::NoChainNoRelease); // AND NO CHAIN 覆盖默认链式事务。
            self::assertFalse($connection->inTransaction());
            $connection->begin_transaction();
            $connection->rollback(TransactionCompletionFlags::NoChainNoRelease);
            self::assertFalse($connection->inTransaction());
        } finally {
            $writer->close();
        }
    }

    public function testReleaseFlagsActuallyCloseServerSession(): void
    {
        foreach (['commit', 'rollback'] as $method) {
            foreach ([TransactionCompletionFlags::Release, TransactionCompletionFlags::NoChainRelease] as $flags) {
                $writer = $this->newClient();
                try {
                    $connection = $writer->mysqlClient();
                    $connection->begin_transaction();
                    $writer->rawQuery("UPDATE `{$this->table}` SET value = 30 WHERE id = 2");
                    self::assertTrue($connection->{$method}($flags));
                    self::assertFalse($connection->isConnected());
                    self::assertFalse($connection->inTransaction());
                    self::assertFalse($writer->ping());
                    self::assertFalse($connection->isTransactionLost());
                    self::assertSame($method === 'commit' ? 30 : 0, $this->value($this->admin, 2));
                    $this->admin->rawQuery("UPDATE `{$this->table}` SET value = 0 WHERE id = 2");
                    self::assertSame([['v' => 1]], $writer->rawQuery('SELECT 1 AS v'));
                } finally {
                    $writer->close();
                }
            }
        }
    }

    public function testNoReleaseEnumOverridesServerReleaseDefault(): void
    {
        foreach (['commit', 'rollback'] as $method) {
            $writer = $this->newClient();
            try {
                $writer->rawQuery('SET SESSION completion_type = 2');
                $connection = $writer->mysqlClient();
                $connection->begin_transaction();
                $writer->rawQuery("UPDATE `{$this->table}` SET value = 30 WHERE id = 2");
                self::assertTrue($connection->{$method}(TransactionCompletionFlags::NoRelease));
                self::assertFalse($connection->inTransaction());
                self::assertTrue($connection->isConnected());
                self::assertTrue($writer->ping());
                self::assertSame($method === 'commit' ? 30 : 0, $this->value($this->admin, 2));
                $this->admin->rawQuery("UPDATE `{$this->table}` SET value = 0 WHERE id = 2");
            } finally {
                $writer->close();
            }
        }
    }

    private function newClient(): Client
    {
        $config = MYSQL_CONFIG;
        $config['timeout'] = 6.0;
        $config['maxConnectTime'] = 3.0;
        $client = new Client(new Config($config));
        $client->connect();
        return $client;
    }

    private function value(Client $client, int $id): int
    {
        return $client->rawQuery("SELECT value FROM `{$this->table}` WHERE id = {$id}")[0]['value'];
    }
}
