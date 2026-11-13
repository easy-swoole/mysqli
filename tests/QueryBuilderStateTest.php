<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\Exception;
use PHPUnit\Framework\TestCase;

final class QueryBuilderStateTest extends TestCase
{
    private function buildResult(QueryBuilder $builder): array
    {
        return [$builder->getLastPrepareQuery(), $builder->getLastBindParams(), $builder->getLastQueryOptions(),
            $builder->getLastTransactionOp(), $builder->getLastQuery()];
    }

    public function testGetFieldDistinguishesImplicitAndExplicitFields(): void
    {
        $builder = new QueryBuilder();
        self::assertNull($builder->getField());
        self::assertSame(['*'], $builder->fields('*')->getField());
        self::assertSame([], $builder->fields([])->getField());
        self::assertSame(['id'], $builder->fields('id')->getField());
        self::assertSame(['id', 'name'], $builder->fields(['id', 'name'])->getField());
        self::assertNull($builder->get('users')->getField());

        $fields = $builder->selectRaw('COALESCE(name, ?)', ['guest'])->getField();
        self::assertCount(1, $fields);
        self::assertSame('COALESCE(name, ?)', $fields[0]->getSql());
        self::assertSame(['guest'], $fields[0]->getParameters());
        self::assertNull($builder->reset()->getField());
    }

    public function testGetOnePassesResolvedFieldsToAnOverriddenGet(): void
    {
        $builder = new class extends QueryBuilder {
            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                return parent::get($tableName, $numRows, [...(array)($columns ?? '*'), 'audit_id']);
            }
        };

