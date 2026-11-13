<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderRegressionTest extends TestCase
{
    private function sql(QueryBuilder $builder): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $builder->getLastPrepareQuery()));
        return preg_replace_callback('/\(\s+|\s+\)/', static fn(array $match): string =>
            str_starts_with($match[0], '(') ? '(' : ')', $sql);
    }

    public function testJoinFiltersPreserveTheScopeOfTheInitialPredicate(): void
    {
        $predicate = 'u.id = o.userId OR u.id = o.ownerId';
        foreach ([$predicate, QueryBuilder::rawExpression($predicate)] as $condition) {
            $builder = (new QueryBuilder())->join('users u', $condition, 'LEFT')
                ->joinWhere('users u', 'u.enabled', true)->where('o.tenantId', 7)->get('orders o');
            self::assertSame('SELECT * FROM orders o LEFT JOIN users u on (u.id = o.userId OR u.id = o.ownerId) AND u.enabled = ? WHERE `o`.`tenantId` = ?', $this->sql($builder));
            self::assertSame([true, 7], $builder->getLastBindParams());
        }
    }

    public function testCountKeepsRollupInGroupByOnly(): void
    {
        $builder = (new QueryBuilder())->where('status', 'paid')
            ->fields(['region', 'city'])->groupBy('region')->groupBy('city with rollup')->count('sales');
        self::assertSame('SELECT COUNT(*) AS `aggregate` FROM (SELECT region, city FROM `sales` WHERE `status` = ? GROUP BY region, city with rollup) AS `__mysqli_count` LIMIT 1', $this->sql($builder));
        self::assertSame(['paid'], $builder->getLastBindParams());
    }

    public function testCountPreservesTheExplicitProjectionOfRollup(): void
    {
        $builder = (new QueryBuilder())->where('status', 'paid')
            ->fields(['region', "COALESCE(city, 'a,b') AS city_group"])
            ->groupBy("ROLLUP(region, COALESCE(city, 'a,b'))")->count('sales');
        self::assertSame("SELECT COUNT(*) AS `aggregate` FROM (SELECT region, COALESCE(city, 'a,b') AS city_group FROM `sales` WHERE `status` = ? GROUP BY ROLLUP(region, COALESCE(city, 'a,b'))) AS `__mysqli_count` LIMIT 1", $this->sql($builder));
        self::assertSame(['paid'], $builder->getLastBindParams());
    }

    public function testUnionRejectsOverallLocksAndPreservesLockedBranches(): void
    {
        $branch = (new QueryBuilder())->forUpdate()->get('archive', null, 'id');
        $union = (new QueryBuilder())->union($branch)->get('orders', null, 'id');
        self::assertSame('SELECT id FROM `orders` UNION (SELECT id FROM `archive` FOR UPDATE)', $this->sql($union));
        foreach (['forUpdate', 'lockInShareMode'] as $lock) {
            try {
                (new QueryBuilder())->union($branch)->{$lock}()->get('orders', null, 'id');
                self::fail('Expected overall UNION lock to fail');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('individual query branches', $error->getMessage());
            }
        }
    }

    public function testRegexSortPatternIsBoundAsAValue(): void
    {
        $pattern = "x' DESC, (SELECT SLEEP(1)) #";
        $builder = (new QueryBuilder())->orderBy('name', 'ASC', $pattern)->get('users');
        self::assertSame('SELECT * FROM `users` ORDER BY name REGEXP ? ASC', $this->sql($builder));
        self::assertSame([$pattern], $builder->getLastBindParams());
        self::assertStringNotContainsString($pattern, $builder->getLastPrepareQuery());
    }

    public function testRegexBindOrderFollowsSqlRatherThanMethodCallOrder(): void
    {
        $builder = (new QueryBuilder())->orderBy('name', 'ASC', "O'Reilly")
            ->orderBy('category', 'DESC', '^A')
            ->where('tenantId', 42)->groupBy('name')->having('COUNT(*)', 2, '>')->get('users');
        self::assertSame(
            'SELECT * FROM `users` WHERE `tenantId` = ? GROUP BY name HAVING COUNT(*) > ? ORDER BY name REGEXP ? ASC, category REGEXP ? DESC',
            $this->sql($builder)
        );
        self::assertSame([42, 2, "O'Reilly", '^A'], $builder->getLastBindParams());
        $builder->get('users');
        self::assertSame('SELECT * FROM `users`', $this->sql($builder));
        self::assertSame([], $builder->getLastBindParams());
    }

    public function testRepeatedRegexSortPreservesLegacyExpressionKeys(): void
    {
        $builder = (new QueryBuilder())->orderBy('name', 'ASC', 'same')
            ->orderBy('name', 'DESC', 'same')->get('users');
        self::assertSame('SELECT * FROM `users` ORDER BY name REGEXP ? DESC', $this->sql($builder));
        self::assertSame(['same'], $builder->getLastBindParams());
        $builder->orderBy('name', 'ASC', 'first')->orderBy('name', 'DESC', 'second')->get('users');
        self::assertSame('SELECT * FROM `users` ORDER BY name REGEXP ? ASC, name REGEXP ? DESC', $this->sql($builder));
        self::assertSame(['first', 'second'], $builder->getLastBindParams());
    }

    public function testUnionOuterSortAndLimitApplyAfterAllBranches(): void
    {
        $branch = (new QueryBuilder())->where('status', 'archived')->get('archive', null, 'id');
        $builder = (new QueryBuilder())->union($branch)->where('status', 'open')
            ->orderByDesc('id')->limit(10)->get('orders', null, 'id');
        self::assertSame(
            'SELECT id FROM `orders` WHERE `status` = ? UNION SELECT id FROM `archive` WHERE `status` = ? ORDER BY id DESC LIMIT 10',
            $this->sql($builder)
        );
        self::assertSame(['open', 'archived'], $builder->getLastBindParams());
    }

    public function testLimitedAndSortedUnionBranchesAreParenthesized(): void
    {
        $limited = (new QueryBuilder())->where('status', 'limited')->orderByDesc('id')->get('archive', 2, 'id');
        $sorted = (new QueryBuilder())->where('status', 'sorted')->orderByAsc('id')->get('history', null, 'id');
        $builder = (new QueryBuilder())->union($limited, true)->union($sorted)
            ->orderByAsc('id')->get('orders', [5, 10], 'id');
        self::assertSame(
            'SELECT id FROM `orders` UNION ALL (SELECT id FROM `archive` WHERE `status` = ? ORDER BY id DESC LIMIT 2) UNION (SELECT id FROM `history` WHERE `status` = ? ORDER BY id ASC) ORDER BY id ASC LIMIT 5, 10',
            $this->sql($builder)
        );
        self::assertSame(['limited', 'sorted'], $builder->getLastBindParams());
    }

    public function testRegexParametersAcrossJoinSubqueryAndUnionStayInSqlOrder(): void
    {
        $sub = QueryBuilder::subQuery()->where('active', 1)->orderBy('name', 'ASC', 'inner-pattern')
            ->get('users', null, 'id');
        $branch = (new QueryBuilder())->where('status', 'archived')->orderBy('id', 'DESC', 'branch-pattern')
            ->get('archive', 2, 'id');
        $builder = (new QueryBuilder())->orderBy('id', 'DESC', 'outer-pattern')
            ->join('tenants t', 't.id = o.tenantId', 'LEFT')->joinWhere('tenants t', 't.enabled', true)
            ->where('o.tenantId', 7)->whereIn('o.userId', $sub)->groupBy('o.id')->having('COUNT(*)', 2, '>')
            ->union($branch)->get('orders o', 10, 'o.id AS id');
        self::assertSame([true, 7, 1, 'inner-pattern', 2, 'archived', 'branch-pattern', 'outer-pattern'], $builder->getLastBindParams());
        self::assertSame(8, substr_count($builder->getLastPrepareQuery(), '?'));
        self::assertStringContainsString('LEFT JOIN tenants t on (t.id = o.tenantId) AND t.enabled = ?', $this->sql($builder));
        self::assertStringContainsString('SELECT id FROM `users` WHERE `active` = ? ORDER BY name REGEXP ? ASC', $this->sql($builder));
        self::assertStringContainsString('UNION (SELECT id FROM `archive` WHERE `status` = ? ORDER BY id REGEXP ? DESC LIMIT 2)', $this->sql($builder));
        self::assertStringEndsWith('ORDER BY id REGEXP ? DESC LIMIT 10', $this->sql($builder));
    }

    public function testNestedUnionRetainsItsLocalSortAndParameterOrder(): void
    {
        $oldest = (new QueryBuilder())->where('type', 'older')->get('older', null, 'id');
        $nested = (new QueryBuilder())->union($oldest, true)->where('type', 'old')
            ->orderBy('id', 'DESC', 'inner')->get('archive', 2, 'id');
        $builder = (new QueryBuilder())->union($nested)->where('type', 'live')
            ->orderBy('id', 'ASC', 'outer')->get('orders', 3, 'id');
        self::assertSame(
            'SELECT id FROM `orders` WHERE `type` = ? UNION (SELECT id FROM `archive` WHERE `type` = ? UNION ALL SELECT id FROM `older` WHERE `type` = ? ORDER BY id REGEXP ? DESC LIMIT 2) ORDER BY id REGEXP ? ASC LIMIT 3',
            $this->sql($builder)
        );
        self::assertSame(['live', 'old', 'older', 'inner', 'outer'], $builder->getLastBindParams());
    }

    public function testRawUnionBranchesAreParenthesizedWithoutLosingBindings(): void
    {
        $raw = (new QueryBuilder())->raw('SELECT ? AS id ORDER BY id DESC LIMIT 1', [4]);
        $builder = (new QueryBuilder())->union($raw)->union('SELECT 5 AS id ORDER BY id DESC LIMIT 1', true)
            ->where('status', 'open')->get('orders', null, 'id');
        self::assertSame(
            'SELECT id FROM `orders` WHERE `status` = ? UNION (SELECT ? AS id ORDER BY id DESC LIMIT 1) UNION ALL (SELECT 5 AS id ORDER BY id DESC LIMIT 1)',
            $this->sql($builder)
        );
        self::assertSame(['open', 4], $builder->getLastBindParams());
    }

    public function testExplicitZeroLimitIsPreservedAndOverridesPendingLimit(): void
    {
        $builder = (new QueryBuilder())->get('orders', 0);
        self::assertSame('SELECT * FROM `orders` LIMIT 0', $this->sql($builder));
        $builder->limit(25)->get('orders', 0);
        self::assertSame('SELECT * FROM `orders` LIMIT 0', $this->sql($builder));
        $builder->get('orders', [0, 0]);
        self::assertSame('SELECT * FROM `orders` LIMIT 0, 0', $this->sql($builder));
        $builder->get('orders');
        self::assertSame('SELECT * FROM `orders`', $this->sql($builder));
    }

    public function testJoinNullConditionsHaveSeparatedOperators(): void
    {
        $builder = (new QueryBuilder())->join('users u', 'u.id = o.userId', 'LEFT')
            ->joinWhere('users u', 'u.deleted_at', null, 'IS')
            ->joinOrWhere('users u', 'u.paid_at', null, 'IS NOT')->where('o.tenantId', 7)->get('orders o');
        self::assertSame(
            'SELECT * FROM orders o LEFT JOIN users u on (u.id = o.userId) AND u.deleted_at IS NULL OR u.paid_at IS NOT NULL WHERE `o`.`tenantId` = ?',
            $this->sql($builder)
        );
        self::assertSame([7], $builder->getLastBindParams());
    }
}
