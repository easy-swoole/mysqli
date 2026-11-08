<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Expression, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class QueryBuilderConditionsTest extends TestCase
{
    private function sql(QueryBuilder $builder): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $builder->getLastPrepareQuery()));
        return preg_replace_callback('/\(\s+|\s+\)/', static fn(array $m): string => str_starts_with($m[0], '(') ? '(' : ')', $sql);
    }

    public function testColumnComparisonsQuoteBothSidesWithoutBindingIdentifiers(): void
    {
        $builder = (new QueryBuilder())->whereColumn('o.userId', 'u.id')
            ->whereColumn('o.created_at', 'u.created_at', '>=', 'OR')->where('o.status', 'open')
            ->get('orders o');
        self::assertSame('SELECT * FROM orders o WHERE `o`.`userId` = `u`.`id` OR `o`.`created_at` >= `u`.`created_at` AND `o`.`status` = ?', $this->sql($builder));
        self::assertSame(['open'], $builder->getLastBindParams());
    }

    public function testOrdinaryConditionsPreserveThreePartAndQuotedIdentifiers(): void
    {
        $builder = (new QueryBuilder())->whereNull('app.users.deleted_at')
            ->where('`app`.`users`.`odd``name`', 'value')->get('app.users');
        self::assertSame('SELECT * FROM `app`.`users` WHERE `app`.`users`.`deleted_at` IS NULL AND `app`.`users`.`odd``name` = ?', $this->sql($builder));
        self::assertSame(['value'], $builder->getLastBindParams());
    }

    public function testHavingGroupsNestAndKeepBindingOrder(): void
    {
        $builder = (new QueryBuilder())->where('tenantId', 7)->groupBy('tenantId')
            ->havingGroup(static function (QueryBuilder $q): void {
                $q->having('COUNT(*)', 3, '>')->orHavingGroup(static function (QueryBuilder $nested): void {
                    $nested->having('SUM(amount)', 100, '>')->having('SUM(refund)', 0);
                });
            })->get('orders', null, 'tenantId');
        self::assertSame('SELECT tenantId FROM `orders` WHERE `tenantId` = ? GROUP BY tenantId HAVING (COUNT(*) > ? OR (SUM(amount) > ? AND SUM(refund) = ?))', $this->sql($builder));
        self::assertSame([7, 3, 100, 0], $builder->getLastBindParams());
        $builder->get('orders');
        self::assertSame('SELECT * FROM `orders`', $this->sql($builder));
    }

    public function testJoinOnGroupsPreserveLegacyFieldTextAndColumnNodes(): void
    {
        $builder = (new QueryBuilder())->join('users u', 'u.id=o.userId', 'LEFT')
            ->joinOn('users u', 'u.tenantId', 'o.tenantId')
            ->joinWhereGroup('users u', static function (QueryBuilder $q): void {
                $q->whereColumn('u.ownerId', 'o.ownerId')->whereGroup(static function (QueryBuilder $nested): void {
                    $nested->where('u.status', 'active')->whereNull('u.deleted_at', 'OR');
                });
            })->where('o.tenantId', 7)->get('orders o');
        self::assertSame('SELECT * FROM orders o LEFT JOIN users u on u.id=o.userId AND `u`.`tenantId` = `o`.`tenantId` AND (`u`.`ownerId` = `o`.`ownerId` AND (u.status = ? OR u.deleted_at IS NULL)) WHERE `o`.`tenantId` = ?', $this->sql($builder));
        self::assertSame(['active', 7], $builder->getLastBindParams());
    }

    public function testJoinOrGroupAndOrColumnComparisonKeepTheirScope(): void
    {
        $builder = (new QueryBuilder())->join('users u', 'u.id=o.userId', 'LEFT')
            ->joinOrOn('users u', 'u.tenantId', 'o.tenantId', '<=>')
            ->joinOrWhereGroup('users u', static fn(QueryBuilder $q) => $q->where('u.public', true)->where('u.enabled', true))
            ->get('orders o');
        self::assertSame('SELECT * FROM orders o LEFT JOIN users u on u.id=o.userId OR `u`.`tenantId` <=> `o`.`tenantId` OR (u.public = ? AND u.enabled = ?)', $this->sql($builder));
        self::assertSame([true, true], $builder->getLastBindParams());
    }

    public function testRawPredicatesAreParenthesizedInsteadOfEscapingTenantScope(): void
    {
        $builder = (new QueryBuilder())->where('tenantId', 7)->whereRaw('a = ? OR b = ?', [1, 2])->get('orders');
        self::assertSame('SELECT * FROM `orders` WHERE `tenantId` = ? AND (a = ? OR b = ?)', $this->sql($builder));
        self::assertSame([7, 1, 2], $builder->getLastBindParams());
    }

    public function testExpressionValuesAndLeftExpressionsBindBeforeComparisonValues(): void
    {
        $builder = (new QueryBuilder())->where('amount', new Expression('COALESCE(?, ?)', [10, 20]), '>')
            ->groupBy('tenantId')->having(new Expression('SUM(IF(status = ?, amount, 0))', ['paid']), 100, '>')
            ->get('orders', null, 'tenantId');
        self::assertSame('SELECT tenantId FROM `orders` WHERE `amount` > COALESCE(?, ?) GROUP BY tenantId HAVING SUM(IF(status = ?, amount, 0)) > ?', $this->sql($builder));
        self::assertSame([10, 20, 'paid', 100], $builder->getLastBindParams());
    }

    public function testExpressionsAcrossSelectJoinWhereHavingUnionAndOrderBindInSqlOrder(): void
    {
        $sub = QueryBuilder::subQuery()->where('active', true)->get('users', null, 'id');
        $branch = (new QueryBuilder())->where('archived', 9)->get('archive', null, [new Expression('? AS id', [8])]);
        $builder = (new QueryBuilder())->join('users u', new Expression('u.id=o.userId AND u.rank = ?', [2]), 'LEFT')
            ->joinWhereGroup('users u', static fn(QueryBuilder $q) => $q->whereIn('u.status', [3, 4]))
            ->whereIn('o.userId', $sub)->whereRaw('o.score > ? OR o.flag = ?', [5, 6])->groupBy('o.id')->havingRaw('COUNT(*) > ?', [7])
            ->union($branch)->orderByRaw('FIELD(id, ?, ?) ASC', [10, 11])
            ->get('orders o', null, [new Expression('? AS id', [1])]);
        self::assertSame([1, 2, 3, 4, true, 5, 6, 7, 8, 9, 10, 11], $builder->getLastBindParams());
        self::assertSame(12, substr_count($builder->getLastPrepareQuery(), '?'));
        self::assertStringContainsString('on (u.id=o.userId AND u.rank = ?)', $this->sql($builder));
        self::assertStringContainsString('AND (o.score > ? OR o.flag = ?)', $this->sql($builder));
        self::assertStringContainsString('HAVING (COUNT(*) > ?)', $this->sql($builder));
        self::assertStringEndsWith('ORDER BY FIELD(id, ?, ?) ASC', $this->sql($builder));
    }

    public function testInvalidColumnOperatorsAndEmptyGroupsDoNotAddConditions(): void
    {
        foreach ([
            static fn(QueryBuilder $q) => $q->whereColumn('a.id', 'b.id', '= 1 OR'),
            static fn(QueryBuilder $q) => $q->whereColumn('a.id', 'b.id', '=', 'OR 1=1'),
            static fn(QueryBuilder $q) => $q->havingGroup(static fn(QueryBuilder $nested) => null),
            static fn(QueryBuilder $q) => $q->joinWhereGroup('users u', static fn(QueryBuilder $nested) => null),
        ] as $invalid) {
            $builder = (new QueryBuilder())->where('tenantId', 7);
            try {
                $invalid($builder);
                self::fail('Expected invalid condition to be rejected');
            } catch (\InvalidArgumentException $error) {
                $builder->get('orders');
                self::assertSame('SELECT * FROM `orders` WHERE `tenantId` = ?', $this->sql($builder));
                self::assertSame([7], $builder->getLastBindParams());
            }
        }
    }

    public function testJoinSubqueryAliasFindsOnGroupsWithTablePrefixes(): void
    {
        $subquery = QueryBuilder::subQuery('u')->selectRaw('? AS id, ? AS tenantId, ? AS status', [1, 7, 'active'])
            ->get('(SELECT 1) seed');
        $builder = (new QueryBuilder())->setPrefix('app_')->join($subquery, 'u.id=o.userId', 'LEFT')
            ->joinOn('u', 'u.tenantId', 'o.tenantId')
            ->joinWhereGroup('u', static fn(QueryBuilder $q) => $q->where('u.status', 'active')->orWhere('u.status', 'staff'))
            ->where('o.tenantId', 7)->get('orders o');
        self::assertStringContainsString('FROM app_orders o LEFT JOIN', $this->sql($builder));
        self::assertStringContainsString('AND `u`.`tenantId` = `o`.`tenantId` AND (u.status = ? OR u.status = ?)', $this->sql($builder));
        self::assertSame([1, 7, 'active', 'active', 'staff', 7], $builder->getLastBindParams());
    }

    public function testUsingTextInsideColumnNamesStillProducesOnJoin(): void
    {
        $builder = (new QueryBuilder())->join('users u', 'u.using_flag=o.using_flag', 'LEFT')->get('orders o');
        self::assertStringContainsString('LEFT JOIN users u on u.using_flag=o.using_flag', $this->sql($builder));
        $builder->join('users u', ' using (id)', 'LEFT')->get('orders o');
        self::assertStringContainsString('LEFT JOIN users u using (id)', $this->sql($builder));
    }

    public function testUsingJoinsRejectAdditionalOnConditions(): void
    {
        $builder = (new QueryBuilder())->join('users u', 'USING (id)', 'LEFT')
            ->joinWhereGroup('users u', static fn(QueryBuilder $q) => $q->where('u.active', true));
        $this->expectException(\InvalidArgumentException::class);
        $builder->get('orders o');
    }
}
