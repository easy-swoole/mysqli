<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{ConditionValue, QueryBuilder};
use EasySwoole\Mysqli\QueryBuilder\{
    ComparisonOperator,
    ConditionConjunction,
    JoinType,
    LockWaitPolicy,
    SortDirection,
    TableLockMode
};
use PHPUnit\Framework\TestCase;

final class QueryBuilderEnumTest extends TestCase
{
    /** 精确比较公开构建结果，包括空白和事务标记。 */
    private function buildResult(QueryBuilder $builder): array
    {
        return [$builder->getLastPrepareQuery(), $builder->getLastBindParams(),
            $builder->getLastTransactionOp(), $builder->getLastQueryOptions()];
    }

    private function conditionBuilder(): QueryBuilder
    {
        return (new QueryBuilder())->selectRaw('? AS token', [9])
            ->join('users u', 'u.id=o.userId', 'LEFT')->where('o.tenantId', 7)
            ->groupBy('o.id')->having('COUNT(*)', 0, '>');
    }

    private function subquery(): QueryBuilder
    {
        return QueryBuilder::subQuery()->where('active', true)->get('users', null, 'id');
    }

    private function assertRejectedWithoutMutation(QueryBuilder $builder, callable $invalid, callable $finish, QueryBuilder $expected): void
    {
        $error = null;
        try {
            $invalid($builder);
        } catch (\Exception $caught) {
            $error = $caught;
        }
        self::assertNotNull($error, 'The invalid finite option must be rejected');
        $finish($builder);
        self::assertSame($this->buildResult($expected), $this->buildResult($builder));
    }

    public function testSortDirectionCasesMatchStringsAndPreserveCustomSortBindings(): void
    {
        foreach ([[SortDirection::Asc, 'ASC'], [SortDirection::Desc, 'DESC']] as [$enum, $string]) {
            $actual = (new QueryBuilder())->where('tenantId', 7)->orderBy('name', $enum, '^A')->get('users');
            $expected = (new QueryBuilder())->where('tenantId', 7)->orderBy('name', $string, '^A')->get('users');
            self::assertSame($this->buildResult($expected), $this->buildResult($actual));
            self::assertSame([7, '^A'], $actual->getLastBindParams());
            self::assertStringContainsString('name REGEXP ? ' . $string, $actual->getLastPrepareQuery());
        }
        $actual = (new QueryBuilder())->orderBy('id', " aSc\t")->get('users');
        self::assertSame($this->buildResult((new QueryBuilder())->orderBy('id', SortDirection::Asc)->get('users')), $this->buildResult($actual));
        self::assertSame($this->buildResult((new QueryBuilder())->orderBy('id')->get('users')),
            $this->buildResult((new QueryBuilder())->orderBy('id', SortDirection::Desc)->get('users')));
    }

    public function testEveryJoinTypeMatchesTheLegacyStringIncludingTheDefault(): void
    {
        $cases = [[JoinType::Default, ''], [JoinType::Inner, 'INNER'], [JoinType::Left, 'LEFT'],
            [JoinType::Right, 'RIGHT'], [JoinType::LeftOuter, 'LEFT OUTER'],
            [JoinType::RightOuter, 'RIGHT OUTER'], [JoinType::Natural, 'NATURAL']];
        foreach ($cases as [$enum, $string]) {
            $condition = $enum === JoinType::Natural ? '' : 'u.id=o.userId';
            $actual = (new QueryBuilder())->join('users u', $condition, $enum)->where('o.id', 7)->get('orders o');
            $expected = (new QueryBuilder())->join('users u', $condition, $string)->where('o.id', 7)->get('orders o');
            self::assertSame($this->buildResult($expected), $this->buildResult($actual));
        }
        self::assertSame($this->buildResult((new QueryBuilder())->join('users u', 'u.id=o.userId')->get('orders o')),
            $this->buildResult((new QueryBuilder())->join('users u', 'u.id=o.userId', JoinType::Default)->get('orders o')));
        self::assertSame($this->buildResult((new QueryBuilder())->join('users u', 'u.id=o.userId', JoinType::LeftOuter)->get('orders o')),
            $this->buildResult((new QueryBuilder())->join('users u', 'u.id=o.userId', ' left outer ')->get('orders o')));
    }

    public function testStandaloneOuterJoinIsRejected(): void
    {
        $this->expectException(\EasySwoole\Mysqli\Exception\Exception::class);
        $this->expectExceptionMessage('Wrong JOIN type: OUTER');
        (new QueryBuilder())->join('users u', 'u.id=o.userId', ' outer ');
    }

