<?php

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private ?Client $client = null;
    private string $table;
    private int $reportMode;

    protected function setUp(): void
    {
        if (!defined('MYSQL_CONFIG') || MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set MYSQLI_TEST_* or FAST_DB_TEST_* database environment variables.');
        }
        $this->reportMode = (new \mysqli_driver())->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->table = 'mysqli_regression_' . bin2hex(random_bytes(8));
        $this->client = new Client(new Config(MYSQL_CONFIG));
        $this->client->rawQuery("CREATE TABLE `{$this->table}` (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(128) NULL,
            age INT NULL,
            amount DOUBLE NULL,
            flag TINYINT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->rawQuery('ROLLBACK');
                $this->client->rawQuery("DROP TABLE IF EXISTS `{$this->table}`");
            } finally {
                $this->client->close();
                mysqli_report($this->reportMode);
            }
        }
    }

    private function seed(): void
    {
        $this->assertTrue($this->client->query((new QueryBuilder())->insertAll($this->table, [
            ['id' => 1, 'name' => 'siam,你好', 'age' => 21],
            ['id' => 2, 'name' => 'second', 'age' => 22],
            ['id' => 3, 'name' => 'third', 'age' => 23],
        ])));
    }

    public function testDistinctReturnsUniqueColumnCombinations(): void
    {
        $this->assertTrue($this->client->query((new QueryBuilder())->insertAll($this->table, [
            ['name' => 'same', 'age' => 21],
            ['name' => 'same', 'age' => 21],
            ['name' => 'same', 'age' => 22],
            ['name' => 'other', 'age' => 23],
        ])));
        $builder = new QueryBuilder();
        $rows = $this->client->query($builder->distinct()->orderBy('name', 'ASC')->get($this->table, null, 'name'));
        $this->assertSame([['name' => 'other'], ['name' => 'same']], $rows);
        $rows = $this->client->query($builder->distinct()->where('name', 'same')->orderBy('age', 'ASC')->get($this->table, null, ['name', 'age']));
        $this->assertSame([['name' => 'same', 'age' => 21], ['name' => 'same', 'age' => 22]], $rows);
        $rows = $this->client->query($builder->where('name', 'same')->get($this->table, null, 'name'));
        $this->assertCount(3, $rows);
    }

    public function testDisabledDistinctRetainsDuplicateRows(): void
    {
        $this->assertTrue($this->client->query((new QueryBuilder())->insertAll($this->table, [
            ['name' => 'same'], ['name' => 'same'],
        ])));
        $rows = $this->client->query((new QueryBuilder())->distinct()->distinct(false)->get($this->table, null, 'name'));
        $this->assertSame([['name' => 'same'], ['name' => 'same']], $rows);
    }

    public function testPreparedWritesReturnSuccessAndMetadata(): void
    {
        $seen = [];
        $this->client->onQuery(function ($result) use (&$seen): void { $seen[] = $result; });
        $this->assertTrue($this->client->query((new QueryBuilder())->insert($this->table, ['name' => 'hello'])));
        $this->assertSame(1, $this->client->getLastInsertId());
        $this->assertSame(1, $this->client->getLastAffectRows());
        $this->assertTrue($this->client->query((new QueryBuilder())->where('id', 1)->update($this->table, ['name' => 'updated'])));
        $this->assertSame(1, $this->client->getLastAffectRows());
        $this->assertTrue($this->client->query((new QueryBuilder())->where('id', 1)->delete($this->table)));
        $this->assertSame(1, $this->client->getLastAffectRows());
        $this->assertSame([true, true, true], $seen);
    }

    public function testPreparedTypesAndEmptySelect(): void
    {
        $data = ['name' => "Don't worry? 你好", 'age' => null, 'amount' => 1.25, 'flag' => true];
        $this->assertTrue($this->client->query((new QueryBuilder())->insert($this->table, $data)));
        $rows = $this->client->query((new QueryBuilder())->getOne($this->table));
        $this->assertSame($data['name'], $rows[0]['name']);
        $this->assertNull($rows[0]['age']);
        $this->assertSame(1.25, $rows[0]['amount']);
        $this->assertSame(1, $rows[0]['flag']);
        $this->assertSame([], $this->client->query((new QueryBuilder())->where('id', 999)->get($this->table)));
    }

    public function testBulkInsertReplaceAndRawWrites(): void
    {
        $this->seed();
        $this->assertSame(3, $this->client->getLastAffectRows());
        $this->assertTrue($this->client->query((new QueryBuilder())->replace($this->table, ['id' => 1, 'name' => 'replacement'])));
        $this->assertSame('replacement', $this->client->query((new QueryBuilder())->where('id', 1)->getOne($this->table))[0]['name']);
        $this->assertTrue($this->client->rawQuery("UPDATE `{$this->table}` SET age=42 WHERE id=2"));
        $this->assertSame(1, $this->client->getLastAffectRows());
    }

    public function testChainedLimitsRestrictRowsChanged(): void
    {
        $this->seed();
        $this->assertTrue($this->client->query((new QueryBuilder())->orderBy('id', 'ASC')->limit(1)->update($this->table, ['age' => 99])));
        $this->assertSame(1, $this->client->getLastAffectRows());
        $this->assertSame(1, count($this->client->query((new QueryBuilder())->where('age', 99)->get($this->table))));
        $this->assertTrue($this->client->query((new QueryBuilder())->orderBy('id', 'ASC')->limit(1)->delete($this->table)));
        $this->assertSame(1, $this->client->getLastAffectRows());
        $this->assertCount(2, $this->client->query((new QueryBuilder())->get($this->table)));
    }

    public function testBoundWhereExpressionsExecute(): void
    {
        $this->seed();
        $builder = new QueryBuilder();
        $rows = $this->client->query($builder->where('find_in_set(?, name)', ['siam'])->get($this->table));
        $this->assertSame([1], array_column($rows, 'id'));
        $rows = $this->client->query($builder->where('(id = ? OR id = ?)', [1, 3])->orderBy('id', 'ASC')->get($this->table));
        $this->assertSame([1, 3], array_column($rows, 'id'));
    }

    public function testUnionDoesNotAffectSubsequentExecution(): void
    {
        $this->seed();
        $builder = new QueryBuilder();
        $builder->union((new QueryBuilder())->where('id', 2)->get($this->table, null, 'id'));
        $rows = $this->client->query($builder->where('id', 1)->get($this->table, null, 'id'));
        $this->assertEqualsCanonicalizing([1, 2], array_column($rows, 'id'));
        $rows = $this->client->query($builder->where('id', 1)->get($this->table, null, 'id'));
        $this->assertSame([1], array_column($rows, 'id'));
    }

    public function testSubQueryCanExecuteMoreThanOnce(): void
    {
        $this->seed();
        $sub = QueryBuilder::subQuery();
        $sub->where('age', 21)->get($this->table, null, 'id');
        for ($i = 0; $i < 2; $i++) {
            $rows = $this->client->query((new QueryBuilder())->where('id', $sub, 'IN')->get($this->table));
            $this->assertSame([1], array_column($rows, 'id'));
        }
    }

    public function testQualifiedTableNameExecutes(): void
    {
        $this->seed();
        $rows = $this->client->query((new QueryBuilder())->get(MYSQL_CONFIG['database'] . '.' . $this->table));
        $this->assertCount(3, $rows);
    }

    public function testOrHavingUsesEqualityByDefault(): void
    {
        $this->seed();
        $rows = $this->client->query((new QueryBuilder())->groupBy('age')->having('COUNT(*)', 3)->orHaving('COUNT(*)', 1)->get($this->table, null, 'age, COUNT(*) AS total'));
        $this->assertCount(3, $rows);
        $this->assertSame([1, 1, 1], array_column($rows, 'total'));
    }

    public function testDisabledLocksDoNotBlockAnotherConnection(): void
    {
        $this->seed();
        $other = new Client(new Config(MYSQL_CONFIG));
        try {
            $other->rawQuery('SET SESSION innodb_lock_wait_timeout=1');
            foreach (['update', 'share'] as $mode) {
                $this->client->rawQuery('START TRANSACTION');
                $builder = new QueryBuilder();
                if ($mode === 'update') {
                    $builder->selectForUpdate(true)->selectForUpdate(false);
                } else {
                    $builder->lockInShareMode(true)->lockInShareMode(false);
                }
                $this->assertCount(1, $this->client->query($builder->where('id', 1)->get($this->table)));
                $this->assertTrue($other->rawQuery("UPDATE `{$this->table}` SET age=age+1 WHERE id=1"));
                $this->client->rawQuery('ROLLBACK');
            }
        } finally {
            $other->close();
        }
    }

    public function testDatabaseErrorsPreserveCodeAndCause(): void
    {
        $this->seed();
        foreach (['prepared', 'raw'] as $mode) {
            try {
                if ($mode === 'prepared') {
                    $this->client->query((new QueryBuilder())->insert($this->table, ['id' => 1]));
                } else {
                    $this->client->rawQuery("INSERT INTO `{$this->table}` (id) VALUES (1)");
                }
                $this->fail('Duplicate primary key must fail');
            } catch (Exception $error) {
                $this->assertSame(1062, $error->getCode());
                $this->assertInstanceOf(\mysqli_sql_exception::class, $error->getPrevious());
                $this->assertNull($this->client->getLastAffectRows());
            }
        }
        $this->assertTrue($this->client->ping());
    }

    public function testBindingFailureIsReportedAndConnectionStillWorks(): void
    {
        try {
            $this->client->query((new QueryBuilder())->raw('SELECT ? + ?', [1]));
            $this->fail('Missing bind parameter must fail');
        } catch (Exception $error) {
            $this->assertInstanceOf(\ArgumentCountError::class, $error->getPrevious());
            $this->assertStringContainsString('execute error', $error->getMessage());
        }
        $this->assertSame([['value' => 2]], $this->client->query((new QueryBuilder())->raw('SELECT ? AS value', [2])));
    }

    public function testFailuresStillThrowWhenMysqliReportingIsOff(): void
    {
        $this->seed();
        mysqli_report(MYSQLI_REPORT_OFF);
        $callbacks = 0;
        $this->client->onQuery(function () use (&$callbacks): void { $callbacks++; });
        foreach (['prepared', 'raw'] as $mode) {
            try {
                if ($mode === 'prepared') {
                    $this->client->query((new QueryBuilder())->insert($this->table, ['id' => 1]));
                } else {
                    $this->client->rawQuery("INSERT INTO `{$this->table}` (id) VALUES (1)");
                }
                $this->fail('Database failure must throw with reporting disabled');
            } catch (Exception $error) {
                $this->assertSame(1062, $error->getCode());
                $this->assertNull($this->client->getLastAffectRows());
            }
        }
        try {
            $this->client->query((new QueryBuilder())->raw("SELECT missing_column FROM `{$this->table}`"));
            $this->fail('Prepare failure must throw');
        } catch (Exception $error) {
            $this->assertSame(1054, $error->getCode());
        }
        $this->assertSame(0, $callbacks);
    }
}
