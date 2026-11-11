<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Expression, QueryBuilder};
use EasySwoole\Mysqli\QueryBuilder\{SelectPlan, SelectPlanner};
use PHPUnit\Framework\TestCase;

final class SelectPlannerTest extends TestCase
{
    private function state(array $changes = []): array
    {
        return array_replace([
            'fields' => '*', 'groups' => [], 'havingConditions' => [], 'unionBranches' => [],
            'queryOptions' => [], 'ordering' => [], 'limit' => null, 'forUpdate' => false,
            'lockInShareMode' => false, 'selectLockOption' => '', 'fromSource' => null,
            'whereConditions' => [], 'joins' => [], 'prefix' => 'app_',
        ], $changes);
    }

    public function testFromSubSnapshotsReferencedValuesAndKeepsTrailingCommentsSafe(): void
    {
        $value = 7;
        $source = SelectPlanner::fromSub('SELECT ? AS id -- comment', [&$value], 'odd`.alias');
        $value = 99;
        self::assertSame(['sql' => "(SELECT ? AS id -- comment\n) AS `odd``.alias`",
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

    public function testSelectHeaderRetainsExactSpacingPrefixMetadataAndIndexHints(): void
    {
        $plan = SelectPlanner::selectHead('`orders` o', ['id', new Expression('? AS marker', [7])], ['DISTINCT'], 'app_', null,
            ['FORCE' => ['idx`name'], 'USE' => [], 'IGNORE' => ['idx_state']]);
        self::assertInstanceOf(SelectPlan::class, $plan);
        self::assertSame('SELECT DISTINCT id, ? AS marker FROM `app_orders` o FORCE INDEX (`idx``name`) IGNORE INDEX (`idx_state`)', $plan->sql);
        self::assertSame([7], $plan->parameters);
        self::assertSame('`app_orders` o', $plan->tableName);
        $plain = SelectPlanner::selectHead('orders', '*', [], 'app_', null, ['FORCE' => [], 'USE' => [], 'IGNORE' => []]);
        self::assertSame('SELECT  * FROM `app_orders`', $plain->sql);
        self::assertSame('app_orders', $plain->tableName);
    }

    public function testFromSourceParametersFollowSelectedExpressionsWithoutApplyingOuterPrefix(): void
    {
        $source = SelectPlanner::fromSub('SELECT ? AS id', [2], 's');
        $plan = SelectPlanner::selectHead(null, [new Expression('? AS marker', [1]), 's.id'], [], 'outer_', $source,
            ['FORCE' => [], 'USE' => [], 'IGNORE' => []]);
        self::assertSame('SELECT  ? AS marker, s.id FROM (SELECT ? AS id) AS `s`', $plan->sql);
        self::assertSame([1, 2], $plan->parameters);
        self::assertSame('s', $plan->tableName);
        foreach ([['users', []], [null, ['FORCE' => ['idx']]]] as [$table, $hints]) {
            $error = null;
            try { SelectPlanner::selectHead($table, '*', [], '', $source, $hints); }
            catch (\InvalidArgumentException $caught) { $error = $caught; }
            self::assertNotNull($error);
        }
    }

    public function testCountPlanningChangesOnlyTheCopiedOuterQueryAndPreservesProjectionParameters(): void
    {
        $projection = new Expression('SUM(score + ?) AS total', [1]);
        $branch = ['SELECT ? AS total ORDER BY total DESC LIMIT 2', [9], false, true];
        $state = $this->state(['fields' => [$projection], 'groups' => ['userId'], 'unionBranches' => [$branch],
            'ordering' => [['field' => new Expression('FIELD(id, ?)', [99])]], 'limit' => 10,
            'forUpdate' => true, 'lockInShareMode' => true, 'selectLockOption' => 'NOWAIT',
            'queryOptions' => ['DISTINCT', 'SQL_CALC_FOUND_ROWS'], 'whereConditions' => [['', 'tenantId', '=', 7]]]);
        $before = $state;
        $plan = SelectPlanner::countInput($state, 'total`count');
        self::assertSame($before, $state);
        self::assertSame('`total``count`', $plan['quotedAlias']);
        self::assertSame([$projection], $plan['state']['fields']);
        self::assertSame([$branch], $plan['state']['unionBranches']);
        self::assertSame([['', 'tenantId', '=', 7]], $plan['state']['whereConditions']);
        self::assertSame([], $plan['state']['ordering']);
        self::assertNull($plan['state']['limit']);
        self::assertFalse($plan['state']['forUpdate']);
        self::assertFalse($plan['state']['lockInShareMode']);
        self::assertSame('', $plan['state']['selectLockOption']);
        self::assertSame(['DISTINCT'], $plan['state']['queryOptions']);
        self::assertSame('app_', $plan['state']['prefix']);
    }

    public function testCountPlanningPreservesPositionalGroupingAndAssignsNamesToCompoundGroups(): void
    {
        $ordinary = SelectPlanner::countInput($this->state(), 'aggregate')['state'];
        self::assertSame('1 AS `__mysqli_row`', $ordinary['fields'][0]->getSql());
        $grouped = SelectPlanner::countInput($this->state(['groups' => ["COALESCE(status, 'x,y'), tenantId"]]), 'aggregate')['state'];
        self::assertSame(["COALESCE(status, 'x,y') AS `__mysqli_group0`", ' tenantId AS `__mysqli_group1`'],
            array_map(static fn(Expression $field): string => $field->getSql(), $grouped['fields']));
        foreach (['*', ['*']] as $fields) {
            $ordinal = SelectPlanner::countInput($this->state(['fields' => $fields, 'groups' => ['status, 1']]), 'aggregate')['state'];
            self::assertSame($fields, $ordinal['fields']);
            $distinct = SelectPlanner::countInput($this->state(['fields' => $fields, 'queryOptions' => ['DISTINCT']]), 'aggregate')['state'];
            self::assertSame($fields, $distinct['fields']);
        }
        self::assertSame("SELECT COUNT(*) AS `total` FROM (SELECT ? AS id -- comment\n) AS `__mysqli_count`",
            SelectPlanner::countSql('SELECT ? AS id -- comment', '`total`'));
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