    public function testNaturalJoinDoesNotGenerateOnAndRejectsExplicitConditions(): void
    {
        $builder = (new QueryBuilder())->join('users u', '', JoinType::Natural)->get('orders o');
        self::assertSame('SELECT  * FROM orders o NATURAL JOIN users u', $builder->getLastPrepareQuery());
        foreach ([
            static fn() => (new QueryBuilder())->join('users u', 'u.id=o.id', JoinType::Natural),
            static fn() => (new QueryBuilder())->join('users u', 'USING(id)', 'NATURAL'),
            static fn() => (new QueryBuilder())->join('users u', '', JoinType::Natural)->joinWhere('users u', 'u.active', true),
            static fn() => (new QueryBuilder())->join('users u', '', JoinType::Natural)->joinOn('users u', 'u.id', 'o.id'),
        ] as $invalid) {
            try {
                $invalid()->get('orders o');
                self::fail('NATURAL JOIN cannot have ON/USING conditions');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('NATURAL', $error->getMessage());
            }
        }
    }

    public function testConjunctionEnumsWorkAtEveryPublicConditionEntryPoint(): void
    {
        $cases = [
            'where' => fn($q, $c) => $q->where('o.amount', 1, '=', $c),
            'whereIn' => fn($q, $c) => $q->whereIn('o.status', [1, 2], $c),
            'whereIn empty' => fn($q, $c) => $q->whereIn('o.status', [], $c),
            'whereNotIn' => fn($q, $c) => $q->whereNotIn('o.status', [1, 2], $c),
            'whereNotIn empty' => fn($q, $c) => $q->whereNotIn('o.status', [], $c),
            'whereIn subquery' => fn($q, $c) => $q->whereIn('o.userId', $this->subquery(), $c),
            'whereNotIn subquery' => fn($q, $c) => $q->whereNotIn('o.userId', $this->subquery(), $c),
            'whereNull' => fn($q, $c) => $q->whereNull('o.deleted_at', $c),
            'whereNotNull' => fn($q, $c) => $q->whereNotNull('o.paid_at', $c),
            'whereBetween' => fn($q, $c) => $q->whereBetween('o.amount', [1, 2], $c),
            'whereLike' => fn($q, $c) => $q->whereLike('o.name', '%value%', $c),
            'whereExists' => fn($q, $c) => $q->whereExists($this->subquery(), $c),
            'whereNotExists' => fn($q, $c) => $q->whereNotExists($this->subquery(), $c),
            'whereRaw' => fn($q, $c) => $q->whereRaw('o.a = ? OR o.b = ?', [1, 2], $c),
            'whereColumn' => fn($q, $c) => $q->whereColumn('o.a', 'o.b', '=', $c),
            'having' => fn($q, $c) => $q->having('SUM(amount)', 1, '>', $c),
            'havingRaw' => fn($q, $c) => $q->havingRaw('SUM(amount) > ?', [1], $c),
            'havingColumn' => fn($q, $c) => $q->havingColumn('paid', 'total', '=', $c),
            'joinWhere' => fn($q, $c) => $q->joinWhere('users u', 'u.active', true, '=', $c),
            // 保留原有 OR 便捷方法接受但忽略 cond 参数的行为。
            'joinOrWhere' => fn($q, $c) => $q->joinOrWhere('users u', 'u.active', true, '=', $c),
            'joinOn' => fn($q, $c) => $q->joinOn('users u', 'u.tenantId', 'o.tenantId', '=', $c),
        ];
        foreach ($cases as $name => $apply) {
            foreach ([[ConditionConjunction::And, 'AND'], [ConditionConjunction::Or, 'OR']] as [$enum, $string]) {
                $actual = $this->conditionBuilder();
                $expected = $this->conditionBuilder();
                $apply($actual, $enum);
                $apply($expected, $string);
                $actual->get('orders o');
                $expected->get('orders o');
                self::assertSame($this->buildResult($expected), $this->buildResult($actual), $name . ': ' . $string);
                if ($string === 'OR') {
                    self::assertStringContainsString(' OR ', $actual->getLastPrepareQuery(), $name);
                }
            }
        }
    }

