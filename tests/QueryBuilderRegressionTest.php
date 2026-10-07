<?php

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderRegressionTest extends TestCase
{
    public function testDistinctSelectsUniqueColumnCombinations(): void
    {
        $builder = new QueryBuilder();
        $this->assertSame($builder, $builder->distinct());
        $builder->where('active', 1)->fields(['name', 'age'])->orderBy('name', 'ASC')->limit(5)->get('users');
        $this->assertSame('SELECT DISTINCT name, age FROM `users` WHERE  `active` = ?  ORDER BY name ASC  LIMIT 5', $builder->getLastPrepareQuery());
        $this->assertSame([1], $builder->getLastBindParams());
        $this->assertSame(['DISTINCT'], $builder->getLastQueryOptions());
    }

    public function testDistinctIsIdempotentAndCanBeDisabled(): void
    {
        $builder = new QueryBuilder();
        $builder->distinct()->distinct(true)->get('users');
        $this->assertSame('SELECT DISTINCT * FROM `users`', $builder->getLastPrepareQuery());
        $builder->setQueryOption('DISTINCT')->distinct(false)->get('users');
        $this->assertSame('SELECT  * FROM `users`', $builder->getLastPrepareQuery());
        $builder->distinct()->distinct(false)->distinct()->get('users');
        $this->assertSame('SELECT DISTINCT * FROM `users`', $builder->getLastPrepareQuery());
    }

    public function testDistinctPreservesOtherOptionsAndReplacesAll(): void
    {
        $builder = new QueryBuilder();
        $builder->setQueryOption(['ALL', 'SQL_CALC_FOUND_ROWS'])->distinct()->get('users');
        $this->assertSame('SELECT SQL_CALC_FOUND_ROWS DISTINCT * FROM `users`', $builder->getLastPrepareQuery());
        $builder->withTotalCount()->distinct()->distinct(false)->get('users');
        $this->assertSame('SELECT SQL_CALC_FOUND_ROWS * FROM `users`', $builder->getLastPrepareQuery());
        $this->assertSame(['SQL_CALC_FOUND_ROWS'], $builder->getLastQueryOptions());
    }

    public function testDistinctDoesNotLeakIntoNextQuery(): void
    {
        $builder = new QueryBuilder();
        $builder->distinct()->get('users');
        $this->assertSame([], $builder->getQueryOptions());
        $builder->get('users');
        $this->assertSame('SELECT  * FROM `users`', $builder->getLastPrepareQuery());
        $this->assertSame([], $builder->getLastQueryOptions());
    }

    public function testDistinctSubQueryPreservesSqlAndParameters(): void
    {
        $sub = QueryBuilder::subQuery();
        $sub->distinct()->where('active', 1)->get('users', null, 'name');
        $builder = new QueryBuilder();
        $builder->where('name', $sub, 'IN')->get('users');
        $this->assertSame('SELECT  * FROM `users` WHERE  `name` IN (  (SELECT DISTINCT name FROM `users` WHERE  `active` = ? )  ) ', $builder->getLastPrepareQuery());
        $this->assertSame([1], $builder->getLastBindParams());
    }

    public function testChainedWriteLimitsAndExplicitOverrides(): void
    {
        $builder = new QueryBuilder();
        $builder->limit(1)->delete('users');
        $this->assertSame('DELETE FROM `users` LIMIT 1', $builder->getLastPrepareQuery());
        $builder->limit(1)->update('users', ['name' => 'x']);
        $this->assertSame('UPDATE `users` SET `name` = ? LIMIT 1', $builder->getLastPrepareQuery());
        $builder->limit(1)->delete('users', 2);
        $this->assertSame('DELETE FROM `users` LIMIT 2', $builder->getLastPrepareQuery());
        $builder->limit(1)->update('users', ['name' => 'x'], 2);
        $this->assertSame('UPDATE `users` SET `name` = ? LIMIT 2', $builder->getLastPrepareQuery());
    }

    public function testUnionDoesNotLeakIntoLaterQueries(): void
    {
        $builder = new QueryBuilder();
        $builder->union((new QueryBuilder())->where('id', 9)->get('other'))->get('users');
        $this->assertStringContainsString(' UNION ', $builder->getLastPrepareQuery());
        $builder->where('id', 1)->get('users');
        $this->assertSame('SELECT  * FROM `users` WHERE  `id` = ? ', $builder->getLastPrepareQuery());
        $this->assertSame([1], $builder->getLastBindParams());
    }

    public function testLocksCanBeDisabledBeforeBuilding(): void
    {
        $builder = new QueryBuilder();
        $builder->selectForUpdate(true, 'NOWAIT')->selectForUpdate(false)->get('users');
        $this->assertSame('SELECT  * FROM `users`', $builder->getLastPrepareQuery());
        $this->assertNull($builder->getLastTransactionOp());
        $builder->lockInShareMode(true)->lockInShareMode(false)->get('users');
        $this->assertSame('SELECT  * FROM `users`', $builder->getLastPrepareQuery());
        $this->assertNull($builder->getLastTransactionOp());
    }

    public function testReusableSubQueryPreservesSqlAndParameters(): void
    {
        $sub = QueryBuilder::subQuery();
        $sub->where('active', 1)->get('users', null, 'id');
        $builder = new QueryBuilder();
        $builder->where('id', $sub, 'IN')->get('users');
        $sql = $builder->getLastPrepareQuery();
        $builder->where('id', $sub, 'IN')->get('users');
        $this->assertSame($sql, $builder->getLastPrepareQuery());
        $this->assertSame([1], $builder->getLastBindParams());
        $this->assertSame([1], $sub->getLastBindParams());
    }

    public function testQualifiedTableNames(): void
    {
        $builder = new QueryBuilder();
        $builder->get('demo.users');
        $this->assertSame('SELECT  * FROM `demo`.`users`', $builder->getLastPrepareQuery());
        $builder->setPrefix('prefix_')->get('users');
        $this->assertSame('SELECT  * FROM `prefix_users`', $builder->getLastPrepareQuery());
        $builder->get('demo.users');
        $this->assertSame('SELECT  * FROM `demo`.`users`', $builder->getLastPrepareQuery());
    }

    public function testOrHavingHasConsistentDefaults(): void
    {
        $builder = new QueryBuilder();
        $builder->groupBy('status')->having('COUNT(*)', 1, '>')->orHaving('COUNT(*)', 2)->get('users');
        $this->assertStringContainsString('OR COUNT(*) = ? ', $builder->getLastPrepareQuery());
        $this->assertSame([1, 2], $builder->getLastBindParams());
        $builder->orHaving('COUNT(*) > 0')->get('users');
        $this->assertStringContainsString('HAVING  COUNT(*) > 0', $builder->getLastPrepareQuery());
        $this->assertSame([], $builder->getLastBindParams());
    }

    public function testRawWhereExpressionsBindValues(): void
    {
        $builder = new QueryBuilder();
        $builder->where('find_in_set(?, name)', ['x'])->where('id = ? OR id = ?', [1, 2])->get('users');
        $this->assertStringContainsString('AND id = ? OR id = ?', $builder->getLastPrepareQuery());
        $this->assertSame(['x', 1, 2], $builder->getLastBindParams());
        $this->expectException(Exception::class);
        $builder->where('id', [1, 2])->get('users');
    }

    public function testInvalidLockModeIsRejected(): void
    {
        $this->expectException(Exception::class);
        (new QueryBuilder())->setLockTableMode('INVALID');
    }

    public function testValidLockModesAreNormalized(): void
    {
        $builder = new QueryBuilder();
        $builder->setLockTableMode('write')->lockTable('users');
        $this->assertSame('LOCK TABLES users WRITE', $builder->getLastPrepareQuery());
        $builder->setLockTableMode('read')->lockTable('users');
        $this->assertSame('LOCK TABLES users READ', $builder->getLastPrepareQuery());
    }

    public function testDiagnosticSqlDoesNotReplaceQuotedQuestionMarks(): void
    {
        $builder = new QueryBuilder();
        $builder->raw("SELECT '?' AS literal, ? AS value, ? IS NULL, ? AS flag /* ? */", ["Don't worry?", null, true]);
        $this->assertSame("SELECT '?' AS literal, 'Don\\'t worry?' AS value, NULL IS NULL, 1 AS flag /* ? */", $builder->getLastQuery());
        $builder->raw('? + ?', [1, 2]);
        $this->assertSame('1 + 2', $builder->getLastQuery());
    }
}
