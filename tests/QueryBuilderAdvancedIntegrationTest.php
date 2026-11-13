<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, Expression, QueryBuilder};
use EasySwoole\Mysqli\QueryBuilder\{ComparisonOperator, ConditionConjunction, JoinType, LockWaitPolicy, SortDirection};
use PHPUnit\Framework\TestCase;

/** 所有测试夹具仅在当前连接内有效，客户端关闭时自动清理。 */
final class QueryBuilderAdvancedIntegrationTest extends TestCase
{
    private ?Client $client = null;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') { self::markTestSkipped('Set MYSQLI_TEST_* for live SQL checks'); }
        $this->client = new Client(new Config(MYSQL_CONFIG));
        $this->client->connect();
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_advanced (
            id INT PRIMARY KEY, tenantId INT NOT NULL DEFAULT 1, name VARCHAR(100) NULL,
            score INT NOT NULL DEFAULT 0, baseline INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT \'new\'
        ) ENGINE=InnoDB');
        $this->client->rawQuery("INSERT INTO qb_advanced (id, tenantId, name, score, baseline) VALUES
            (1, 1, 'a', 10, 5), (2, 1, 'b', 20, 25), (3, 2, 'b', 30, 15)");
    }

    protected function tearDown(): void { $this->client?->close(); }

    public function testBatchUpsertUsesEachRowsIncomingValueAndPreservesNull(): void
    {
        $builder = (new QueryBuilder())->upsert('qb_advanced', [
            ['id' => 1, 'name' => 'first', 'score' => new Expression('? + ?', [10, 1])],
            ['score' => 22, 'name' => null, 'id' => 2],
            ['id' => 4, 'name' => 'fourth', 'score' => 44],
        ], ['name', 'score']);
        self::assertTrue($this->client->query($builder));
        self::assertSame([
            ['id' => 1, 'name' => 'first', 'score' => 11],
            ['id' => 2, 'name' => null, 'score' => 22],
            ['id' => 4, 'name' => 'fourth', 'score' => 44],
        ], $this->client->query((new QueryBuilder())->whereIn('id', [1, 2, 4])->orderByAsc('id')
            ->get('qb_advanced', null, 'id,name,score')));
    }

    public function testBatchUpsertCanMixIncomingFieldsAndOneFixedUpdateValue(): void
    {
        $builder = (new QueryBuilder())->upsert('qb_advanced', [
            ['id' => 1, 'name' => 'first'], ['id' => 2, 'name' => 'second'],
        ], ['name', 'status' => new Expression('UPPER(?)', ['done'])]);
        self::assertTrue($this->client->query($builder));
        self::assertSame([
            ['id' => 1, 'name' => 'first', 'status' => 'DONE'],
            ['id' => 2, 'name' => 'second', 'status' => 'DONE'],
        ], $this->client->query((new QueryBuilder())->whereIn('id', [1, 2])->orderByAsc('id')
            ->get('qb_advanced', null, 'id,name,status')));
    }

    public function testMixedUpsertEvaluatesAssignmentsInOrderAgainstTheTargetRow(): void
    {
        $builder = (new QueryBuilder())->upsert('qb_advanced', [
            ['id' => 1, 'score' => 20, 'baseline' => 99, 'tenantId' => 99],
        ], ['score', 'baseline' => new Expression('qb_advanced.score + ?', [1]), 'tenantId' => QueryBuilder::inc(1)]);
        self::assertTrue($this->client->query($builder));
        self::assertSame([['score' => 20, 'baseline' => 21, 'tenantId' => 2]],
            $this->client->rawQuery('SELECT score, baseline, tenantId FROM qb_advanced WHERE id = 1'));
    }

    public function testTypedAndLegacyWriteExpressionsKeepValueOrder(): void
    {
        $builder = (new QueryBuilder())->where('id', 1)->update('qb_advanced', [
            'name' => new Expression('CONCAT(?, ?)', ["O'Reilly", ' safe']),
            'score' => QueryBuilder::func('score + ?', [2]),
        ]);
        self::assertTrue($this->client->query($builder));
        self::assertSame([['name' => "O'Reilly safe", 'score' => 12]],
            $this->client->query((new QueryBuilder())->where('id', 1)->get('qb_advanced', null, 'name,score')));
        self::assertSame([['display_name' => 'anonymous']], $this->client->query(
            (new QueryBuilder())->getScalar('(SELECT NULL AS name) seed', new Expression('COALESCE(name, ?) AS display_name', ['anonymous']))
        ));
    }