        $builder->fields('id')->getOne('users');
        self::assertSame('SELECT  id, audit_id FROM `users` LIMIT 1', $builder->getLastPrepareQuery());
        $builder->fields('id')->getOne('users', null);
        self::assertSame('SELECT  id, audit_id FROM `users` LIMIT 1', $builder->getLastPrepareQuery());
        $builder->fields('id')->getOne('users', '*');
        self::assertSame('SELECT  *, audit_id FROM `users` LIMIT 1', $builder->getLastPrepareQuery());
    }

    public function testClonesStayIndependentAfterWhereHelpersAndWhenHelpersRunOnTheClone(): void
    {
        $helpers = [
            fn(QueryBuilder $q) => $q->whereRaw('score > ?', [2]),
            fn(QueryBuilder $q) => $q->whereColumn('r.score', 'r.baseline'),
            fn(QueryBuilder $q) => $q->whereGroup(static fn(QueryBuilder $nested) => $nested->whereRaw('score > ?', [2])->orWhereColumn('r.score', 'r.baseline')),
        ];
        foreach ($helpers as $helper) {
            $original = (new QueryBuilder())->where('tenantId', 7);
            $helper($original);
            $fork = clone $original;
            $fork->where('fork_only', 31);
            $original->where('original_only', 41);
            $expectedFork = (new QueryBuilder())->where('tenantId', 7);
            $helper($expectedFork);
            $expectedFork->where('fork_only', 31)->get('records r');
            $expectedOriginal = (new QueryBuilder())->where('tenantId', 7);
            $helper($expectedOriginal);
            $expectedOriginal->where('original_only', 41)->get('records r');
            self::assertSame($this->buildResult($expectedFork), $this->buildResult($fork->get('records r')));
            self::assertSame($this->buildResult($expectedOriginal), $this->buildResult($original->get('records r')));

            $original = (new QueryBuilder())->where('tenantId', 7);
            $fork = clone $original;
            $helper($fork);
            $fork->get('records r');
            self::assertSame($this->buildResult((new QueryBuilder())->where('tenantId', 7)->get('records r')), $this->buildResult($original->get('records r')));
        }
    }

    public function testClonesStayIndependentAfterEveryHavingHelper(): void
    {
        $helpers = [
            fn(QueryBuilder $q) => $q->having('SUM(score)', 2, '>'),
            fn(QueryBuilder $q) => $q->havingRaw('SUM(score) > ?', [2]),
            fn(QueryBuilder $q) => $q->havingColumn('paid', 'total'),
            fn(QueryBuilder $q) => $q->havingGroup(static fn(QueryBuilder $nested) => $nested->havingRaw('SUM(score) > ?', [2])->orHavingColumn('paid', 'total')),
        ];
        foreach ($helpers as $helper) {
            $original = (new QueryBuilder())->groupBy('tenantId')->having('COUNT(*)', 1, '>');
            $helper($original);
            $fork = clone $original;
            $fork->orHaving('SUM(refund)', 31, '>');
            $original->having('SUM(refund)', 41, '>');
            $expectedFork = (new QueryBuilder())->groupBy('tenantId')->having('COUNT(*)', 1, '>');
            $helper($expectedFork);
            $expectedFork->orHaving('SUM(refund)', 31, '>')->get('records', null, 'tenantId');
            $expectedOriginal = (new QueryBuilder())->groupBy('tenantId')->having('COUNT(*)', 1, '>');
            $helper($expectedOriginal);
            $expectedOriginal->having('SUM(refund)', 41, '>')->get('records', null, 'tenantId');
            self::assertSame($this->buildResult($expectedFork), $this->buildResult($fork->get('records', null, 'tenantId')));
            self::assertSame($this->buildResult($expectedOriginal), $this->buildResult($original->get('records', null, 'tenantId')));
        }
    }

    public function testClonesStayIndependentAfterJoinOnAndGroupedJoinHelpers(): void
    {
        $base = static fn(): QueryBuilder => (new QueryBuilder())->join('users u', 'u.id=r.userId', 'LEFT')->where('r.tenantId', 7);
        $helpers = [
            fn(QueryBuilder $q) => $q->joinWhere('users u', 'u.active', 2),
            fn(QueryBuilder $q) => $q->joinOn('users u', 'u.tenantId', 'r.tenantId'),
            fn(QueryBuilder $q) => $q->joinWhereGroup('users u', static fn(QueryBuilder $nested) => $nested->whereRaw('u.active = ?', [2])->whereColumn('u.tenantId', 'r.tenantId')),
            fn(QueryBuilder $q) => $q->joinOrWhereGroup('users u', static fn(QueryBuilder $nested) => $nested->where('u.active', 2)),
        ];
        foreach ($helpers as $helper) {
            $original = $base();
            $helper($original);
            $fork = clone $original;
            $fork->joinWhere('users u', 'u.branch', 31);
            $original->joinWhere('users u', 'u.branch', 41);
            $expectedFork = $base();
            $helper($expectedFork);
            $expectedFork->joinWhere('users u', 'u.branch', 31)->get('records r');
            $expectedOriginal = $base();
            $helper($expectedOriginal);
            $expectedOriginal->joinWhere('users u', 'u.branch', 41)->get('records r');
            self::assertSame($this->buildResult($expectedFork), $this->buildResult($fork->get('records r')));
            self::assertSame($this->buildResult($expectedOriginal), $this->buildResult($original->get('records r')));
        }
    }

    public function testGroupCallbacksCaptureConditionsInsteadOfRetainingTheTemporaryBuilder(): void
    {
        $where = null;
        $having = null;
        $on = null;
        $builder = (new QueryBuilder())->join('users u', 'u.id=r.userId')
            ->whereGroup(static function (QueryBuilder $q) use (&$where): void { $where = $q; $q->whereRaw('r.active = ?', [1]); })
            ->havingGroup(static function (QueryBuilder $q) use (&$having): void { $having = $q; $q->havingRaw('COUNT(*) > ?', [2]); })
            ->joinWhereGroup('users u', static function (QueryBuilder $q) use (&$on): void { $on = $q; $q->where('u.active', 3); });
        $where->where('later_where', 11);
        $having->having('later_having', 12);
        $on->where('later_on', 13);
        $fork = clone $builder;
        $builder->get('records r');
        $fork->get('records r');
        self::assertSame([3, 1, 2], $builder->getLastBindParams());
        self::assertSame($this->buildResult($builder), $this->buildResult($fork));
        self::assertStringNotContainsString('later_', $builder->getLastPrepareQuery());
    }

    public function testSubclassConstructorAndCloneNeedNotCallParentForStateIsolation(): void
    {
        $original = new class extends QueryBuilder {
            public int $constructCalls = 0;
            public int $cloneCalls = 0;
            public function __construct() { $this->constructCalls++; }
            public function __clone(): void { $this->cloneCalls++; $this->where('clone_marker', 99); }
        };
        $original->whereRaw('active = ?', [1])->whereGroup(static fn(QueryBuilder $q) => $q->where('owner', 2));
        $fork = clone $original;
        $fork->where('fork_only', 3)->get('records');
        $original->get('records');
        self::assertSame(1, $original->constructCalls);
        self::assertSame(1, $fork->constructCalls);
        self::assertSame(0, $original->cloneCalls);
        self::assertSame(1, $fork->cloneCalls);
        self::assertSame([1, 2], $original->getLastBindParams());
        self::assertSame([1, 2, 99, 3], $fork->getLastBindParams());
        self::assertStringNotContainsString('clone_marker', $original->getLastPrepareQuery());
        $class = $original::class;
        $fresh = new $class();
        self::assertSame([], $fresh->getLastBindParams());
        self::assertSame('SELECT  * FROM `records`', $fresh->get('records')->getLastPrepareQuery());
    }

    public function testCountPreservesSubclassCloneHooksAndVirtualGetCalls(): void
    {
        $template = new class extends QueryBuilder {
            public static array $events = [];
            public function __clone(): void
            {
                self::$events[] = 'clone';
                $this->where('clone_marker', 42)->orderByDesc('ignored')->limit(99);
            }
            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                self::$events[] = 'get';
                return parent::get($tableName, $numRows, $columns);
            }
        };
        $template->whereRaw('active = ?', [1])->count('records');
        self::assertSame(['clone', 'get'], $template::$events);
        self::assertSame([1, 42], $template->getLastBindParams());
        self::assertStringNotContainsString('ORDER BY', $template->getLastPrepareQuery());
        self::assertSame(1, substr_count($template->getLastPrepareQuery(), 'LIMIT'));
        self::assertStringEndsWith('AS `__mysqli_count` LIMIT 1', $template->getLastPrepareQuery());

    }

    public function testCompletedOutputsAndReturnedParameterArraysStayIndependentAcrossClones(): void
    {
        $original = (new QueryBuilder())->distinct()->where('id', 1)->get('records');
        $completed = $this->buildResult($original);
        $parameters = $original->getLastBindParams();
        $parameters[0] = 'changed';
        $options = $original->getLastQueryOptions();
        $options[] = 'ALL';
        $fork = clone $original;
        $fork->whereRaw('id > ?', [2])->get('archive');
        self::assertSame($completed, $this->buildResult($original));
        $forkCompleted = $this->buildResult($fork);
        $original->whereColumn('a.id', 'b.id')->get('records a');
        self::assertSame($forkCompleted, $this->buildResult($fork));
    }

    public function testResetDiscardsOnlyPendingStateWhileKeepingCompletedOutputAndPersistentSettings(): void
    {
        $builder = (new QueryBuilder())->setPrefix('app_')->setLockTableMode('WRITE')->where('id', 1)->get('records');
        $completed = $this->buildResult($builder);
        $builder->selectRaw('? AS pending', [2])->whereRaw('id = ?', [3])->distinct()->forceIndex('idx_id')->skipLocked()->limit(5);
        self::assertSame($completed, $this->buildResult($builder));
        self::assertSame(['DISTINCT'], $builder->getQueryOptions());
        $builder->reset();
        self::assertSame($completed, $this->buildResult($builder));
        self::assertSame([], $builder->getQueryOptions());
        $builder->get('records');
        self::assertSame('SELECT  * FROM `app_records`', $builder->getLastPrepareQuery());
        self::assertSame([], $builder->getLastBindParams());
        $builder->lockTable('records');
        self::assertSame('LOCK TABLES `app_records` WRITE', $builder->getLastPrepareQuery());
    }

    public function testDebugSqlIsAvailableBeforeVirtualResetPublishesThePreparedSnapshot(): void
    {
        $builder = new class extends QueryBuilder {
            public array $observations = [];
            public function reset(): QueryBuilder
            {
                $before = [$this->getLastPrepareQuery(), $this->getLastBindParams(), $this->getLastQuery()];
                parent::reset();
                $this->observations[] = [$before, [$this->getLastPrepareQuery(), $this->getLastBindParams(), $this->getLastQuery()]];
                return $this;
            }
        };
        $builder->where('id', 1)->get('records');
        self::assertNull($builder->observations[0][0][0]);
        self::assertSame([], $builder->observations[0][0][1]);
        self::assertStringContainsString('`id` = 1', $builder->observations[0][0][2]);
        self::assertSame($builder->getLastPrepareQuery(), $builder->observations[0][1][0]);
        self::assertSame([1], $builder->observations[0][1][1]);
        self::assertSame($builder->observations[0][0][2], $builder->observations[0][1][2]);
        $first = $builder->getLastPrepareQuery();
        $builder->where('id', 2)->get('records');
        self::assertSame($first, $builder->observations[1][0][0]);
        self::assertSame([1], $builder->observations[1][0][1]);
        self::assertStringContainsString('`id` = 2', $builder->observations[1][0][2]);
        self::assertSame([2], $builder->observations[1][1][1]);
    }

    public function testInternalPublicationDoesNotCallAnOverriddenDebugGetter(): void
    {
        $builder = new class extends QueryBuilder {
            public int $debugCalls = 0;
            public function getLastQuery(): mixed { $this->debugCalls++; return 'view:' . parent::getLastQuery(); }
        };
        $builder->where('id', 1)->get('records');
        self::assertSame(0, $builder->debugCalls);
        $builder->reset();
        self::assertSame(0, $builder->debugCalls);
        self::assertStringStartsWith('view:SELECT', $builder->getLastQuery());
        self::assertSame(1, $builder->debugCalls);
        $builder->raw('SELECT ? AS value', [2]);
        self::assertSame(1, $builder->debugCalls);
    }

    public function testUnpublishedDebugSqlSurvivesAFailedBuildAndExplicitPendingDiscard(): void
    {
        $builder = new class extends QueryBuilder {
            public bool $publish = true;
            public function reset(): QueryBuilder { return $this->publish ? parent::reset() : $this; }
            public function discardPending(): QueryBuilder { return parent::reset(); }
        };
        $builder->raw('SELECT ? AS previous', [0]);
        $published = $builder->getLastPrepareQuery();
        $builder->publish = false;
        $builder->where('id', 1)->get('records');
        $debug = $builder->getLastQuery();
        self::assertSame($published, $builder->getLastPrepareQuery());
        self::assertStringContainsString('`id` = 1', $debug);
        try { $builder->where('bad', [2])->get('records'); self::fail('Expected invalid comparison'); }
        catch (Exception) { }
        $builder->discardPending();
        self::assertSame($debug, $builder->getLastQuery());
        self::assertSame($published, $builder->getLastPrepareQuery());
        self::assertSame([0], $builder->getLastBindParams());
    }

    public function testNewWritesDoNotUseParametersFromAnUnpublishedBuild(): void
    {
        $builder = new class extends QueryBuilder {
            public bool $publish = false;
            public function reset(): QueryBuilder { return $this->publish ? parent::reset() : $this; }
        };
        foreach ([
            static fn(QueryBuilder $q) => $q->insert('users', ['id' => 42]),
            static fn(QueryBuilder $q) => $q->update('users', ['id' => 42]),
            static fn(QueryBuilder $q) => $q->where('id', 42)->delete('users'),
        ] as $build) {
            $builder->publish = false;
            $builder->raw('SELECT ?', [7]);
            $builder->publish = true;
            $build($builder);
            self::assertSame([42], $builder->getLastBindParams());
            self::assertStringContainsString('42', $builder->getLastQuery());
        }
    }

    public function testFromSubAndUnionSnapshotWhileInAndExistsReadTheirSourceAtBuildTime(): void
    {
        $sub = QueryBuilder::subQuery()->where('state', 'initial')->get('items', null, 'id');
        $branch = (new QueryBuilder())->where('state', 'branch_initial')->get('archive', null, 'id');
        // 抽离后的 SELECT 规划器不得拒绝原先可用的 null 空前缀。
        $from = (new QueryBuilder())->setPrefix(null)->fromSub($sub, 's');
        $union = (new QueryBuilder())->union($branch);
        $in = (new QueryBuilder())->whereIn('id', $sub)->whereNotIn('otherId', $sub);
        $exists = (new QueryBuilder())->whereExists($sub)->whereNotExists($sub);
        $sub->where('state', 'later')->get('items', null, 'id');
        $branch->where('state', 'branch_later')->get('archive', null, 'id');
        $from->get(null);
        $union->get('items', null, 'id');
        $in->get('items');
        $exists->get('items');
        self::assertSame(['initial'], $from->getLastBindParams());
        self::assertSame(['branch_initial'], $union->getLastBindParams());
        self::assertSame(['later', 'later'], $in->getLastBindParams());
        self::assertSame(['later', 'later'], $exists->getLastBindParams());
        $completed = [$this->buildResult($from), $this->buildResult($union), $this->buildResult($in), $this->buildResult($exists)];
        $sub->raw('SELECT ? AS id', ['future']);
        $branch->raw('SELECT ? AS id', ['branch_future']);
        self::assertSame($completed, [$this->buildResult($from), $this->buildResult($union), $this->buildResult($in), $this->buildResult($exists)]);
    }

    public function testCloningDoesNotTurnDelayedSubqueryReferencesIntoDeepSnapshots(): void
    {
        $sub = QueryBuilder::subQuery()->where('state', 'first')->get('items', null, 'id');
        $original = (new QueryBuilder())->whereIn('id', $sub)->whereExists($sub);
        $fork = clone $original;
        $sub->where('state', 'second')->get('items', null, 'id');
        self::assertSame(['second', 'second'], $fork->get('items')->getLastBindParams());
        $sub->where('state', 'third')->get('items', null, 'id');
        self::assertSame(['third', 'third'], $original->get('items')->getLastBindParams());
        self::assertSame(['second', 'second'], $fork->getLastBindParams());
    }

    public function testJoinSubqueriesRemainDelayedAcrossOuterClones(): void
    {
        $sub = QueryBuilder::subQuery('u')->where('state', 'first')->get('users', null, 'id');
        $original = (new QueryBuilder())->join($sub, 'u.id=r.userId')->joinWhereGroup('u', static fn(QueryBuilder $q) => $q->where('u.enabled', true));
        $fork = clone $original;
        $sub->where('state', 'second')->get('users', null, 'id');
        self::assertSame(['second', true], $fork->get('records r')->getLastBindParams());
        $sub->where('state', 'third')->get('users', null, 'id');
        self::assertSame(['third', true], $original->get('records r')->getLastBindParams());
    }
}