    public function testConjunctionEnumsInsideGroupsPreserveScopeAndBindingOrder(): void
    {
        $build = function ($and, $or): QueryBuilder {
            return (new QueryBuilder())->join('users u', 'u.id=o.userId', JoinType::Left)
                ->joinWhereGroup('users u', static fn(QueryBuilder $q) => $q->where('u.active', true, '=', $and)->whereNull('u.deleted_at', $or))
                ->whereGroup(static fn(QueryBuilder $q) => $q->whereIn('o.status', [1, 2], $and)->whereLike('o.name', '%x%', $or))
                ->groupBy('o.id')->havingGroup(static fn(QueryBuilder $q) => $q->having('COUNT(*)', 1, '>', $and)->havingRaw('SUM(amount) > ?', [2], $or))
                ->get('orders o');
        };
        $actual = $build(ConditionConjunction::And, ConditionConjunction::Or);
        self::assertSame($this->buildResult($build('AND', 'OR')), $this->buildResult($actual));
        self::assertSame([true, 1, 2, '%x%', 1, 2], $actual->getLastBindParams());
    }

    public function testAllComparisonEnumsMatchStringsInValueAndColumnPredicates(): void
    {
        $operators = [[ComparisonOperator::Equal, '='], [ComparisonOperator::NullSafeEqual, '<=>'],
            [ComparisonOperator::NotEqual, '<>'], [ComparisonOperator::NotEqualAlt, '!='],
            [ComparisonOperator::GreaterThan, '>'], [ComparisonOperator::LessThan, '<'],
            [ComparisonOperator::GreaterThanOrEqual, '>='], [ComparisonOperator::LessThanOrEqual, '<=']];
        $cases = [
            'where' => fn($q, $op) => $q->where('o.amount', 11, $op),
            'orWhere' => fn($q, $op) => $q->orWhere('o.amount', 11, $op),
            'having' => fn($q, $op) => $q->having('SUM(amount)', 11, $op),
            'orHaving' => fn($q, $op) => $q->orHaving('SUM(amount)', 11, $op),
            'joinWhere' => fn($q, $op) => $q->joinWhere('users u', 'u.level', 11, $op),
            'joinOrWhere' => fn($q, $op) => $q->joinOrWhere('users u', 'u.level', 11, $op),
            'whereColumn' => fn($q, $op) => $q->whereColumn('o.a', 'o.b', $op),
            'orWhereColumn' => fn($q, $op) => $q->orWhereColumn('o.a', 'o.b', $op),
            'havingColumn' => fn($q, $op) => $q->havingColumn('paid', 'total', $op),
            'orHavingColumn' => fn($q, $op) => $q->orHavingColumn('paid', 'total', $op),
            'joinOn' => fn($q, $op) => $q->joinOn('users u', 'u.tenantId', 'o.tenantId', $op),
            'joinOrOn' => fn($q, $op) => $q->joinOrOn('users u', 'u.tenantId', 'o.tenantId', $op),
        ];
        foreach ($cases as $name => $apply) {
            foreach ($operators as [$enum, $string]) {
                $actual = $this->conditionBuilder();
                $expected = $this->conditionBuilder();
                $apply($actual, $enum);
                $apply($expected, $string);
                self::assertSame($this->buildResult($expected->get('orders o')), $this->buildResult($actual->get('orders o')), $name . ': ' . $string);
            }
        }
    }

    public function testHavingComparisonAndListOperatorsUseTheExplicitArgument(): void
    {
        $builder = (new QueryBuilder())->groupBy('userId')->having('SUM(amount)', 100, ComparisonOperator::GreaterThanOrEqual)
            ->orHaving('COUNT(*)', [2, 3], ' iN ')->get('orders', null, 'userId');
        self::assertSame([100, 2, 3], $builder->getLastBindParams());
        self::assertStringContainsString('SUM(amount) >= ?', $builder->getLastPrepareQuery());
        self::assertStringContainsString('COUNT(*)  iN  ( ?, ? )', $builder->getLastPrepareQuery());
    }