    public function testSelectWhereHavingAndOrderExpressionsBindInSqlOrder(): void
    {
        $builder = (new QueryBuilder())->orderByRaw('FIELD(tenantId, ?) DESC', [1])
            ->where('tenantId', 1)->whereRaw('score > ? OR name = ?', [100, 'b'])
            ->groupBy('tenantId')->havingGroup(static function (QueryBuilder $q): void {
                $q->havingRaw('SUM(score) > ?', [10])->orHaving('COUNT(*)', 3, '>');
            })->fields(['tenantId', new Expression('SUM(score + ?) AS total', [1])])->get('qb_advanced');
        self::assertSame([1, 1, 100, 'b', 10, 3, 1], $builder->getLastBindParams());
        $rows = $this->client->query($builder);
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['tenantId']);
        self::assertSame(21, (int) $rows[0]['total']);
    }

    public function testColumnComparisonsAndOnGroupsWorkWithSubqueryAliases(): void
    {
        $subquery = QueryBuilder::subQuery('u')->selectRaw('? AS id, ? AS tenantId, ? AS status', [1, 1, 'active'])
            ->get('(SELECT 1) seed');
        $builder = (new QueryBuilder())->join($subquery, new Expression('u.id = o.id AND u.tenantId = ?', [1]), 'INNER')
            ->joinOn('u', 'u.tenantId', 'o.tenantId')
            ->joinWhereGroup('u', static fn(QueryBuilder $q) => $q->where('u.status', 'active')->orWhere('u.status', 'staff'))
            ->whereColumn('o.score', 'o.baseline', '>')->where('o.tenantId', 1)
            ->get('qb_advanced o', null, 'o.id');
        self::assertSame([1, 1, 'active', 1, 'active', 'staff', 1], $builder->getLastBindParams());
        self::assertSame([['id' => 1]], $this->client->query($builder));
    }

    public function testRawOrPredicatesRemainInsideTenantBoundary(): void
    {
        $builder = (new QueryBuilder())->where('tenantId', 1)->whereRaw('name = ? OR name = ?', ['a', 'b'])
            ->orderByAsc('id')->get('qb_advanced', null, 'id');
        self::assertSame([['id' => 1], ['id' => 2]], $this->client->query($builder));
    }

    public function testEnumsComposeAcrossJoinConditionsHavingAndOrdering(): void
    {
        $subquery = QueryBuilder::subQuery('u')->selectRaw('? AS tenantId', [1])->get('(SELECT 1) seed');
        $builder = (new QueryBuilder())->join($subquery, 'u.tenantId=o.tenantId', JoinType::Inner)
            ->joinWhere('u', 'u.tenantId', 1, ComparisonOperator::Equal, ConditionConjunction::And)
            ->where('o.tenantId', 1, ComparisonOperator::Equal, ConditionConjunction::And)
            ->whereColumn('o.score', 'o.baseline', ComparisonOperator::LessThan)
            ->groupBy('o.tenantId')->having('total', 10, ComparisonOperator::GreaterThan, ConditionConjunction::And)
            ->havingColumn('total', 'baseline_total', ComparisonOperator::LessThan)
            ->orderBy('o.tenantId', SortDirection::Desc)
            ->get('qb_advanced o', null, 'o.tenantId,SUM(o.score) AS total,SUM(o.baseline) AS baseline_total');
        self::assertSame([1, 1, 1, 10], $builder->getLastBindParams());
        $rows = $this->client->query($builder);
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['tenantId']);
        self::assertSame(20, (int) $rows[0]['total']);
        self::assertSame(25, (int) $rows[0]['baseline_total']);
    }

    public function testLockWaitPolicyEnumsExecuteOnServer(): void
    {
        foreach ([LockWaitPolicy::Default, LockWaitPolicy::Nowait, LockWaitPolicy::SkipLocked] as $policy) {
            $builder = (new QueryBuilder())->selectForUpdate(true, $policy)->where('id', 1)->get('qb_advanced', null, 'id');
            self::assertSame([['id' => 1]], $this->client->query($builder));
            self::assertSame(QueryBuilder::TS_OP_LOCK_FOR_UPDATE, $builder->getLastTransactionOp());
        }
    }

    public function testNaturalJoinEnumUsesCommonColumnsWithoutOn(): void
    {
        $builder = (new QueryBuilder())->join('(SELECT 1 AS tenantId) u', '', JoinType::Natural)
            ->orderBy('o.id', SortDirection::Asc)->get('qb_advanced o', null, 'o.id');
        self::assertSame([['id' => 1], ['id' => 2]], $this->client->query($builder));
    }
}
