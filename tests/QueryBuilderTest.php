<?php


namespace EasySwoole\Mysqli\Tests;


use EasySwoole\Mysqli\QueryBuilder;
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

    /**
     * @throws \Exception
     */
    public function testLockWhereLimitUpdate(): void
    {
        $this->builder->setQueryOption("FOR UPDATE")->where('whereUpdate', 'whereValue')->update('updateTable', ['a' => 1], 2);
        $this->assertEquals('UPDATE `updateTable` SET `a` = ? WHERE  `whereUpdate` = ?  LIMIT 2 FOR UPDATE', $this->builder->getLastPrepareQuery());
        $this->assertEquals("UPDATE `updateTable` SET `a` = 1 WHERE  `whereUpdate` = 'whereValue'  LIMIT 2 FOR UPDATE", $this->builder->getLastQuery());
        $this->assertEquals([1, 'whereValue'], $this->builder->getLastBindParams());
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
        $this->assertEquals('SELECT  u.login, p.productName FROM products p LEFT JOIN   (SELECT  * FROM `users` WHERE  `active` = ? ) u on p.userId=u.id',$this->builder->getLastPrepareQuery());
        $this->assertEquals('SELECT  u.login, p.productName FROM products p LEFT JOIN   (SELECT  * FROM `users` WHERE  `active` = 1 ) u on p.userId=u.id',$this->builder->getLastQuery());
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
        // Reusing the outer builder preserves the completed subquery and clears only the outer hint.
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
            'SELECT  * FROM products p FORCE INDEX (`idx_userId`) LEFT JOIN   (SELECT  * FROM `users` FORCE INDEX (`idx_active`) WHERE  `active` = ? ) u on p.userId=u.id WHERE  `p`.`status` = ? ',
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