    public function testOrdinaryConditionStringsKeepTheirOriginalCaseWhitespaceAndExtensions(): void
    {
        $builder = (new QueryBuilder())->join('users u', 'u.id=o.userId', 'LEFT')
            ->joinWhere('users u', 'u.name', 'a', 'rEgExP', ' xOr ')
            ->where('o.id', 1)->where('o.name', 'b', 'sOuNdS LiKe', ' oR ')
            ->whereLike('o.name', '%x%', ' xOr ')->groupBy('o.id')
            ->having('COUNT(*)', 0, '>')->having('SUM(amount)', 5, ' > ', ' aNd ')->get('orders o');
        $sql = $builder->getLastPrepareQuery();
        foreach ([' xOr ', ' oR ', ' aNd ', 'rEgExP', 'sOuNdS LiKe', ' > '] as $legacyText) {
            self::assertStringContainsString($legacyText, $sql);
        }
        self::assertSame(['a', 1, 'b', '%x%', 0, 5], $builder->getLastBindParams());
        $builder->where('id', [1, 2], 'not in')->where('amount', [3, 4], 'between')->where('deleted_at', null, 'IS NOT')->get('orders');
        self::assertSame([1, 2, 3, 4], $builder->getLastBindParams());
        self::assertStringContainsString('not in', $builder->getLastPrepareQuery());
        self::assertStringContainsString('between', $builder->getLastPrepareQuery());
        self::assertStringContainsString('IS NOT NULL', $builder->getLastPrepareQuery());
    }

    public function testStrictRawAndColumnStringOptionsStillNormalizeCaseAndWhitespace(): void
    {
        $build = static function ($and, $or, $operator): QueryBuilder {
            return (new QueryBuilder())->join('users u', 'u.id=o.userId', 'LEFT')
                ->joinOn('users u', 'u.tenantId', 'o.tenantId', $operator, $and)
                ->where('o.id', 1)->whereRaw('o.active = ?', [true], $or)
                ->whereColumn('o.amount', 'o.total', $operator, $and)
                ->having('COUNT(*)', 0, '>')->havingRaw('SUM(amount) > ?', [5], $or)
                ->havingColumn('paid', 'total', $operator, $and)->get('orders o');
        };
        self::assertSame($this->buildResult($build(ConditionConjunction::And, ConditionConjunction::Or, ComparisonOperator::GreaterThanOrEqual)),
            $this->buildResult($build(' aNd ', " oR\t", ' >= ')));
    }

    public function testRawNullBooleanAndLiteralSentinelValuesKeepTheirMeaning(): void
    {
        $build = static function ($operator, $cond): QueryBuilder {
            return (new QueryBuilder())->where('a.id = b.id', ConditionValue::Raw, $operator, $cond)
                ->where('tenantId', 'DBNULL', $operator, $cond)->where('nullable', null, $operator, $cond)
                ->where('enabled', true, $operator, $cond)->where('hidden', false, $operator, $cond)->get('users');
        };
        $actual = $build(ComparisonOperator::Equal, ConditionConjunction::And);
        self::assertSame($this->buildResult($build('=', 'AND')), $this->buildResult($actual));
        self::assertSame(['DBNULL', true, false], $actual->getLastBindParams());
        self::assertStringContainsString('a.id = b.id', $actual->getLastPrepareQuery());
        self::assertStringContainsString('`nullable` = NULL', $actual->getLastPrepareQuery());
    }

    public function testInvalidFiniteOptionsDoNotPartlyChangeExistingState(): void
    {
        $this->assertRejectedWithoutMutation((new QueryBuilder())->orderBy('id', SortDirection::Asc),
            static fn($q) => $q->orderBy('id', 'SIDEWAYS'), static fn($q) => $q->get('users'),
            (new QueryBuilder())->orderBy('id', 'ASC')->get('users'));
        $this->assertRejectedWithoutMutation((new QueryBuilder())->join('users u', 'u.id=o.userId', JoinType::Left),
            static fn($q) => $q->join('extra e', 'e.id=o.id', 'LEFT; DROP TABLE users'), static fn($q) => $q->get('orders o'),
            (new QueryBuilder())->join('users u', 'u.id=o.userId', 'LEFT')->get('orders o'));
        foreach (['READ LOCAL', ' read ', 'INVALID'] as $invalidMode) {
            $this->assertRejectedWithoutMutation((new QueryBuilder())->setLockTableMode(TableLockMode::Write),
                static fn($q) => $q->setLockTableMode($invalidMode), static fn($q) => $q->lockTable('users'),
                (new QueryBuilder())->setLockTableMode('WRITE')->lockTable('users'));
        }
        $invalidConditions = [
            fn($q) => $q->whereRaw('o.x = ?', [1], 'XOR'),
            fn($q) => $q->havingRaw('COUNT(*) > ?', [1], 'OR 1=1'),
            fn($q) => $q->whereColumn('o.a', 'o.b', '=', 'XOR'),
            fn($q) => $q->havingColumn('paid', 'total', '=', 'OR 1=1'),
            fn($q) => $q->joinOn('users u', 'u.id', 'o.id', '=', 'XOR'),
        ];
        foreach (['LIKE', 'IN', 'BETWEEN', '>= 0 OR 1=1', ''] as $invalidOperator) {
            $invalidConditions[] = fn($q) => $q->whereColumn('o.a', 'o.b', $invalidOperator);
            $invalidConditions[] = fn($q) => $q->havingColumn('paid', 'total', $invalidOperator);
            $invalidConditions[] = fn($q) => $q->joinOn('users u', 'u.id', 'o.id', $invalidOperator);
        }
        foreach ($invalidConditions as $invalid) {
            $this->assertRejectedWithoutMutation($this->conditionBuilder(), $invalid, static fn($q) => $q->get('orders o'),
                $this->conditionBuilder()->get('orders o'));
        }
    }

