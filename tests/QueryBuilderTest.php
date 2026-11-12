<?php


namespace EasySwoole\Mysqli\Tests;


use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\ConditionValue;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private QueryBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new QueryBuilder();
    }

    private function normalizedSql(QueryBuilder $builder): string
    {
        return trim(preg_replace('/\s+/', ' ', $builder->getLastPrepareQuery()));
    }

    public function testConditionSugarMatchesLegacyCalls(): void
    {
        $this->builder->whereIn('id', [1, 2])->whereNotIn('status', [3])
            ->whereNull('deleted_at')->whereNotNull('name')
            ->whereBetween('price', [10, 20])->orderByAsc('price')->orderByDesc('id')->get('items');
        $legacy = (new QueryBuilder())->where('id', [1, 2], 'IN')->where('status', [3], 'NOT IN')
            ->where('deleted_at', null, 'IS')->where('name', null, 'IS NOT')
            ->where('price', [10, 20], 'BETWEEN')->orderBy('price', 'ASC')->orderBy('id', 'DESC')->get('items');
        self::assertSame($legacy->getLastPrepareQuery(), $this->builder->getLastPrepareQuery());
        self::assertSame([1, 2, 3, 10, 20], $this->builder->getLastBindParams());
    }

    public function testEmptySetsAndLikeRemainBound(): void
    {
        $this->builder->whereIn('id', [])->whereNotIn('status', [], 'OR')
            ->whereLike('i.order', 'DBNULL')->whereLike('name', "%x' OR 1=1 --%")->get('items i');
        self::assertSame('SELECT * FROM items i WHERE 0 = 1 OR 1 = 1 AND `i`.`order` LIKE ? AND `name` LIKE ?', $this->normalizedSql($this->builder));
        self::assertSame(['DBNULL', "%x' OR 1=1 --%"], $this->builder->getLastBindParams());
        $this->builder->get('items');
        self::assertSame([], $this->builder->getLastBindParams());
    }

    public function testNestedGroupsPreservePrecedenceAndParameterOrder(): void
    {
        $this->builder->where('tenantId', 7)->whereGroup(static function (QueryBuilder $q): void {
            $q->where('status', 1)->orWhereGroup(static function (QueryBuilder $nested): void {
                $nested->where('status', 2)->whereBetween('price', [10, 20]);
            });
        })->whereNull('deleted_at')->get('items');
        self::assertSame('SELECT * FROM `items` WHERE `tenantId` = ? AND ( `status` = ? OR ( `status` = ? AND `price` BETWEEN ? AND ? )) AND `deleted_at` IS NULL', $this->normalizedSql($this->builder));
        self::assertSame([7, 1, 2, 10, 20], $this->builder->getLastBindParams());
        $this->builder->get('items');
        self::assertSame('SELECT * FROM `items`', $this->normalizedSql($this->builder));
    }

    public function testGroupsWithSubqueriesWorkInUpdatesAndDeletes(): void
    {
        $sub = QueryBuilder::subQuery()->forceIndex('PRIMARY')->where('active', 1)->get('users', null, 'id');
        $this->builder->whereGroup(static function (QueryBuilder $q) use ($sub): void {
            $q->whereIn('userId', $sub)->whereNotExists($sub, 'OR');
        })->update('items', ['status' => 3]);
        self::assertSame([3, 1, 1], $this->builder->getLastBindParams());
        self::assertStringContainsString('FORCE INDEX (`PRIMARY`)', $this->builder->getLastPrepareQuery());
        self::assertStringContainsString('OR NOT EXISTS', $this->normalizedSql($this->builder));
        $this->builder->orWhereGroup(static fn(QueryBuilder $q) => $q->whereNull('deleted_at'))->delete('items');
        self::assertSame('DELETE FROM `items` WHERE ( `deleted_at` IS NULL)', $this->normalizedSql($this->builder));
    }

    public function testGroupExistingWhereKeepsOrConditionsInsideTheAddedConstraint(): void
    {
        foreach (['select', 'update', 'delete'] as $operation) {
            foreach ([false, true] as $raw) {
                $builder = new QueryBuilder();
                if ($raw) {
                    $builder->where('status = ? OR score = ?', [1, 2]);
                    $conditions = 'status = ? OR score = ?';
                } else {
                    $builder->where('status', 1)->orWhere('score', 2);
                    $conditions = '`status` = ? OR `score` = ?';
                }
                self::assertSame($builder, $builder->groupExistingWhere());
                $builder->where('id', 7);
                if ($operation === 'update') {
                    $builder->update('items', ['name' => 'changed']);
                } elseif ($operation === 'delete') {
                    $builder->delete('items');
                } else {
                    $builder->get('items');
                }
                self::assertMatchesRegularExpression('/WHERE \( ' . preg_quote($conditions, '/') . '\s*\) AND `id` = \?/', $this->normalizedSql($builder));
                self::assertSame($operation === 'update' ? ['changed', 1, 2, 7] : [1, 2, 7], $builder->getLastBindParams());
            }
        }
    }

    public function testGroupExistingWhereDoesNothingWhenThereAreNoConditions(): void
    {
        $this->builder->get('previous');
        $previousSql = $this->builder->getLastPrepareQuery();
        self::assertSame($this->builder, $this->builder->groupExistingWhere());
        self::assertSame($previousSql, $this->builder->getLastPrepareQuery());
        $this->builder->where('id', 7)->get('items');
        self::assertSame('SELECT * FROM `items` WHERE `id` = ?', $this->normalizedSql($this->builder));
        self::assertSame([7], $this->builder->getLastBindParams());
        $this->builder->groupExistingWhere()->get('items');
        self::assertSame('SELECT * FROM `items`', $this->normalizedSql($this->builder));
    }

    public function testGroupExistingWherePreservesOtherStateParameterOrderAndCloneIsolation(): void
    {
        $joined = QueryBuilder::subQuery('u')->where('active', 20)->get('users');
        $this->builder->get('previous');
        $previousSql = $this->builder->getLastPrepareQuery();
        $this->builder->setPrefix('app_')->selectRaw('? AS marker', [10])->distinct()
            ->join($joined, 'u.id=i.userId', 'LEFT')->useIndex('idx_status')
            ->where('i.status', 30)->orWhere('i.score', 40)
            ->groupBy('i.id')->havingRaw('COUNT(*) > ?', [50])
            ->orderByRaw('FIELD(i.id, ?)', [60])->limit(2, 3)->selectForUpdate();
        $copy = clone $this->builder;
        $copy->groupExistingWhere();
        self::assertSame($previousSql, $copy->getLastPrepareQuery());
        $copy->where('i.id', 70)->get('items i');
        $sql = $this->normalizedSql($copy);
        self::assertSame([10, 20, 30, 40, 70, 50, 60], $copy->getLastBindParams());
        self::assertStringContainsString('SELECT DISTINCT ? AS marker FROM app_items i USE INDEX (`idx_status`)', $sql);
        self::assertStringContainsString('LEFT JOIN', $sql);
        self::assertStringContainsString('WHERE ( `i`.`status` = ? OR `i`.`score` = ? ) AND `i`.`id` = ?', $sql);
        self::assertStringContainsString('GROUP BY i.id HAVING (COUNT(*) > ?)', $sql);
        self::assertStringContainsString('ORDER BY FIELD(i.id, ?) LIMIT 2, 3 FOR UPDATE', $sql);
        self::assertSame(['DISTINCT'], $copy->getLastQueryOptions());

        $this->builder->get('items i');
        self::assertStringContainsString('WHERE `i`.`status` = ? OR `i`.`score` = ?', $this->normalizedSql($this->builder));
        self::assertSame([10, 20, 30, 40, 50, 60], $this->builder->getLastBindParams());
        $copy->get('items');
        self::assertSame('SELECT * FROM `app_items`', $this->normalizedSql($copy));
        self::assertSame([], $copy->getLastBindParams());
        self::assertSame([], $copy->getLastQueryOptions());
        $copy->where('unused', 1)->groupExistingWhere()->reset()->get('items');
        self::assertSame('SELECT * FROM `app_items`', $this->normalizedSql($copy));
        self::assertSame([], $copy->getLastBindParams());
    }

    public function testExistsAndNotInSubquery(): void
    {
        $sub = QueryBuilder::subQuery()->where('active', 1)->get('users', null, 'id');
        $this->builder->whereExists($sub)->whereNotIn('userId', $sub)->get('items');
        self::assertSame('SELECT * FROM `items` WHERE EXISTS (SELECT id FROM `users` WHERE `active` = ? ) AND `userId` NOT IN ( (SELECT id FROM `users` WHERE `active` = ? ) )', $this->normalizedSql($this->builder));
        self::assertSame([1, 1], $this->builder->getLastBindParams());
    }

    public function testNewHelpersRejectInvalidInputWithoutAddingConditions(): void
    {
        $invalidCalls = [
            static fn(QueryBuilder $q) => $q->whereBetween('price', [1]),
            static fn(QueryBuilder $q) => $q->whereGroup(static fn(QueryBuilder $g) => null),
            static fn(QueryBuilder $q) => $q->whereGroup(static function (QueryBuilder $g): void { $g->where('id', 2); throw new \InvalidArgumentException('callback failed'); }),
            static fn(QueryBuilder $q) => $q->whereExists(new QueryBuilder()),
            static fn(QueryBuilder $q) => $q->whereIn('id', QueryBuilder::subQuery()),
            static fn(QueryBuilder $q) => $q->whereExists(QueryBuilder::subQuery('u')->get('users')),
        ];
        foreach ($invalidCalls as $call) {
            $q = (new QueryBuilder())->where('id', 1);
            try {
                $call($q);
                self::fail('Expected invalid helper input to be rejected');
            } catch (\InvalidArgumentException $e) {
                $q->get('items');
                self::assertSame('SELECT * FROM `items` WHERE `id` = ?', $this->normalizedSql($q));
                self::assertSame([1], $q->getLastBindParams());
            }
        }
    }

    public function testUseAndIgnoreIndexesResetAndPreserveAliasAndPrefix(): void
    {
        $this->builder->setPrefix('app_')->useIndex(['idx_user', 'PRIMARY'])->ignoreIndex('idx_status')->get('items i');
        self::assertSame('SELECT * FROM app_items i USE INDEX (`idx_user`, `PRIMARY`) IGNORE INDEX (`idx_status`)', $this->normalizedSql($this->builder));
        $this->builder->get('items');
        self::assertSame('SELECT * FROM `app_items`', $this->normalizedSql($this->builder));
        $this->builder->forceIndex('old')->useIndex('new')->get('items');
        self::assertSame('SELECT * FROM `app_items` USE INDEX (`new`)', $this->normalizedSql($this->builder));
        $this->builder->useIndex('old')->forceIndex('new')->ignoreIndex('skip')->get('items');
        self::assertSame('SELECT * FROM `app_items` FORCE INDEX (`new`) IGNORE INDEX (`skip`)', $this->normalizedSql($this->builder));
        $this->builder->useIndex('x')->ignoreIndex('y')->useIndex([])->ignoreIndex([])->get('items');
        self::assertSame('SELECT * FROM `app_items`', $this->normalizedSql($this->builder));
    }

    public function testIndexSugarSubqueryIsolationAndValidation(): void
    {
        $sub = QueryBuilder::subQuery()->useIndex('idx_active')->where('active', 1)->get('users', null, 'id');
        $this->builder->ignoreIndex('idx_user')->whereIn('userId', $sub)->get('items');
        self::assertSame('SELECT * FROM `items` IGNORE INDEX (`idx_user`) WHERE `userId` IN ( (SELECT id FROM `users` USE INDEX (`idx_active`) WHERE `active` = ? ) )', $this->normalizedSql($this->builder));
        self::assertSame([1], $this->builder->getLastBindParams());
        foreach (['useIndex', 'ignoreIndex'] as $method) {
            try {
                $this->builder->$method(['valid', '']);
                self::fail('Expected invalid index to be rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Index names', $e->getMessage());
            }
        }
    }

    public function testLockSugarMatchesLegacyAndResets(): void
    {
        foreach (['forUpdate' => null, 'skipLocked' => 'SKIP LOCKED', 'nowait' => 'NOWAIT'] as $method => $option) {
            $this->builder->$method()->get('items', 10);
            $legacy = (new QueryBuilder())->selectForUpdate(true, $option)->get('items', 10);
            self::assertSame($legacy->getLastPrepareQuery(), $this->builder->getLastPrepareQuery());
            self::assertSame(QueryBuilder::TS_OP_LOCK_FOR_UPDATE, $this->builder->getLastTransactionOp());
            $this->builder->get('items');
            self::assertSame('SELECT * FROM `items`', $this->normalizedSql($this->builder));
        }
        $this->builder->skipLocked()->nowait()->get('items');
        self::assertStringEndsWith('FOR UPDATE NOWAIT', $this->builder->getLastPrepareQuery());
        $this->builder->skipLocked()->forUpdate(false)->get('items');
        self::assertSame('SELECT * FROM `items`', $this->normalizedSql($this->builder));
    }

    public function testLiteralConditionValuesAreAlwaysBound(): void
    {
        foreach (['DBNULL', true, false, 0, '0', ''] as $value) {
            $this->builder->where('tenantId', $value)->orWhere('enabled', $value)->get('orders');
            self::assertSame('SELECT * FROM `orders` WHERE `tenantId` = ? OR `enabled` = ?', $this->normalizedSql($this->builder));
            self::assertSame([$value, $value], $this->builder->getLastBindParams());
            $this->builder->having('tenantId', $value)->orHaving('enabled', $value)->get('orders');
            self::assertSame('SELECT * FROM `orders` HAVING `tenantId` = ? OR `enabled` = ?', $this->normalizedSql($this->builder));
            self::assertSame([$value, $value], $this->builder->getLastBindParams());
            $this->builder->join('users u', 'u.id = o.userId', 'LEFT')
                ->joinWhere('users u', 'u.name', $value)->joinOrWhere('users u', 'u.enabled', $value)->get('orders o');
            self::assertSame('SELECT * FROM orders o LEFT JOIN users u on (u.id = o.userId) AND u.name = ? OR u.enabled = ?', $this->normalizedSql($this->builder));
            self::assertSame([$value, $value], $this->builder->getLastBindParams());
        }
    }

    public function testExplicitRawMarkerAndSingleArgumentConditions(): void
    {
        foreach ([false, true] as $explicit) {
            $q = new QueryBuilder();
            if ($explicit) {
                $q->where('a.id = b.id', ConditionValue::Raw)->orWhere('a.id > 0', ConditionValue::Raw)
                    ->having('COUNT(*) > 0', ConditionValue::Raw)->orHaving('COUNT(*) = 0', ConditionValue::Raw)
                    ->joinWhere('users b', 'b.enabled = 1', ConditionValue::Raw)
                    ->joinOrWhere('users b', 'b.enabled = 2', ConditionValue::Raw);
            } else {
                $q->where('a.id = b.id')->orWhere('a.id > 0')
                    ->having('COUNT(*) > 0')->orHaving('COUNT(*) = 0')
                    ->joinWhere('users b', 'b.enabled = 1')->joinOrWhere('users b', 'b.enabled = 2');
            }
            $q->join('users b', 'b.id = a.id', 'LEFT')->get('orders a');
            self::assertSame('SELECT * FROM orders a LEFT JOIN users b on (b.id = a.id) AND b.enabled = 1 OR b.enabled = 2 WHERE a.id = b.id OR a.id > 0 HAVING COUNT(*) > 0 OR COUNT(*) = 0', $this->normalizedSql($q));
            self::assertSame([], $q->getLastBindParams());
        }
    }

    public function testTenantStringAndBooleanConditionsSurviveWritesAndGroups(): void
    {
        $this->builder->where('tenantId', 'DBNULL')->update('orders', ['enabled' => true]);
        self::assertSame('UPDATE `orders` SET `enabled` = ? WHERE `tenantId` = ?', $this->normalizedSql($this->builder));
        self::assertSame([true, 'DBNULL'], $this->builder->getLastBindParams());
        $this->builder->where('tenantId', 'DBNULL')->delete('orders');
        self::assertSame('DELETE FROM `orders` WHERE `tenantId` = ?', $this->normalizedSql($this->builder));
        self::assertSame(['DBNULL'], $this->builder->getLastBindParams());
        $this->builder->whereGroup(static function (QueryBuilder $q): void {
            $q->where('tenantId', 'DBNULL')->where('enabled', true)->where('disabled', false)
                ->whereNull('deleted_at')->where('userId IS NOT NULL', ConditionValue::Raw);
        })->get('orders');
        self::assertSame('SELECT * FROM `orders` WHERE ( `tenantId` = ? AND `enabled` = ? AND `disabled` = ? AND `deleted_at` IS NULL AND userId IS NOT NULL)', $this->normalizedSql($this->builder));
        self::assertSame(['DBNULL', true, false], $this->builder->getLastBindParams());
    }

    public function testMarkerChangePreservesNullAndEmptySetHelpers(): void
    {
        $this->builder->whereNull('deleted_at')->whereNotNull('paid_at')->whereIn('id', [])
            ->whereNotIn('status', [])->get('orders');
        self::assertSame('SELECT * FROM `orders` WHERE `deleted_at` IS NULL AND `paid_at` IS NOT NULL AND 0 = 1 AND 1 = 1', $this->normalizedSql($this->builder));
        self::assertSame([], $this->builder->getLastBindParams());
    }

    public function testGet(): void
    {
        $this->builder->get('get');
        $this->assertEquals('SELECT  * FROM `get`',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `get`',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->get('get',1);
        $this->assertEquals('SELECT  * FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->get('get',[2,10]);
        $this->assertEquals('SELECT  * FROM `get` LIMIT 2, 10',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `get` LIMIT 2, 10',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->get('get',null,['col1','col2']);
        $this->assertEquals('SELECT  col1, col2 FROM `get`',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  col1, col2 FROM `get`',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->get('get',1,['col1','col2']);
        $this->assertEquals('SELECT  col1, col2 FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  col1, col2 FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->get('get',[2,10],['col1','col2']);
        $this->assertEquals('SELECT  col1, col2 FROM `get` LIMIT 2, 10',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  col1, col2 FROM `get` LIMIT 2, 10',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());


        $this->builder->get('get',[2,10],['distinct col1','col2']);
        $this->assertEquals('SELECT  distinct col1, col2 FROM `get` LIMIT 2, 10',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  distinct col1, col2 FROM `get` LIMIT 2, 10',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());
    }

    public function testForceIndex(): void
    {
        $this->builder->forceIndex('idx_userId')->where('userId', 123)
            ->orderBy('mapId', 'DESC')->get('order_mapping', 10);
        self::assertSame(
            'SELECT  * FROM `order_mapping` FORCE INDEX (`idx_userId`) WHERE  `userId` = ?  ORDER BY mapId DESC  LIMIT 10',
            $this->builder->getLastPrepareQuery()
        );
        self::assertSame([123], $this->builder->getLastBindParams());
        $this->builder->get('order_mapping');
        self::assertSame('SELECT  * FROM `order_mapping`', $this->builder->getLastPrepareQuery());
    }

    public function testForceIndexSupportsMultipleIndexesAndTableAliasBeforeJoin(): void
    {
        $this->builder->setPrefix('app_')->forceIndex(['PRIMARY', 'idx`name'])
            ->join('users u', 'u.id = o.userId', 'LEFT')->get('order_mapping o');
        self::assertStringContainsString(
            'FROM app_order_mapping o FORCE INDEX (`PRIMARY`, `idx``name`) LEFT JOIN',
            $this->builder->getLastPrepareQuery()
        );
        self::assertSame([], $this->builder->getLastBindParams());
    }

    public function testForceIndexCanBeReplacedClearedAndReset(): void
    {
        $this->builder->forceIndex('old')->forceIndex('new')->getOne('orders');
        self::assertSame('SELECT  * FROM `orders` FORCE INDEX (`new`) LIMIT 1', $this->builder->getLastPrepareQuery());
        $this->builder->forceIndex('old')->forceIndex([])->get('orders');
        self::assertSame('SELECT  * FROM `orders`', $this->builder->getLastPrepareQuery());
        $this->builder->forceIndex('old')->reset()->get('orders');
        self::assertSame('SELECT  * FROM `orders`', $this->builder->getLastPrepareQuery());
    }

    public function testForceIndexRejectsInvalidNamesWithoutChangingPreviousSetting(): void
    {
        $this->builder->forceIndex('valid');
        foreach (['', ' ', ['ok', 123], [null], ["bad\0name"]] as $invalid) {
            try {
                $this->builder->forceIndex($invalid);
                self::fail('Expected invalid index name to be rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Index names', $e->getMessage());
            }
        }
        $this->builder->get('orders');
        self::assertSame('SELECT  * FROM `orders` FORCE INDEX (`valid`)', $this->builder->getLastPrepareQuery());
    }

    public function testGetOne(): void
    {
        $this->builder->getOne('get');
        $this->assertEquals('SELECT  * FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());
    }

    public function testGetOneUsesConfiguredFieldsOnlyWhenColumnsAreOmittedOrNull(): void
    {
        $this->builder->fields('id')->getOne('users');
        self::assertSame('SELECT id FROM `users` LIMIT 1', $this->normalizedSql($this->builder));

        $this->builder->fields('id')->getOne('users', null);
        self::assertSame('SELECT id FROM `users` LIMIT 1', $this->normalizedSql($this->builder));

        $this->builder->fields('id')->getOne('users', '*');
        self::assertSame('SELECT * FROM `users` LIMIT 1', $this->normalizedSql($this->builder));
    }

    public function testGetRejectsAnExplicitEmptyColumnList(): void
    {
        $this->builder->fields('id');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SELECT requires at least one column');
        $this->builder->get('users', null, []);
    }

    public function testGetColumn(): void
    {
        $this->builder->getColumn('get');
        $this->assertEquals('SELECT  * FROM `get`',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `get`',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->fields('testcolumn')->getColumn('get');
        $this->assertEquals('SELECT  testcolumn FROM `get`',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn FROM `get`',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->fields('testcolumn1, testcolumn2')->getColumn('get');
        $this->assertEquals('SELECT  testcolumn1 FROM `get`',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn1 FROM `get`',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->fields(['testcolumn1', 'testcolumn2'])->getColumn('get');
        $this->assertEquals('SELECT  testcolumn1 FROM `get`',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn1 FROM `get`',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->getColumn('get', 'testcolumn');
        $this->assertEquals('SELECT  testcolumn FROM `get`',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn FROM `get`',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->getColumn('get', 'testcolumn', 1);
        $this->assertEquals('SELECT  testcolumn FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->getColumn('get', 'testcolumn', [0, 10]);
        $this->assertEquals('SELECT  testcolumn FROM `get` LIMIT 0, 10',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn FROM `get` LIMIT 0, 10',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());
    }

    public function testGetScalar(): void
    {
        $this->builder->getScalar('get', 'testscalar');
        $this->assertEquals('SELECT  testscalar FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testscalar FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->fields('testscalar')->getScalar('get');
        $this->assertEquals('SELECT  testscalar FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testscalar FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->fields('testcolumn1, testcolumn2')->getScalar('get');
        $this->assertEquals('SELECT  testcolumn1 FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn1 FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->fields(['testcolumn1', 'testcolumn2'])->getScalar('get');
        $this->assertEquals('SELECT  testcolumn1 FROM `get` LIMIT 1',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  testcolumn1 FROM `get` LIMIT 1',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());
    }

    public function testWhereGet(): void
    {
        $this->builder->where('col1',2)->get('whereGet');
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  `col1` = ? ',$this->builder->getLastPrepareQuery());
        $this->assertEquals("SELECT  * FROM `whereGet` WHERE  `col1` = 2 ",$this->builder->getLastQuery());
        $this->assertEquals([2],$this->builder->getLastBindParams());

        $this->builder->where('col1',2,">")->get('whereGet');
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  `col1` > ? ',$this->builder->getLastPrepareQuery());
        $this->assertEquals("SELECT  * FROM `whereGet` WHERE  `col1` > 2 ",$this->builder->getLastQuery());
        $this->assertEquals([2],$this->builder->getLastBindParams());

        $this->builder->where('col1',2)->where('col2','str')->get('whereGet');
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  `col1` = ?  AND `col2` = ? ',$this->builder->getLastPrepareQuery());
        $this->assertEquals("SELECT  * FROM `whereGet` WHERE  `col1` = 2  AND `col2` = 'str' ",$this->builder->getLastQuery());
        $this->assertEquals([2,'str'],$this->builder->getLastBindParams());

        $this->builder->where('col3',[1,2,3],'IN')->get('whereGet');
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  `col3` IN ( ?, ?, ? ) ',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  `col3` IN ( 1, 2, 3 ) ',$this->builder->getLastQuery());
        $this->assertEquals([1,2,3],$this->builder->getLastBindParams());


        $this->builder->where("find_in_set(1, test)")->get('whereGet');
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  find_in_set(1, test)', $this->builder->getLastPrepareQuery());

        $this->builder->where("find_in_set(?, test)", [1])->get('whereGet');
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  find_in_set(1, test)', $this->builder->getLastQuery());

        $this->builder->where("(id = ? or id = ?)", [1,3])->get('whereGet');
        $this->assertEquals('SELECT  * FROM `whereGet` WHERE  (id = 1 or id = 3)', $this->builder->getLastQuery());
    }

    public function testJoinGet(): void
    {
        $this->builder->join('table2','table2.col1 = getTable.col2')->get('getTable');
        $this->assertEquals('SELECT  * FROM `getTable`  JOIN table2 on table2.col1 = getTable.col2',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `getTable`  JOIN table2 on table2.col1 = getTable.col2',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());

        $this->builder->join('table2','table2.col1 = getTable.col2','LEFT')->get('getTable');
        $this->assertEquals('SELECT  * FROM `getTable` LEFT JOIN table2 on table2.col1 = getTable.col2',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `getTable` LEFT JOIN table2 on table2.col1 = getTable.col2',$this->builder->getLastQuery());
        $this->assertEquals([],$this->builder->getLastBindParams());
    }

    public function testGroup(): void
    {
        $this->builder->groupBy("user_id")->get("test_table");
        $this->assertEquals('SELECT  * FROM `test_table` GROUP BY user_id ',$this->builder->getLastPrepareQuery());

        $this->builder->groupBy("FROM_UNIXTIME(create_time, '%Y%m')")->get("test_table");
        $this->assertEquals('SELECT  * FROM `test_table` GROUP BY FROM_UNIXTIME(create_time, \'%Y%m\') ',$this->builder->getLastPrepareQuery());


        $this->builder->fields([
            "DATE_FORMAT(create_time, '%Y%m') AS month",
            "sum(age)"
        ])->groupBy("month")->get("test_table");
        $this->assertEquals('SELECT  DATE_FORMAT(create_time, \'%Y%m\') AS month, sum(age) FROM `test_table` GROUP BY month ',$this->builder->getLastPrepareQuery());

    }

    public function testJoinWhereGet(): void
    {
        $this->builder->join('table2','table2.col1 = getTable.col2')->where('table2.col1',2)->get('getTable');
        $this->assertEquals('SELECT  * FROM `getTable`  JOIN table2 on table2.col1 = getTable.col2 WHERE  `table2`.`col1` = ? ',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `getTable`  JOIN table2 on table2.col1 = getTable.col2 WHERE  `table2`.`col1` = 2 ',$this->builder->getLastQuery());
        $this->assertEquals([2],$this->builder->getLastBindParams());
    }

    public function testUpdate(): void
    {
        $this->builder->update('updateTable', ['a' => 1]);
        $this->assertEquals('UPDATE `updateTable` SET `a` = ?', $this->builder->getLastPrepareQuery());
        $this->assertEquals('UPDATE `updateTable` SET `a` = 1', $this->builder->getLastQuery());
        $this->assertEquals([1], $this->builder->getLastBindParams());
    }

    public function testLimitUpdate(): void
    {
        $this->builder->update('updateTable', ['a' => 1], 5);
        $this->assertEquals('UPDATE `updateTable` SET `a` = ? LIMIT 5', $this->builder->getLastPrepareQuery());
        $this->assertEquals('UPDATE `updateTable` SET `a` = 1 LIMIT 5', $this->builder->getLastQuery());
        $this->assertEquals([1], $this->builder->getLastBindParams());
    }

    public function testWhereUpdate(): void
    {
        $this->builder->where('whereUpdate', 'whereValue')->update('updateTable', ['a' => 1]);
        $this->assertEquals('UPDATE `updateTable` SET `a` = ? WHERE  `whereUpdate` = ? ', $this->builder->getLastPrepareQuery());
        $this->assertEquals("UPDATE `updateTable` SET `a` = 1 WHERE  `whereUpdate` = 'whereValue' ", $this->builder->getLastQuery());
        $this->assertEquals([1, 'whereValue'], $this->builder->getLastBindParams());
    }

    public function testStatementSpecificClausesCannotLeakIntoOtherStatements(): void
    {
        $builder = $this->builder->raw('SELECT ?', [1]);
        $cases = [
            static fn(QueryBuilder $q) => $q->setQueryOption('FOR UPDATE')->update('users', ['name' => 'a']),
            static fn(QueryBuilder $q) => $q->forUpdate()->lockInShareMode()->get('users'),
            static fn(QueryBuilder $q) => $q->onDuplicate(['name' => 'a'])->get('users'),
            static fn(QueryBuilder $q) => $q->onDuplicate(['name' => 'a'])->replace('users', ['name' => 'b']),
            static fn(QueryBuilder $q) => $q->where('id', 1)->insert('users', ['name' => 'a']),
        ];
        foreach ($cases as $build) {
            try {
                $build($builder);
                self::fail('Expected an incompatible clause to be rejected');
            } catch (\InvalidArgumentException) {
                self::assertSame('SELECT ?', $builder->getLastPrepareQuery());
                self::assertSame([1], $builder->getLastBindParams());
                self::assertSame('SELECT 1', $builder->getLastQuery());
            }
            $builder->reset();
        }
    }

    public function testUpdateAndDeleteRejectUnsupportedOrderingAndLimits(): void
    {
        $cases = [
            [static fn(QueryBuilder $q) => $q->join('orders o', 'users.id=o.userId')->orderByAsc('users.id'), null, 'with JOIN'],
            [static fn(QueryBuilder $q) => $q->join('orders o', 'users.id=o.userId')->limit(0), null, 'with JOIN'],
            [static fn(QueryBuilder $q) => $q->limit(2, 5), null, 'row count only'],
            [static fn(QueryBuilder $q) => $q, [2, 5], 'row count only'],
        ];
        foreach ($cases as [$configure, $limit, $message]) {
            foreach (['update', 'delete'] as $statement) {
                $builder = $configure((new QueryBuilder())->raw('SELECT ?', [7])->where('users.id', 1));
                try {
                    if ($statement === 'update') {
                        $builder->update('users', ['name' => 'new'], $limit);
                    } else {
                        $builder->delete('users', $limit);
                    }
                    self::fail('Expected unsupported write syntax to be rejected');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString($message, $error->getMessage());
                    self::assertSame('SELECT ?', $builder->getLastPrepareQuery());
                    self::assertSame([7], $builder->getLastBindParams());
                }
            }
        }
    }

    public function testStatementModifiersAreValidatedAndRenderedForTheTargetStatement(): void
    {
        $builder = $this->builder->setQueryOption(['IGNORE', 'LOW_PRIORITY'])->update('users', ['name' => 'a']);
        self::assertSame('UPDATE LOW_PRIORITY IGNORE `users` SET `name` = ?', $builder->getLastPrepareQuery());
        self::assertSame(['a'], $builder->getLastBindParams());
        $builder->setQueryOption(['IGNORE', 'QUICK', 'LOW_PRIORITY'])->delete('users');
        self::assertSame('DELETE LOW_PRIORITY QUICK IGNORE FROM `users`', $builder->getLastPrepareQuery());

        foreach ([
            static fn(QueryBuilder $q) => $q->distinct()->insert('users', ['name' => 'a']),
            static fn(QueryBuilder $q) => $q->setQueryOption('LOW_PRIORITY')->get('users'),
            static fn(QueryBuilder $q) => $q->setQueryOption('IGNORE')->replace('users', ['name' => 'a']),
            static fn(QueryBuilder $q) => $q->setQueryOption(['HIGH_PRIORITY', 'LOW_PRIORITY'])->insert('users', ['name' => 'a']),
        ] as $build) {
            try {
                $build($builder);
                self::fail('Expected incompatible modifiers to be rejected');
            } catch (\InvalidArgumentException) {
                self::assertSame('DELETE LOW_PRIORITY QUICK IGNORE FROM `users`', $builder->getLastPrepareQuery());
            }
            $builder->reset();
        }
    }

    public function testTableLockUsesTheSameTableReferencesForOneOrManyTables(): void
    {
        $builder = $this->builder->setPrefix('app_');
        $builder->lockTable('orders');
        self::assertSame('LOCK TABLES `app_orders` READ', $builder->getLastPrepareQuery());

        $builder->lockTable([1 => 'orders', 'archive' => '`archive`.`orders`']);
        self::assertSame('LOCK TABLES `app_orders` READ, `archive`.`orders` READ', $builder->getLastPrepareQuery());
        self::assertSame(QueryBuilder::TS_OP_LOCK_TABLE, $builder->getLastTransactionOp());
        self::assertSame([], $builder->getLastBindParams());
    }

    public function testForUpdate(): void
    {
        $this->builder->selectForUpdate(true)->where('name',1)->get('test');
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = ?  FOR UPDATE', $this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = 1  FOR UPDATE', $this->builder->getLastQuery());

        $this->builder->selectForUpdate(true, 'NOWAIT')->where('name',1)->get('test');
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = ?  FOR UPDATE NOWAIT', $this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = 1  FOR UPDATE NOWAIT', $this->builder->getLastQuery());

        $this->builder->selectForUpdate(true, 'WAIT 5')->where('name',1)->get('test');
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = ?  FOR UPDATE WAIT 5', $this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = 1  FOR UPDATE WAIT 5', $this->builder->getLastQuery());

        $this->builder->selectForUpdate(true, 'SKIP LOCKED')->where('name',1)->get('test');
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = ?  FOR UPDATE SKIP LOCKED', $this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `test` WHERE  `name` = 1  FOR UPDATE SKIP LOCKED', $this->builder->getLastQuery());
    }


    public function testDelete(): void
    {
        $this->builder->delete('deleteTable');
        $this->assertEquals('DELETE FROM `deleteTable`', $this->builder->getLastPrepareQuery());
        $this->assertEquals('DELETE FROM `deleteTable`', $this->builder->getLastQuery());
        $this->assertEquals([], $this->builder->getLastBindParams());
    }

    public function testLimitDelete(): void
    {
        $this->builder->delete('deleteTable', 1);
        $this->assertEquals('DELETE FROM `deleteTable` LIMIT 1', $this->builder->getLastPrepareQuery());
        $this->assertEquals('DELETE FROM `deleteTable` LIMIT 1', $this->builder->getLastQuery());
        $this->assertEquals([], $this->builder->getLastBindParams());
    }

    public function testWhereDelete(): void
    {
        $this->builder->where('whereDelete', 'whereValue')->delete('deleteTable');
        $this->assertEquals('DELETE FROM `deleteTable` WHERE  `whereDelete` = ? ', $this->builder->getLastPrepareQuery());
        $this->assertEquals("DELETE FROM `deleteTable` WHERE  `whereDelete` = 'whereValue' ", $this->builder->getLastQuery());
        $this->assertEquals(['whereValue'], $this->builder->getLastBindParams());
    }

    public function testInsert(): void
    {
        $this->builder->insert('insertTable', ['a' => 1, 'b' => "b"]);
        $this->assertEquals('INSERT  INTO `insertTable` (`a`, `b`)  VALUES (?, ?)', $this->builder->getLastPrepareQuery());
        $this->assertEquals("INSERT  INTO `insertTable` (`a`, `b`)  VALUES (1, 'b')", $this->builder->getLastQuery());
        $this->assertEquals([1,'b'], $this->builder->getLastBindParams());

        $this->builder->insert('insertTable', ['a' => "Don't worry"]);
        $this->assertEquals('INSERT  INTO `insertTable` (`a`)  VALUES (?)', $this->builder->getLastPrepareQuery());
        $this->assertEquals("INSERT  INTO `insertTable` (`a`)  VALUES ('Don\'t worry')", $this->builder->getLastQuery());
        $this->assertEquals(["Don't worry"], $this->builder->getLastBindParams());
    }

    public function testInsertAll(): void
    {
        $this->builder->insertAll('insertTable', [
            ['a' => 1, 'b' => "a"],
            ['a' => 2, 'b' => "b"],
        ]);
        $this->assertEquals('INSERT  INTO `insertTable` (`a`, `b`)  VALUES (?, ?),(?, ?)', $this->builder->getLastPrepareQuery());
        $this->assertEquals("INSERT  INTO `insertTable` (`a`, `b`)  VALUES (1, 'a'),(2, 'b')", $this->builder->getLastQuery());
    }

    public function testSubQuery(): void
    {
        $sub = $this->builder::subQuery();
        $sub->where ("qty", 2, ">");
        $sub->get ("products", null, "userId");
        $this->builder->where ("id", $sub, 'in')->get('users');
        $this->assertEquals('SELECT  * FROM `users` WHERE  `id` in (  (SELECT  userId FROM `products` WHERE  `qty` > ? )  ) ',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `users` WHERE  `id` in (  (SELECT  userId FROM `products` WHERE  `qty` > 2 )  ) ',$this->builder->getLastQuery());
        $this->assertEquals([2],$this->builder->getLastBindParams());


        $sub = $this->builder::subQuery();
        $sub->where ("qty", 2, ">");
        $sub->get ("products", null, "userId");
        $this->builder->where('col2',1)->where ("id", $sub, 'in')->get('users');
        $this->assertEquals('SELECT  * FROM `users` WHERE  `col2` = ?  AND `id` in (  (SELECT  userId FROM `products` WHERE  `qty` > ? )  ) ',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  * FROM `users` WHERE  `col2` = 1  AND `id` in (  (SELECT  userId FROM `products` WHERE  `qty` > 2 )  ) ',$this->builder->getLastQuery());
        $this->assertEquals([1,2],$this->builder->getLastBindParams());

        $userIdQ = $this->builder::subQuery();
        $userIdQ->where ("id", 6);
        $userIdQ->getOne ("users", "name");

        $data = Array (
            "productName" => "test product",
            "userId" => $userIdQ,
            "lastUpdated" => $this->builder->now()
        );
        $this->builder->insert ("products", $data);
        $this->assertEquals('INSERT  INTO `products` (`productName`, `userId`, `lastUpdated`)  VALUES (?,   (SELECT  name FROM `users` WHERE  `id` = ?  LIMIT 1) , NOW())',$this->builder->getLastPrepareQuery());
        $this->assertEquals("INSERT  INTO `products` (`productName`, `userId`, `lastUpdated`)  VALUES ('test product',   (SELECT  name FROM `users` WHERE  `id` = 6  LIMIT 1) , NOW())",$this->builder->getLastQuery());
        $this->assertEquals(["test product",6],$this->builder->getLastBindParams());


        $usersQ = $this->builder::subQuery ("u");
        $usersQ->where ("active", 1);
        $usersQ->get ("users");

        $this->builder->join($usersQ, "p.userId=u.id", "LEFT");
        $this->builder->get ("products p", null, "u.login, p.productName");
        $this->assertEquals('SELECT  u.login, p.productName FROM products p LEFT JOIN   (SELECT  * FROM `users` WHERE  `active` = ? ) `u` on p.userId=u.id',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  u.login, p.productName FROM products p LEFT JOIN   (SELECT  * FROM `users` WHERE  `active` = 1 ) `u` on p.userId=u.id',$this->builder->getLastQuery());
        $this->assertEquals([1],$this->builder->getLastBindParams());

    }

    public function testForceIndexIsIsolatedBetweenOuterAndInSubquery(): void
    {
        $sub = QueryBuilder::subQuery()->forceIndex('idx_qty')
            ->where('qty', 2, '>')->get('products', null, 'userId');
        $this->builder->forceIndex('PRIMARY')->where('active', 1)
            ->where('id', $sub, 'in')->get('users');
        self::assertSame(
            'SELECT  * FROM `users` FORCE INDEX (`PRIMARY`) WHERE  `active` = ?  AND `id` in (  (SELECT  userId FROM `products` FORCE INDEX (`idx_qty`) WHERE  `qty` > ? )  ) ',
            $this->builder->getLastPrepareQuery()
        );
        self::assertSame([1, 2], $this->builder->getLastBindParams());
        // 复用外层构造器时，保留已构建的子查询，仅清除外层索引提示。
        $this->builder->where('id', $sub, 'in')->get('users');
        self::assertStringNotContainsString('FORCE INDEX (`PRIMARY`)', $this->builder->getLastPrepareQuery());
        self::assertStringContainsString('FORCE INDEX (`idx_qty`)', $this->builder->getLastPrepareQuery());
        self::assertSame([2], $this->builder->getLastBindParams());
        $sub->get('products');
        self::assertSame('SELECT  * FROM `products`', $sub->getLastPrepareQuery());
    }

    public function testForceIndexInJoinedSubqueryKeepsAliasAndParameterOrder(): void
    {
        $sub = QueryBuilder::subQuery('u')->forceIndex('idx_active')
            ->where('active', 1)->get('users');
        $this->builder->forceIndex('idx_userId')->join($sub, 'p.userId=u.id', 'LEFT')
            ->where('p.status', 2)->get('products p');
        self::assertSame(
            'SELECT  * FROM products p FORCE INDEX (`idx_userId`) LEFT JOIN   (SELECT  * FROM `users` FORCE INDEX (`idx_active`) WHERE  `active` = ? ) `u` on p.userId=u.id WHERE  `p`.`status` = ? ',
            $this->builder->getLastPrepareQuery()
        );
        self::assertSame([1, 2], $this->builder->getLastBindParams());
    }

    public function testForceIndexInScalarSubqueryDoesNotAffectInsert(): void
    {
        $sub = QueryBuilder::subQuery()->forceIndex('PRIMARY')->where('id', 6)->getOne('users', 'name');
        $this->builder->insert('products', ['productName' => 'test', 'userId' => $sub]);
        self::assertSame(
            'INSERT  INTO `products` (`productName`, `userId`)  VALUES (?,   (SELECT  name FROM `users` FORCE INDEX (`PRIMARY`) WHERE  `id` = ?  LIMIT 1))',
            $this->builder->getLastPrepareQuery()
        );
        self::assertSame(['test', 6], $this->builder->getLastBindParams());
    }

    public function testForceIndexIsLocalToEachUnionBranch(): void
    {
        $branch = (new QueryBuilder())->forceIndex('idx_user')->where('userName', 'user')->get('users');
        $this->builder->forceIndex('idx_admin')->union($branch)
            ->where('adminUserName', 'admin')->get('admins');
        self::assertSame(
            'SELECT  * FROM `admins` FORCE INDEX (`idx_admin`) WHERE  `adminUserName` = ?  UNION SELECT  * FROM `users` FORCE INDEX (`idx_user`) WHERE  `userName` = ? ',
            $this->builder->getLastPrepareQuery()
        );
        self::assertSame(['admin', 'user'], $this->builder->getLastBindParams());
    }

    public function testUnion(): void
    {
        $this->builder->union((new QueryBuilder)->where('userName', 'user')->get('user'))->where('adminUserName', 'admin')->get('admin');
        $this->assertEquals('SELECT  * FROM `admin` WHERE  `adminUserName` = ?  UNION SELECT  * FROM `user` WHERE  `userName` = ? ', $this->builder->getLastPrepareQuery());
        $this->assertEquals("SELECT  * FROM `admin` WHERE  `adminUserName` = 'admin'  UNION SELECT  * FROM `user` WHERE  `userName` = 'user' ", $this->builder->getLastQuery());
        $this->assertEquals(['admin','user'],$this->builder->getLastBindParams());
    }


    public function testRaw(): void
    {
        $this->builder->raw("SELECT * FROM `siam` ");
        $this->assertEquals("SELECT * FROM `siam` ", $this->builder->getLastQuery());


        $this->builder->raw("SELECT * FROM `siam` WHERE `siam_name` = ? AND `age` = ?", ['siam', 321]);
        $this->assertEquals("SELECT * FROM `siam` WHERE `siam_name` = 'siam' AND `age` = 321", $this->builder->getLastQuery());
    }
}
