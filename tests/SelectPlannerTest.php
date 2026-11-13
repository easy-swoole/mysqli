<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Expression, QueryBuilder};
use EasySwoole\Mysqli\QueryBuilder\{SelectPlanner, SqlBuffer};
use PHPUnit\Framework\TestCase;

final class SelectPlannerTest extends TestCase
{
    public function testFromSubSnapshotsReferencedValues(): void
    {
        $value = 7;
        $source = SelectPlanner::fromSub('SELECT ? AS id', [&$value], 'odd`.alias');
        $value = 99;
        self::assertSame(['sql' => '(SELECT ? AS id) AS `odd``.alias`',
            'params' => [7], 'alias' => 'odd`.alias'], $source);
    }

    public function testInvalidSourcesAreRejectedBeforeProducingAPlan(): void
    {
        foreach ([[null, 's'], ['UPDATE users SET id=1', 's'], ['SELECT 1', ''], ['SELECT 1', "bad\0alias"]] as [$sql, $alias]) {
            $error = null;
            try { SelectPlanner::fromSub($sql, [], $alias); }
            catch (\InvalidArgumentException $caught) { $error = $caught; }
            self::assertNotNull($error);
        }
    }

    public function testSelectBodyResolvesPrefixAndIndexHints(): void
    {
        [$body, $projection] = SelectPlanner::selectBody('`orders` o', ['id', new Expression('? AS marker', [7])], 'app_', null,
            ['FORCE' => ['idx`name'], 'USE' => [], 'IGNORE' => ['idx_state']]);
        self::assertInstanceOf(SqlBuffer::class, $body);
        self::assertSame(' FROM `app_orders` o FORCE INDEX (`idx``name`) IGNORE INDEX (`idx_state`)', $body->sql);
        self::assertSame('id, ? AS marker', $projection);
        self::assertSame([7], $body->parameters);
        self::assertSame('`app_orders` o', $body->tableName);
    }

    public function testFromSourceParametersFollowSelectedExpressionsWithoutApplyingOuterPrefix(): void
    {
        $source = SelectPlanner::fromSub('SELECT ? AS id', [2], 's');
        [$body, $projection] = SelectPlanner::selectBody(null, [new Expression('? AS marker', [1]), 's.id'], 'outer_', $source,
            ['FORCE' => [], 'USE' => [], 'IGNORE' => []]);
        self::assertSame(' FROM (SELECT ? AS id) AS `s`', $body->sql);
        self::assertSame('? AS marker, s.id', $projection);
        self::assertSame([1, 2], $body->parameters);
        self::assertSame('s', $body->tableName);
        foreach ([['users', []], [null, ['FORCE' => ['idx']]]] as [$table, $hints]) {
            $error = null;
            try { SelectPlanner::selectBody($table, '*', '', $source, $hints); }
            catch (\InvalidArgumentException $caught) { $error = $caught; }
            self::assertNotNull($error);
        }
    }

    public function testCountIgnoresOuterLockAndPaginationWithoutChangingTheTemplate(): void
    {
        $template = (new QueryBuilder())->setPrefix('app_')->where('tenantId', 7)->orderByDesc('id')
            ->limit(10)->forUpdate()->withTotalCount();
        $count = (clone $template)->count('orders', 'total`count');
        self::assertSame('SELECT COUNT(*) AS `total``count` FROM (SELECT  1 FROM `app_orders` WHERE  `tenantId` = ? ) AS `__mysqli_count` LIMIT 1',
            $count->getLastPrepareQuery());
        self::assertSame([7], $count->getLastBindParams());

        $template->get('orders');
        self::assertStringStartsWith('SELECT SQL_CALC_FOUND_ROWS * FROM `app_orders`', $template->getLastPrepareQuery());
        self::assertMatchesRegularExpression('/ORDER BY id DESC\s+LIMIT 10 FOR UPDATE$/', $template->getLastPrepareQuery());
    }

    public function testCountPreservesGroupingAndExplicitProjection(): void
    {
        $count = (new QueryBuilder())->fields(['tenantId', new Expression('SUM(score + ?) AS total', [5])])
            ->groupBy('tenantId')->having('total', 50, '>')->count('orders');
        self::assertStringContainsString('SELECT  tenantId, SUM(score + ?) AS total FROM `orders`', $count->getLastPrepareQuery());
        self::assertMatchesRegularExpression('/GROUP BY tenantId\s+HAVING/', $count->getLastPrepareQuery());
        self::assertSame([5, 50], $count->getLastBindParams());
    }

    public function testAggregateExpressionsQuoteColumnsAndPreserveExpressionParameters(): void
    {
        $sum = SelectPlanner::aggregateExpression(' sum ', 'app.orders.amount', 'odd`alias', false);
        self::assertSame('SUM(`app`.`orders`.`amount`) AS `odd``alias`', $sum->getSql());
        self::assertSame([], $sum->getParameters());
        $avg = SelectPlanner::aggregateExpression('AVG', new Expression('score + ?', [5]), 'mean', false);
        self::assertSame('AVG(score + ?) AS `mean`', $avg->getSql());
        self::assertSame([5], $avg->getParameters());
        self::assertSame('COUNT(*) AS `rows`', SelectPlanner::aggregateExpression('count', '*', 'rows', false)->getSql());
        foreach ([['MEDIAN', 'amount', 'x', false], ['SUM', 'amount', 'x', true], ['SUM', 'amount', '', false]] as $arguments) {
            $error = null;
            try { SelectPlanner::aggregateExpression(...$arguments); }
            catch (\InvalidArgumentException $caught) { $error = $caught; }
            self::assertNotNull($error);
        }
    }

    public function testCountAndAggregateKeepSubclassCloneAndGetDispatch(): void
    {
        $calls = new \ArrayObject();
        $builder = new class($calls) extends QueryBuilder {
            public function __construct(private \ArrayObject $calls) { parent::__construct(); }
            public function __clone() { $this->calls[] = 'clone'; $this->where('clone_flag', 9); }
            public function get($tableName, $numRows = null, $columns = null): QueryBuilder
            { $this->calls[] = 'get'; $this->where('get_flag', 10); return parent::get($tableName, $numRows, $columns); }
        };
        $builder->where('tenantId', 7)->count('orders');
        self::assertSame([7, 9, 10], $builder->getLastBindParams());
        self::assertSame(['clone', 'get'], $calls->getArrayCopy());
        try { $builder->count('orders', ''); self::fail('Invalid alias must be rejected before cloning'); }
        catch (\InvalidArgumentException $error) { }
        self::assertSame(['clone', 'get'], $calls->getArrayCopy());
        $builder->sum('orders', 'amount');
        self::assertSame([10], $builder->getLastBindParams());
        self::assertSame(['clone', 'get', 'get'], $calls->getArrayCopy());
    }

}