    public function testTableLockModesPreserveSqlOperationFlagsAndThePersistentMode(): void
    {
        foreach ([[TableLockMode::Read, 'READ'], [TableLockMode::Write, 'WRITE']] as [$enum, $string]) {
            $actual = (new QueryBuilder())->setPrefix('app_')->setLockTableMode($enum)->lockTable(['users', 'orders']);
            $expected = (new QueryBuilder())->setPrefix('app_')->setLockTableMode(strtolower($string))->lockTable(['users', 'orders']);
            self::assertSame($this->buildResult($expected), $this->buildResult($actual));
            self::assertSame(QueryBuilder::TS_OP_LOCK_TABLE, $actual->getLastTransactionOp());
            // 原有表锁模式在重置后仍会保留；SELECT 行锁状态则会清除。
            $actual->lockTable('users');
            $expected->lockTable('users');
            self::assertSame($this->buildResult($expected), $this->buildResult($actual));
        }
    }

    public function testLockWaitPoliciesMatchStringsAndResetAfterEachBuild(): void
    {
        foreach ([[LockWaitPolicy::Default, ''], [LockWaitPolicy::Nowait, 'NOWAIT'], [LockWaitPolicy::SkipLocked, 'SKIP LOCKED']] as [$enum, $string]) {
            $actual = (new QueryBuilder())->where('id', 1)->selectForUpdate(true, $enum)->get('orders');
            $expected = (new QueryBuilder())->where('id', 1)->selectForUpdate(true, $string)->get('orders');
            self::assertSame($this->buildResult($expected), $this->buildResult($actual));
            self::assertSame(QueryBuilder::TS_OP_LOCK_FOR_UPDATE, $actual->getLastTransactionOp());
            $actual->get('orders');
            self::assertSame($this->buildResult((new QueryBuilder())->get('orders')), $this->buildResult($actual));
            $actual->setSelectLockOption($enum)->forUpdate()->get('orders');
            self::assertSame($this->buildResult((new QueryBuilder())->setSelectLockOption($string)->forUpdate()->get('orders')), $this->buildResult($actual));
            $actual->selectForUpdate(true, $enum)->forUpdate(false)->get('orders');
            self::assertNull($actual->getLastTransactionOp());
            self::assertStringNotContainsString('FOR UPDATE', $actual->getLastPrepareQuery());
        }
        self::assertSame($this->buildResult((new QueryBuilder())->nowait()->get('orders')),
            $this->buildResult((new QueryBuilder())->selectForUpdate(true, LockWaitPolicy::Nowait)->get('orders')));
        self::assertSame($this->buildResult((new QueryBuilder())->skipLocked()->get('orders')),
            $this->buildResult((new QueryBuilder())->selectForUpdate(true, LockWaitPolicy::SkipLocked)->get('orders')));
    }

    public function testExplicitDefaultLockPolicyClearsTheConfiguredWaitPolicy(): void
    {
        $default = $this->buildResult((new QueryBuilder())->forUpdate()->get('orders'));
        foreach ([LockWaitPolicy::Default, ''] as $option) {
            $combined = (new QueryBuilder())->skipLocked()->selectForUpdate(true, $option)->get('orders');
            self::assertSame($default, $this->buildResult($combined));
            $separate = (new QueryBuilder())->nowait()->setSelectLockOption($option)->get('orders');
            self::assertSame($default, $this->buildResult($separate));
        }

        $configured = (new QueryBuilder())->setSelectLockOption(LockWaitPolicy::SkipLocked)->forUpdate()->get('orders');
        self::assertSame($this->buildResult((new QueryBuilder())->skipLocked()->get('orders')), $this->buildResult($configured));
        $reconfigured = (new QueryBuilder())->skipLocked()->forUpdate(false)->nowait()->get('orders');
        self::assertSame($this->buildResult((new QueryBuilder())->nowait()->get('orders')), $this->buildResult($reconfigured));
    }

