<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Expression, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class QueryBuilderAggregateTest extends TestCase
{
    public function testFromSubDoesNotRepairInvalidSourceSql(): void
    {
        $subquery = (new QueryBuilder())->raw('SELECT ? AS id,', [1]);
        $builder = (new QueryBuilder())->getFromSub($subquery, 'stats');
        self::assertSame('SELECT  * FROM (SELECT ? AS id,) AS `stats`', $builder->getLastPrepareQuery());
        self::assertSame([1], $builder->getLastBindParams());
    }

    private function sql(QueryBuilder $builder): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $builder->getLastPrepareQuery()));
        return preg_replace_callback('/\(\s+|\s+\)/', static fn(array $match): string => str_starts_with($match[0], '(') ? '(' : ')', $sql);
    }

    public function testFromSubBindsBetweenOuterSelectAndJoinWhereHavingUnionOrder(): void
    {
        $source = (new QueryBuilder())->selectRaw('? AS id, score', [2])->where('state', 'open')->get('orders');
        $branch = (new QueryBuilder())->selectRaw('? AS id', [7])->where('archived', 8)->get('archive');
        $builder = (new QueryBuilder())->fromSub($source, 's')
            ->join('users u', new Expression('u.id=s.id AND u.flag=?', [3]), 'LEFT')->joinWhere('users u', 'u.enabled', true)
            ->where('s.score', 5, '>')->groupBy('s.id')->havingRaw('COUNT(*) > ?', [6])
            ->union($branch)->orderByRaw('FIELD(id, ?) ASC', [9])->get(null, null, [new Expression('? AS id', [1])]);
        self::assertSame([1, 2, 'open', 3, true, 5, 6, 7, 8, 9], $builder->getLastBindParams());
        self::assertStringContainsString('FROM (SELECT ? AS id, score FROM `orders` WHERE `state` = ?) AS `s` LEFT JOIN', $this->sql($builder));
        self::assertSame(10, substr_count($builder->getLastPrepareQuery(), '?'));
    }

    public function testFromSubStoresAnIndependentSqlAndParameterSnapshot(): void
    {
        $value = 7;
        $source = (new QueryBuilder())->raw('SELECT ? AS id', [&$value]);
        $builder = (new QueryBuilder())->fromSub(subquery: $source, alias: 's');
        $value = 99;
        $source->raw('SELECT ? AS changed', [100]);
        $builder->where('s.id', 8)->get(null);
        self::assertSame('SELECT * FROM (SELECT ? AS id) AS `s` WHERE `s`.`id` = ?', $this->sql($builder));
        self::assertSame([7, 8], $builder->getLastBindParams());
    }

    public function testFromSubQuotesTheAliasWithoutApplyingTheOuterTablePrefix(): void
    {
        $source = (new QueryBuilder())->setPrefix('inner_')->where('id', 1)->get('orders');
        $builder = (new QueryBuilder())->setPrefix('outer_')->fromSub($source, 'odd`.alias')->get(null);
        self::assertSame('SELECT * FROM (SELECT * FROM `inner_orders` WHERE `id` = ?) AS `odd``.alias`', $this->sql($builder));
        self::assertSame([1], $builder->getLastBindParams());
        $builder->get('orders');
        self::assertSame('SELECT * FROM `outer_orders`', $this->sql($builder));
    }

    public function testGetFromSubConvenienceHonorsExplicitColumnsAndOuterPagination(): void
    {
        $source = QueryBuilder::subQuery('ignored')->where('active', true)->get('users', 3, 'id');
        $builder = (new QueryBuilder())->fields('discarded')->limit(99)->where('s.id', 1, '>')
            ->getFromSub(subquery: $source, alias: 's', numRows: [5, 10], columns: 's.id');
        self::assertSame('SELECT s.id FROM (SELECT id FROM `users` WHERE `active` = ? LIMIT 3) AS `s` WHERE `s`.`id` > ? LIMIT 5, 10', $this->sql($builder));
        self::assertSame([true, 1], $builder->getLastBindParams());
    }

    public function testFromSubCanBeReplacedAndAlsoUsedByExistingSelectConveniences(): void
    {
        $first = (new QueryBuilder())->raw('SELECT ? AS id', [1]);
        $second = (new QueryBuilder())->raw('SELECT ? AS id', [2]);
        $builder = (new QueryBuilder())->fromSub($first, 'a')->fromSub($second, 'b')->getOne(null, 'b.id');
        self::assertSame('SELECT b.id FROM (SELECT ? AS id) AS `b` LIMIT 1', $this->sql($builder));
        self::assertSame([2], $builder->getLastBindParams());
        $builder->fromSub($first, 'a')->getScalar(null, new Expression('COALESCE(a.id, ?)', [3]));
        self::assertSame([3, 1], $builder->getLastBindParams());
        $builder->fromSub($first, 'a')->reset()->get('users');
        self::assertSame('SELECT * FROM `users`', $this->sql($builder));
    }

    public function testInvalidFromSubDoesNotReplaceAnExistingSource(): void
    {
        $source = (new QueryBuilder())->raw('SELECT ? AS id', [7]);
        $invalid = [
            fn($q) => $q->fromSub(new QueryBuilder(), 's'),
            fn($q) => $q->fromSub((new QueryBuilder())->raw('UPDATE users SET id=?', [1]), 's'),
            fn($q) => $q->fromSub($source, ''),
            fn($q) => $q->fromSub($source, '   '),
            fn($q) => $q->fromSub($source, "bad\0alias"),
        ];
        foreach ($invalid as $apply) {
            $builder = (new QueryBuilder())->fromSub($source, 'original')->where('id', 8);
            $error = null;
            try { $apply($builder); } catch (\InvalidArgumentException $caught) { $error = $caught; }
            self::assertNotNull($error);
            $builder->get(null);
            self::assertSame('SELECT * FROM (SELECT ? AS id) AS `original` WHERE `id` = ?', $this->sql($builder));
            self::assertSame([7, 8], $builder->getLastBindParams());
        }
    }

    public function testFromSubRejectsAnOuterTableAndOuterIndexHintsWithoutPublishingPartialSql(): void
    {
        $source = (new QueryBuilder())->raw('SELECT ? AS id', [7]);
        $builder = (new QueryBuilder())->raw('SELECT ? AS previous', [1]);
        $builder->fromSub($source, 's')->where('s.id', 8);
        try { $builder->get('users'); self::fail('Expected an ambiguous FROM to be rejected'); }
        catch (\InvalidArgumentException $error) { }
        self::assertSame('SELECT ? AS previous', $builder->getLastPrepareQuery());
        self::assertSame([1], $builder->getLastBindParams());
        $builder->get(null);
        self::assertSame([7, 8], $builder->getLastBindParams());
        foreach (['forceIndex', 'useIndex', 'ignoreIndex'] as $method) {
            $builder->fromSub($source, 's')->{$method}('idx_id');
            try { $builder->get(null); self::fail('Expected derived-table index hints to be rejected'); }
            catch (\InvalidArgumentException $error) { }
            $builder->{$method}([])->get(null);
            self::assertSame('SELECT * FROM (SELECT ? AS id) AS `s`', $this->sql($builder));
            self::assertSame([7], $builder->getLastBindParams());
        }
    }

    public function testIndexHintsInsideTheFromSourceArePreserved(): void
    {
        $source = (new QueryBuilder())->forceIndex('idx_status')->where('status', 'open')->get('orders');
        $builder = (new QueryBuilder())->fromSub($source, 's')->get(null);
        self::assertStringContainsString('FROM (SELECT * FROM `orders` FORCE INDEX (`idx_status`) WHERE `status` = ?) AS `s`', $this->sql($builder));
        self::assertSame(['open'], $builder->getLastBindParams());
    }

    public function testCountDropsOuterSortingPaginationLockFlagsAndTheirBindings(): void
    {
        $builder = (new QueryBuilder())->where('tenantId', 7)->orderByRaw('FIELD(id, ?) DESC', [99])
            ->limit(0, 10)->skipLocked()->withTotalCount()->count(tableName: 'orders');
        self::assertStringStartsWith('SELECT COUNT(*) AS `aggregate` FROM (SELECT', $this->sql($builder));
        self::assertStringContainsString('WHERE `tenantId` = ?', $this->sql($builder));
        foreach (['ORDER BY', 'FOR UPDATE', 'SKIP LOCKED', 'SQL_CALC_FOUND_ROWS'] as $removed) {
            self::assertStringNotContainsString($removed, $builder->getLastPrepareQuery());
        }
        self::assertSame(1, substr_count($builder->getLastPrepareQuery(), 'LIMIT'));
        self::assertStringEndsWith('AS `__mysqli_count` LIMIT 1', $builder->getLastPrepareQuery());
        self::assertSame([7], $builder->getLastBindParams());
        self::assertNull($builder->getLastTransactionOp());
        self::assertSame([], $builder->getLastQueryOptions());
        $builder->get('orders');
        self::assertSame('SELECT * FROM `orders`', $this->sql($builder));
        self::assertSame([], $builder->getLastBindParams());
    }

    public function testCountPreservesDistinctAndTheSelectedDataset(): void
    {
        $builder = (new QueryBuilder())->distinct()->fields(['status'])->where('tenantId', 7)->count('orders', 'total');
        self::assertSame('SELECT COUNT(*) AS `total` FROM (SELECT DISTINCT status FROM `orders` WHERE `tenantId` = ?) AS `__mysqli_count` LIMIT 1', $this->sql($builder));
        self::assertSame([7], $builder->getLastBindParams());
    }

    public function testCountOfAnOrdinaryJoinDoesNotExposeDuplicateStarColumns(): void
    {
        foreach ([false, true] as $explicitStar) {
            $builder = (new QueryBuilder())->join('users u', 'u.id=o.userId', 'LEFT')
                ->joinWhere('users u', 'u.enabled', true)->where('o.tenantId', 7);
            if ($explicitStar) { $builder->fields('*'); }
            $builder->count('orders o');
            self::assertMatchesRegularExpression('/FROM \(SELECT 1(?: AS `[^`]+`)? FROM orders o LEFT JOIN users u/', $this->sql($builder));
            self::assertSame([true, 7], $builder->getLastBindParams());
        }
    }

    public function testCountPreservesGroupingHavingAndSelectExpressionParameters(): void
    {
        $builder = (new QueryBuilder())->fields(['userId', new Expression('SUM(score + ?) AS total', [1])])
            ->where('tenantId', 7)->groupBy('userId')->havingGroup(static fn(QueryBuilder $q) => $q->having('total', 100, '>')->orHaving('COUNT(*)', 5, '>'))
            ->orderByRaw('FIELD(userId, ?) ASC', [999])->limit(10)->lockInShareMode()->count('orders', 'total`count');
        self::assertStringStartsWith('SELECT COUNT(*) AS `total``count` FROM (SELECT userId, SUM(score + ?) AS total FROM `orders`', $this->sql($builder));
        self::assertStringContainsString('GROUP BY userId HAVING (`total` > ? OR COUNT(*) > ?)', $this->sql($builder));
        self::assertSame([1, 7, 100, 5], $builder->getLastBindParams());
        self::assertStringNotContainsString('LOCK IN SHARE MODE', $builder->getLastPrepareQuery());
    }

    public function testCountWithHavingPreservesImplicitProjection(): void
    {
        $builder = (new QueryBuilder())->where('tenantId', 7)->groupBy('status')->having('COUNT(*)', 2, '>')->count('orders');
        self::assertMatchesRegularExpression('/FROM \(SELECT \* FROM `orders` WHERE `tenantId` = \? GROUP BY status HAVING COUNT\(\*\) > \?\)/', $this->sql($builder));
        self::assertSame([7, 2], $builder->getLastBindParams());
    }

    public function testCountAssignsDistinctAliasesToCompoundGroupingExpressions(): void
    {
        $builder = (new QueryBuilder())->join('users b', 'b.id=a.userId')
            ->groupBy("COALESCE(a.status, 'x,y'), b.id")->count('orders a');
        $sql = $this->sql($builder);
        self::assertMatchesRegularExpression('/SELECT COALESCE\(a\.status, \'x,y\'\) AS `[^`]+`, b\.id AS `[^`]+` FROM/', $sql);
        preg_match('/FROM \(SELECT (.*?) FROM orders a/', $sql, $selection);
        preg_match_all('/ AS (`[^`]+`)/', $selection[1], $aliases);
        self::assertCount(2, array_unique($aliases[1]));
        self::assertSame([], $builder->getLastBindParams());
    }

    public function testCountPreservesStarProjectionWhenGroupByUsesColumnPositions(): void
    {
        foreach ([false, true] as $explicitStar) {
            foreach (['1', 'status, 1'] as $group) {
                $builder = (new QueryBuilder())->where('tenantId', 7)->groupBy($group);
                if ($explicitStar) { $builder->fields('*'); }
                $builder->count('orders');
                self::assertStringContainsString('FROM (SELECT * FROM `orders` WHERE `tenantId` = ? GROUP BY ' . $group . ')', $this->sql($builder));
                self::assertStringNotContainsString('__mysqli_group', $builder->getLastPrepareQuery());
                self::assertSame([7], $builder->getLastBindParams());
            }
        }
    }

    public function testCountKeepsUnionBranchPaginationAndDropsOnlyTheOuterPagination(): void
    {
        $branch = (new QueryBuilder())->selectRaw('? AS id', [20])->where('active', true)->orderByRaw('FIELD(id, ?) DESC', [21])->get('archive', 2);
        $builder = (new QueryBuilder())->fields('id')->where('tenantId', 7)->union($branch, true)
            ->orderByRaw('FIELD(id, ?) ASC', [22])->limit(10)->count('orders');
        self::assertStringContainsString('UNION ALL (SELECT ? AS id FROM `archive` WHERE `active` = ? ORDER BY FIELD(id, ?) DESC LIMIT 2)', $this->sql($builder));
        self::assertStringNotContainsString('LIMIT 10', $builder->getLastPrepareQuery());
        self::assertStringNotContainsString('FIELD(id, ?) ASC', $builder->getLastPrepareQuery());
        self::assertSame([7, 20, true, 21], $builder->getLastBindParams());
    }

    public function testCountOfAFromSubPreservesTheSourcesOwnLimitAndSnapshot(): void
    {
        $source = (new QueryBuilder())->where('active', true)->orderByDesc('id')->get('users', 3, 'id');
        $builder = (new QueryBuilder())->fromSub($source, 's')->where('s.id', 7, '>')->limit(1)->count(null);
        self::assertStringContainsString('FROM (SELECT id FROM `users` WHERE `active` = ? ORDER BY id DESC LIMIT 3) AS `s` WHERE `s`.`id` > ?', $this->sql($builder));
        self::assertSame([true, 7], $builder->getLastBindParams());
        self::assertSame(2, substr_count($builder->getLastPrepareQuery(), 'LIMIT'));
        self::assertStringEndsWith('AS `__mysqli_count` LIMIT 1', $builder->getLastPrepareQuery());
    }

    public function testAggregateHelpersQuoteIdentifiersAndMatchTheGenericFunctions(): void
    {
        foreach (['sum' => 'SUM', 'avg' => 'AVG', 'min' => 'MIN', 'max' => 'MAX'] as $method => $function) {
            $builder = (new QueryBuilder())->where('tenantId', 7)->{$method}('orders o', 'o.amount', 'odd`alias');
            $generic = (new QueryBuilder())->where('tenantId', 7)->aggregate('orders o', strtolower($function), 'o.amount', 'odd`alias');
            self::assertSame($generic->getLastPrepareQuery(), $builder->getLastPrepareQuery());
            self::assertSame('SELECT ' . $function . '(`o`.`amount`) AS `odd``alias` FROM orders o WHERE `tenantId` = ?', $this->sql($builder));
            self::assertSame([7], $builder->getLastBindParams());
        }
        $builder = (new QueryBuilder())->aggregate(tableName: 'orders', function: ' count ', column: '*', alias: 'rows');
        self::assertSame('SELECT COUNT(*) AS `rows` FROM `orders`', $this->sql($builder));
    }

    public function testAggregatesAppendToSelectedGroupFieldsAndBindExpressionsBeforePredicates(): void
    {
        $builder = (new QueryBuilder())->fields(['tenantId', new Expression('? AS marker', [1])])->where('status', 'paid')
            ->groupBy('tenantId')->having('total', 100, '>')->orderByRaw('FIELD(tenantId, ?) ASC', [9])
            ->sum('orders', new Expression('amount + ?', [5]), 'total');
        self::assertSame('SELECT tenantId, ? AS marker, SUM(amount + ?) AS `total` FROM `orders` WHERE `status` = ? GROUP BY tenantId HAVING `total` > ? ORDER BY FIELD(tenantId, ?) ASC', $this->sql($builder));
        self::assertSame([1, 5, 'paid', 100, 9], $builder->getLastBindParams());
    }

    public function testAnEntireUnionCanBeAggregatedThroughAFromSub(): void
    {
        $branch = (new QueryBuilder())->where('status', 'old')->get('archive', null, 'amount');
        $source = (new QueryBuilder())->where('status', 'open')->union($branch, true)->get('orders', null, 'amount');
        $builder = (new QueryBuilder())->fromSub($source, 'u')->sum(null, 'u.amount', 'total');
        self::assertSame('SELECT SUM(`u`.`amount`) AS `total` FROM (SELECT amount FROM `orders` WHERE `status` = ? UNION ALL SELECT amount FROM `archive` WHERE `status` = ?) AS `u`', $this->sql($builder));
        self::assertSame(['open', 'old'], $builder->getLastBindParams());
    }

    public function testInvalidAggregateOptionsLeaveTheOriginalQueryStateUsable(): void
    {
        $invalid = [
            fn($q) => $q->aggregate('orders', 'MEDIAN', 'amount'),
            fn($q) => $q->aggregate('orders', 'SUM) FROM users; --', 'amount'),
            fn($q) => $q->sum('orders', 'amount', ''),
            fn($q) => $q->sum('orders', 'amount', "bad\0alias"),
            fn($q) => $q->count('orders', ''),
        ];
        foreach ($invalid as $apply) {
            $builder = (new QueryBuilder())->fields('id')->where('tenantId', 7);
            $error = null;
            try { $apply($builder); } catch (\InvalidArgumentException $caught) { $error = $caught; }
            self::assertNotNull($error);
            $builder->get('orders');
            self::assertSame('SELECT id FROM `orders` WHERE `tenantId` = ?', $this->sql($builder));
            self::assertSame([7], $builder->getLastBindParams());
        }
        $branch = (new QueryBuilder())->get('archive', null, 'id');
        $builder = (new QueryBuilder())->fields('id')->where('tenantId', 7)->union($branch);
        try { $builder->sum('orders', 'amount'); self::fail('Direct whole-UNION aggregate must be explicit'); }
        catch (\InvalidArgumentException $error) { }
        $builder->get('orders');
        self::assertSame('SELECT id FROM `orders` WHERE `tenantId` = ? UNION SELECT id FROM `archive`', $this->sql($builder));
        self::assertSame([7], $builder->getLastBindParams());
    }

    public function testAggregateCompletionClearsFieldsFromSourceConditionsAndParameters(): void
    {
        $source = (new QueryBuilder())->where('active', true)->get('orders', null, 'amount');
        $builder = (new QueryBuilder())->fromSub($source, 's')->where('s.amount', 2, '>');
        self::assertSame($builder, $builder->avg(null, 's.amount'));
        self::assertSame([true, 2], $builder->getLastBindParams());
        $builder->get('users');
        self::assertSame('SELECT * FROM `users`', $this->sql($builder));
        self::assertSame([], $builder->getLastBindParams());
        self::assertNull($builder->getLastTransactionOp());
    }
}
