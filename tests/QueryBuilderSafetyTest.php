<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{ConditionValue, Expression, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class QueryBuilderSafetyTest extends TestCase
{
    public function testFieldOrderingBindsOriginalValuesAtTheirSqlPosition(): void
    {
        $values = ['paid+', 'a/b', "O'Reilly", '中文', '', null, false, 0, 1.5];
        $branch = (new QueryBuilder())->selectRaw('? AS id', [8])->get('(SELECT 1) seed');
        $q = (new QueryBuilder())->orderBy('id', 'ASC', $values)->where('tenantId', 7)
            ->union($branch)->selectRaw('? AS id', [9])->get('orders');
        self::assertSame([9, 7, 8, ...$values], $q->getLastBindParams());
        self::assertStringEndsWith('ORDER BY FIELD (id, ?, ?, ?, ?, ?, ?, ?, ?, ?) ASC ', $q->getLastPrepareQuery());
        self::assertStringNotContainsString('paid+', $q->getLastPrepareQuery());
    }

    public function testCustomSortKeysIncludeValuesAndPreserveDirectionReplacement(): void
    {
        $q = (new QueryBuilder())->orderBy('status', 'ASC', ['a/b'])
            ->orderBy('status', 'DESC', ['a+b'])->orderBy('status', 'DESC', ['a/b'])->get('orders');
        self::assertSame(['a/b', 'a+b'], $q->getLastBindParams());
        self::assertSame(2, substr_count($q->getLastPrepareQuery(), 'FIELD (status, ?) DESC'));
    }

    public function testInvalidSortDoesNotOverwritePreviousSort(): void
    {
        foreach ([[], [new \stdClass()], [[1, 2]]] as $values) {
            $q = (new QueryBuilder())->orderByAsc('id');
            try {
                $q->orderBy('status', 'DESC', $values);
                self::fail('Invalid custom sort should be rejected');
            } catch (\InvalidArgumentException $error) {
                $q->get('orders');
                self::assertStringEndsWith('ORDER BY id ASC ', $q->getLastPrepareQuery());
                self::assertSame([], $q->getLastBindParams());
            }
        }
    }

    public function testQueryOptionsAreAppliedOnlyWhenEntireBatchIsValid(): void
    {
        foreach ([['DISTINCT', 'FOR UPDATE', 'INVALID'], ['DISTINCT', []]] as $options) {
            $q = (new QueryBuilder())->setQueryOption('ALL')->where('tenantId', 7);
            $caught = null;
            try { $q->setQueryOption($options); }
            catch (\Throwable $error) { $caught = $error; }
            self::assertNotNull($caught, 'Invalid query option should be rejected');
            $q->get('orders');
            self::assertSame('SELECT ALL * FROM `orders` WHERE  `tenantId` = ? ', $q->getLastPrepareQuery());
            self::assertSame(['ALL'], $q->getLastQueryOptions());
            self::assertSame([7], $q->getLastBindParams());
            self::assertNull($q->getLastTransactionOp());
        }
    }

    public function testInvalidBuilderArgumentsAreNotSilentlyIgnored(): void
    {
        $cases = [
            [static fn(QueryBuilder $q) => $q->raw(['SELECT ?', 'SELECT ?'], [1, 2]), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->raw('SELECT ?', 7), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->getColumn('users', ['id']), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->onDuplicate(false), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->union(false), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->union('SELECT 1', 'false'), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->selectForUpdate('false'), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->lockInShareMode('false'), \TypeError::class],
            [static fn(QueryBuilder $q) => $q->union(new QueryBuilder()), \InvalidArgumentException::class],
            [static fn(QueryBuilder $q) => $q->insert('users', ['id' => 1]), \LogicException::class],
            [static fn(QueryBuilder $q) => $q->update('users', ['id' => 1]), \LogicException::class],
            [static fn(QueryBuilder $q) => $q->delete('users'), \LogicException::class],
        ];
        foreach ($cases as [$build, $expected]) {
            $builder = QueryBuilder::subQuery()->raw('SELECT ? AS id', [9]);
            try {
                $build($builder);
                self::fail('Expected invalid input to fail');
            } catch (\TypeError|\LogicException $error) {
                self::assertInstanceOf($expected, $error);
                self::assertSame('SELECT ? AS id', $builder->getLastPrepareQuery());
                self::assertSame([9], $builder->getLastBindParams());
            }
        }
    }

    public function testDebugSqlRejectsUnsupportedParameterTypes(): void
    {
        $builder = (new QueryBuilder())->raw('SELECT ?', [new \stdClass()]);
        $this->expectException(\TypeError::class);
        $builder->getLastQuery();
    }

    public function testTablePrefixDoesNotTurnAnEmptyWriteTargetIntoATableName(): void
    {
        $builder = (new QueryBuilder())->setPrefix('app_')->where('id', 1)->update('', ['status' => 'open']);
        self::assertSame('UPDATE  SET `status` = ? WHERE  `id` = ? ', $builder->getLastPrepareQuery());
        self::assertSame(['open', 1], $builder->getLastBindParams());
    }

    public function testCompilersKeepCommasInsideCallerProvidedSqlFragments(): void
    {
        $builder = (new QueryBuilder())->groupBy('id,')->get('users', null, 'id');
        self::assertStringEndsWith('GROUP BY id, ', $builder->getLastPrepareQuery());

        $builder->orderByRaw('id,')->get('users', null, 'id');
        self::assertStringEndsWith('ORDER BY id, ', $builder->getLastPrepareQuery());

        $builder->where('id', 1)->update('users', ['score' => new Expression('?,', [5])]);
        self::assertSame('UPDATE `users` SET `score` = ?, WHERE  `id` = ? ', $builder->getLastPrepareQuery());
        self::assertSame([5, 1], $builder->getLastBindParams());
    }

    public function testWrongExpressionArgumentTypesAreNotTreatedAsOmitted(): void
    {
        foreach ([
            static fn() => QueryBuilder::not(false),
            static fn() => (new QueryBuilder())->update('users', ['score' => QueryBuilder::func('ABS(-1)', false)]),
        ] as $build) {
            try {
                $build();
                self::fail('Expected invalid expression arguments to fail');
            } catch (\TypeError $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function testInvalidLimitsDoNotTurnIntoDifferentLimits(): void
    {
        $builder = (new QueryBuilder())->get('users', '0');
        $sql = $builder->getLastPrepareQuery();
        self::assertStringEndsWith('LIMIT 0', $sql);
        foreach (['unknown', [5], -1, 1.5] as $limit) {
            try {
                $builder->get('users', $limit);
                self::fail('Expected an invalid limit to fail');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('LIMIT', $error->getMessage());
                self::assertSame($sql, $builder->getLastPrepareQuery());
            }
        }
    }

    public function testNowRequiresACompleteInterval(): void
    {
        self::assertSame(['[F]' => ['NOW() - interval 2 hour ']], QueryBuilder::now(' -2 h '));
        foreach (['later', '2h-extra'] as $interval) {
            try {
                QueryBuilder::now($interval);
                self::fail('Expected invalid interval to fail');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid time interval', $error->getMessage());
            }
        }
    }

    public function testFailedCompilationDoesNotPublishPartialSqlOrLeakIntoRaw(): void
    {
        $q = (new QueryBuilder())->where('id', 1)->get('orders');
        $sql = $q->getLastPrepareQuery();
        $debug = $q->getLastQuery();
        try {
            $q->selectRaw('? AS marker', [9])->where('tenantId', 7)->where('id', [1, 2])->get('orders');
            self::fail('Expected invalid scalar comparison');
        } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
            self::assertSame($sql, $q->getLastPrepareQuery());
            self::assertSame($debug, $q->getLastQuery());
            self::assertSame([1], $q->getLastBindParams());
        }
        $q->reset();
        self::assertSame($sql, $q->getLastPrepareQuery());
        self::assertSame([1], $q->getLastBindParams());
        $q->raw('SELECT ? AS value', [99]);
        self::assertSame('SELECT ? AS value', $q->getLastPrepareQuery());
        self::assertSame([99], $q->getLastBindParams());
        $q->where('id', 2)->get('orders');
        self::assertSame([2], $q->getLastBindParams());
    }

    public function testRawImmediatelyAfterFailureReplacesBindingsAndDiscardsPendingClauses(): void
    {
        $q = (new QueryBuilder())->selectRaw('? AS marker', [9])->where('tenantId', 7)->where('id', [1]);
        try { $q->get('orders'); } catch (\EasySwoole\Mysqli\Exception\Exception $error) {}
        $q->raw('SELECT ? AS value', [99]);
        self::assertSame([99], $q->getLastBindParams());
        $q->get('orders');
        self::assertSame('SELECT  * FROM `orders`', $q->getLastPrepareQuery());
        self::assertSame([], $q->getLastBindParams());
    }

}