    public function testLockStringsNormalizeKnownPoliciesAndPreserveLegacyRawSuffixes(): void
    {
        self::assertSame($this->buildResult((new QueryBuilder())->selectForUpdate(true, LockWaitPolicy::Nowait)->get('orders')),
            $this->buildResult((new QueryBuilder())->selectForUpdate(true, ' nowait ')->get('orders')));
        self::assertSame($this->buildResult((new QueryBuilder())->setSelectLockOption(LockWaitPolicy::SkipLocked)->forUpdate()->get('orders')),
            $this->buildResult((new QueryBuilder())->setSelectLockOption(' skip locked ')->forUpdate()->get('orders')));
        $raw = (new QueryBuilder())->selectForUpdate(true, 'OF o NOWAIT')->get('orders o');
        self::assertStringEndsWith('FOR UPDATE OF o NOWAIT', trim($raw->getLastPrepareQuery()));
        $raw = (new QueryBuilder())->setSelectLockOption('OF o SKIP LOCKED')->forUpdate()->get('orders o');
        self::assertStringEndsWith('FOR UPDATE OF o SKIP LOCKED', trim($raw->getLastPrepareQuery()));
    }

    public function testExistingNamedParameterNamesAcceptEnums(): void
    {
        $actual = (new QueryBuilder())->join(joinTable: 'users u', joinCondition: 'u.id=o.userId', joinType: JoinType::Left)
            ->joinWhere(whereJoin: 'users u', whereProp: 'u.active', whereValue: true, operator: ComparisonOperator::Equal, cond: ConditionConjunction::And)
            ->joinOn(table: 'users u', first: 'u.tenantId', second: 'o.tenantId', operator: ComparisonOperator::NullSafeEqual, cond: ConditionConjunction::And)
            ->where(whereProp: 'o.id', whereValue: 7, operator: ComparisonOperator::GreaterThan, cond: ConditionConjunction::And)
            ->whereIn(column: 'o.status', values: [1, 2], cond: ConditionConjunction::Or)
            ->whereColumn(first: 'o.a', second: 'o.b', operator: ComparisonOperator::LessThan, cond: ConditionConjunction::And)
            ->having(havingProp: 'COUNT(*)', havingValue: 2, operator: ComparisonOperator::GreaterThanOrEqual, cond: ConditionConjunction::And)
            ->havingRaw(sql: 'SUM(amount) > ?', parameters: [5], cond: ConditionConjunction::Or)
            ->orderBy(orderByField: 'o.id', orderbyDirection: SortDirection::Desc, customFieldsOrRegExp: '^1')
            ->selectForUpdate(isLock: true, option: LockWaitPolicy::Nowait)->get('orders o');
        $expected = (new QueryBuilder())->join('users u', 'u.id=o.userId', 'LEFT')->joinWhere('users u', 'u.active', true, '=', 'AND')
            ->joinOn('users u', 'u.tenantId', 'o.tenantId', '<=>', 'AND')->where('o.id', 7, '>', 'AND')
            ->whereIn('o.status', [1, 2], 'OR')->whereColumn('o.a', 'o.b', '<', 'AND')
            ->having('COUNT(*)', 2, '>=', 'AND')->havingRaw('SUM(amount) > ?', [5], 'OR')->orderBy('o.id', 'DESC', '^1')
            ->selectForUpdate(true, 'NOWAIT')->get('orders o');
        self::assertSame($this->buildResult($expected), $this->buildResult($actual));
        $tableLock = (new QueryBuilder())->setLockTableMode(method: TableLockMode::Write)->lockTable(table: 'orders');
        self::assertSame($this->buildResult((new QueryBuilder())->setLockTableMode('WRITE')->lockTable('orders')), $this->buildResult($tableLock));
        self::assertSame($this->buildResult((new QueryBuilder())->setSelectLockOption('SKIP LOCKED')->forUpdate()->get('orders')),
            $this->buildResult((new QueryBuilder())->setSelectLockOption(option: LockWaitPolicy::SkipLocked)->forUpdate()->get('orders')));
    }
}
