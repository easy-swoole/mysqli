<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, Expression, QueryBuilder};
use EasySwoole\Mysqli\Exception\TransactionLostException;
use PHPUnit\Framework\TestCase;

final class QueryBuilderFixesIntegrationTest extends TestCase
{
    private ?Client $client = null;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') { self::markTestSkipped('Set MYSQLI_TEST_* for live checks'); }
        $this->client = new Client(new Config(MYSQL_CONFIG));
        $this->client->connect();
        $this->client->rawQuery("SET SESSION sql_mode='STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,ANSI_QUOTES,NO_BACKSLASH_ESCAPES'");
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_fix_orders (
            id INT PRIMARY KEY, tenantId INT NOT NULL, name VARCHAR(100) NULL,
            status VARCHAR(100) NOT NULL, score INT NOT NULL) ENGINE=InnoDB');
        $this->client->query((new QueryBuilder())->insertAll('qb_fix_orders', [
            ['id'=>1,'tenantId'=>1,'name'=>'Alice','status'=>'paid+','score'=>10],
            ['id'=>2,'tenantId'=>1,'name'=>'Bob','status'=>'a/b','score'=>20],
            ['id'=>3,'tenantId'=>2,'name'=>null,'status'=>"O'Reilly",'score'=>30],
            ['id'=>4,'tenantId'=>2,'name'=>'Dana','status'=>'中文','score'=>40],
        ]));
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_fix_users (id INT PRIMARY KEY, tenantId INT NOT NULL) ENGINE=InnoDB');
        $this->client->rawQuery('INSERT INTO qb_fix_users VALUES (1,1),(2,1),(3,2)');
    }

    protected function tearDown(): void { $this->client?->close(); }

    public function testHavingBoundsAndWriteExpressionsIgnoreArrayPointers(): void
    {
        $increment = QueryBuilder::inc(5);
        $status = QueryBuilder::func('CONCAT(status, ?)', ['!']);
        next($increment);
        next($status);
        $update = (new QueryBuilder())->where('id', 2)
            ->update('qb_fix_orders', ['score' => $increment, 'status' => $status]);
        self::assertSame(['!', 2], $update->getLastBindParams());
        self::assertTrue($this->client->query($update));
        self::assertSame([['score' => 25, 'status' => 'a/b!']],
            $this->client->rawQuery('SELECT score, status FROM qb_fix_orders WHERE id = 2'));

        $bounds = [35, 40];
        next($bounds);
        $query = (new QueryBuilder())->join('qb_fix_users u', 'o.id=u.id')
            ->joinWhere('qb_fix_users u', 'u.tenantId', [1, 2], ' In ')
            ->where('o.id', [1, 2, 3], ' in ')
            ->groupBy('o.tenantId')->having('SUM(o.score)', $bounds, ' BeTwEeN ')
            ->get('qb_fix_orders o', null, 'o.tenantId');
        self::assertSame([1, 2, 1, 2, 3, 35, 40], $query->getLastBindParams());
        self::assertStringContainsString(' BeTwEeN ', $query->getLastPrepareQuery());
        self::assertSame([['tenantId' => 1]], $this->client->query($query));
    }

    public function testCustomFieldOrderingPreservesPunctuationAndWorksWithAnsiQuotes(): void
    {
        $builder = (new QueryBuilder())->orderBy('status', 'ASC', ['paid+', 'a/b', "O'Reilly", '中文'])
            ->get('qb_fix_orders', null, 'id');
        self::assertSame([1,2,3,4], array_column($this->client->query($builder), 'id'));
        self::assertSame(['paid+', 'a/b', "O'Reilly", '中文'], $builder->getLastBindParams());
    }

    public function testRandomOrderKeepsDirectionAndDebugSqlPreservesDoubleValues(): void
    {
        $builder = (new QueryBuilder())->orderBy('RAND()', 'DESC')->get('qb_fix_orders', 2, 'id');
        self::assertStringContainsString('ORDER BY RAND() DESC', $builder->getLastPrepareQuery());
        self::assertCount(2, $this->client->query($builder));

        $value = 1.2345678901234567;
        $builder->raw('SELECT ? AS value', [$value]);
        self::assertSame([['value' => $value]], $this->client->query($builder));
        self::assertSame([['value' => $value]], $this->client->rawQuery($builder->getLastQuery()));
    }

    public function testGetColumnPreservesCompleteFunctionAndAlias(): void
    {
        $builder = (new QueryBuilder())->fields(["CONCAT(COALESCE(name, 'guest'), ', ', status) AS display_name"])
            ->where('id', 3)->getColumn('qb_fix_orders');
        self::assertSame([['display_name'=>"guest, O'Reilly"]], $this->client->query($builder));
    }

    public function testAnsiQuotesDebugSqlUsesIdentifierRulesAndDefaultStringEscapes(): void
    {
        $this->client->rawQuery("SET SESSION sql_mode = 'ANSI_QUOTES'");
        $alias = 'label?' . chr(92);
        $sql = 'SELECT 1 AS "' . $alias . '", ? AS answer';
        $builder = (new QueryBuilder())->ansiQuotes()->raw($sql, [42]);
        self::assertSame([[$alias => 1, 'answer' => 42]], $this->client->query($builder));
        self::assertSame('SELECT 1 AS "' . $alias . '", 42 AS answer', $builder->getLastQuery());
    }

    public function testGetColumnUsesExplicitNoBackslashEscapesMode(): void
    {
        $fields = <<<'SQL'
CONCAT('x\', 'y') AS label, id
SQL;
        $builder = (new QueryBuilder())->noBackslashEscapes()->fields($fields)->where('id', 1)->getColumn('qb_fix_orders');
        self::assertSame([['label' => 'x\\y']], $this->client->query($builder));
        $expression = <<<'SQL'
CONCAT('x\', 'y')
SQL;
        $builder->fields([$expression . ' AS label', 'tenantId'])->groupBy($expression . ', tenantId')->count('qb_fix_orders');
        self::assertSame([['aggregate' => 2]], $this->client->query($builder));
    }

    public function testQuotedColumnsAndPrefixedTablesWorkAcrossLikeSelectAndUpdate(): void
    {
        $builder = (new QueryBuilder())->whereLike('`o`.`name`', 'Ali%')->get('qb_fix_orders o', null, 'o.id');
        self::assertSame([['id'=>1]], $this->client->query($builder));
        $builder->where('o.id',1)->update('`qb_fix_orders` o', ['`o`.`name`'=>'Alicia']);
        self::assertTrue($this->client->query($builder));
        $builder->setPrefix('qb_fix_')->where('id',1)->get('`orders`',null,'name');
        self::assertSame([['name'=>'Alicia']],$this->client->query($builder));
        $database=MYSQL_CONFIG['database'];
        $builder=(new QueryBuilder())->where('id',2)->update($database.'.qb_fix_orders', [$database.'.qb_fix_orders.name'=>'Bobby']);
        self::assertTrue($this->client->query($builder));
        self::assertSame([['name'=>'Bobby']],$this->client->query((new QueryBuilder())->where('id',2)->get('qb_fix_orders',null,'name')));
    }

    public function testCountsHandlePlainJoinDistinctGroupsAndUnion(): void
    {
        $builder=(new QueryBuilder())->join('qb_fix_users u','u.tenantId=o.tenantId','INNER')->count('qb_fix_orders o');
        self::assertSame([['aggregate'=>6]],$this->client->query($builder));
        $builder->fields(['tenantId'])->distinct()->orderByDesc('tenantId')->limit(1)->count('qb_fix_orders');
        self::assertSame([['aggregate'=>2]],$this->client->query($builder));
        $builder->fields(['tenantId'])->groupBy('tenantId')->having('SUM(score)',40,'>')->count('qb_fix_orders');
        self::assertSame([['aggregate'=>1]],$this->client->query($builder));
        $builder->groupBy('1')->count('qb_fix_orders');
        self::assertSame([['aggregate'=>4]],$this->client->query($builder));
        foreach ([false=>2,true=>3] as $all=>$expected) {
            $branch=(new QueryBuilder())->selectRaw('? AS tenantId',[2])->get('(SELECT 1) seed');
            $builder=(new QueryBuilder())->fields(['tenantId'])->where('id',2,'<=')->union($branch,(bool)$all)
                ->orderByDesc('tenantId')->limit(1)->count('qb_fix_orders');
            self::assertSame([['aggregate'=>$expected]],$this->client->query($builder));
        }
    }

    public function testMysqlRejectsMixedIndexHintsInEitherCallOrder(): void
    {
        foreach ([true, false] as $forceFirst) {
            $builder = new QueryBuilder();
            if ($forceFirst) {
                $builder->forceIndex('PRIMARY')->useIndex('PRIMARY');
            } else {
                $builder->useIndex('PRIMARY')->forceIndex('PRIMARY');
            }
            $builder->get('qb_fix_orders', null, 'id');
            self::assertStringContainsString('FORCE INDEX (`PRIMARY`) USE INDEX (`PRIMARY`)', $builder->getLastPrepareQuery());
            try {
                $this->client->query($builder);
                self::fail('Expected MySQL to reject mixed index hints');
            } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
                self::assertSame(1221, $error->getCode());
            }
        }
    }

    public function testDuplicateUpdateKeepsTheConfiguredSubquerySnapshot(): void
    {
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_fix_rates (id INT PRIMARY KEY, score INT)');
        $this->client->rawQuery('INSERT INTO qb_fix_rates VALUES (1, 51), (2, 72)');
        $rate = QueryBuilder::subQuery()->where('id', 1)->get('qb_fix_rates', null, 'score');
        $builder = (new QueryBuilder())->onDuplicate(['score' => $rate, 'status' => 'adjusted']);
        $rate->where('id', 2)->get('qb_fix_rates', null, 'score');
        $builder->insert('qb_fix_orders', [
            'id' => 1, 'tenantId' => 1, 'name' => 'Alice', 'status' => 'paid', 'score' => 0,
        ]);

        self::assertSame([1, 1, 'Alice', 'paid', 0, 1, 'adjusted'], $builder->getLastBindParams());
        self::assertTrue($this->client->query($builder));
        self::assertSame([['score' => 51, 'status' => 'adjusted']],
            $this->client->rawQuery('SELECT score, status FROM qb_fix_orders WHERE id = 1'));
    }

    public function testUpsertUsesOverriddenDuplicateAndBatchInsertMethods(): void
    {
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_fix_audit_scores (id INT PRIMARY KEY, score INT)');
        $this->client->rawQuery('INSERT INTO qb_fix_audit_scores VALUES (1, 51), (2, 72)');
        $this->client->rawQuery('ALTER TABLE qb_fix_orders ADD COLUMN displayName VARCHAR(100) NULL, ADD COLUMN `État` VARCHAR(20) NULL');
        $score = QueryBuilder::subQuery()->where('id', 1)->get('qb_fix_audit_scores', null, 'score');
        $builder = new class($score) extends QueryBuilder {
            public array $calls = [];

            public function __construct(private readonly QueryBuilder $score) {}

            public function onDuplicate(mixed $updateColumns): QueryBuilder
            {
                $this->calls[] = 'onDuplicate';
                return parent::onDuplicate($updateColumns + ['score' => $this->score]);
            }

            public function insertAll(mixed $tableName, mixed $insertData, mixed $option = []): QueryBuilder
            {
                $this->calls[] = 'insertAll';
                $this->score->where('id', 2)->get('qb_fix_audit_scores', null, 'score');
                return parent::insertAll($tableName, $insertData, $option);
            }
        };
        $builder->upsert('qb_fix_orders', [
            ['id' => 1, 'tenantId' => 1, 'name' => 'Alice', 'status' => 'paid', 'score' => 0, 'displayName' => 'new display name', 'État' => 'open'],
        ], ['status' => 'adjusted', 'displayname', 'état']);

        self::assertSame(['onDuplicate', 'insertAll'], $builder->calls);
        self::assertSame([1, 1, 'Alice', 'paid', 0, 'new display name', 'open', 'adjusted', 1], $builder->getLastBindParams());
        self::assertTrue($this->client->query($builder));
        self::assertSame([['score' => 51, 'status' => 'adjusted', 'displayName' => 'new display name', 'État' => 'open']],
            $this->client->rawQuery('SELECT score, status, displayName, `État` FROM qb_fix_orders WHERE id = 1'));
    }

    public function testMysqlRejectsAnImplicitUpdateColumnAbsentFromTheInsert(): void
    {
        $builder = (new QueryBuilder())->upsert('qb_fix_orders', [
            ['id' => 1, 'tenantId' => 1, 'name' => 'Alice', 'status' => 'paid', 'score' => 0],
        ], ['missing']);
        $this->expectException(\EasySwoole\Mysqli\Exception\Exception::class);
        $this->expectExceptionCode(1054);
        $this->client->query($builder);
    }

    public function testDistinctRollupCountKeepsTheOriginalProjection(): void
    {
        $this->client->rawQuery("SET SESSION sql_mode = ''");
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_fix_rollup (region INT NULL, id INT NOT NULL)');
        $this->client->rawQuery('INSERT INTO qb_fix_rollup VALUES (NULL,1),(2,2)');
        $template = (new QueryBuilder())->distinct()->groupBy('region WITH ROLLUP');
        $rows = $this->client->query((clone $template)->get('qb_fix_rollup'));
        self::assertCount(3, $rows);
        self::assertSame([['aggregate' => count($rows)]], $this->client->query($template->count('qb_fix_rollup')));
    }

    public function testCountAndPaginationPreserveExplicitAliasesForJoinColumns(): void
    {
        $table = 'qb_fix_orders o';
        $template = (new QueryBuilder())->join('qb_fix_users u', 'u.tenantId=o.tenantId')
            ->fields(['o.id AS order_id', 'u.id AS user_id'])->selectRaw('? AS marker', [42])->where('o.tenantId', 1);
        $count = (clone $template)->count($table);
        self::assertSame([42, 1], $count->getLastBindParams());
        self::assertSame([['aggregate' => 4]], $this->client->query($count));
        $page = $this->client->paginate($template->orderByAsc('o.id')->orderByAsc('u.id'), $table, 2);
        self::assertSame(4, $page['total']);
        self::assertCount(2, $page['items']);
        self::assertSame(['order_id' => 1, 'user_id' => 1, 'marker' => 42], $page['items'][0]);

        $distinct = (new QueryBuilder())->join('qb_fix_users u', 'u.tenantId=o.tenantId')
            ->fields(['o.tenantId AS order_tenant', 'u.tenantId AS user_tenant'])->distinct()->count($table);
        self::assertSame([['aggregate' => 2]], $this->client->query($distinct));
    }

    public function testGroupedHavingCanUseColumnsFromAnExplicitStarProjection(): void
    {
        $this->client->rawQuery("SET SESSION sql_mode = ''");
        foreach ([false, true] as $explicitStar) {
            $template = (new QueryBuilder())->groupBy('tenantId')->having('id', 0, '>');
            if ($explicitStar) {
                $template->fields('*');
            }
            $rows = $this->client->query((clone $template)->get('qb_fix_orders'));
            self::assertCount(2, $rows);
            self::assertSame([['aggregate' => count($rows)]], $this->client->query($template->count('qb_fix_orders')));
        }
    }

    public function testGroupedCountWithRawAggregateHavingAndExplicitJoinColumns(): void
    {
        $table = 'qb_fix_orders o';
        foreach ([
            ['SUM(o.score) > ?', [50]],
            ['SUM(o.score) > 50', []],
            ['(SUM(o.score) > ?)', [50]],
            ['(( SUM(o.score) > 50 ))', []],
            ['SUM(COALESCE(o.score, 0)) > ?', [50]],
            ['SUM(o.score) > ? AND COUNT(*) > ?', [50, 0]],
            ['SUM(o.score) BETWEEN ? AND ?', [50, 150]],
            ['SUM(o.score) IN (?, ?)', [60, 70]],
            ['COALESCE(SUM(o.score), 0) > ?', [50]],
            ['COALESCE(SUM(o.score), 0) NOT BETWEEN ? AND ? OR COUNT(*) IN (?, ?)', [0, 50, 1, 2]],
            ["SUM(COALESCE(NULLIF(o.score, LENGTH(')')), 0)) > ? AND (COUNT(*) > ? OR FALSE)", [50, 0]],
        ] as [$having, $parameters]) {
            $builder = (new QueryBuilder())->join('qb_fix_users u', 'u.tenantId=o.tenantId')
                ->fields(['o.tenantId AS order_tenant', 'u.tenantId AS user_tenant'])
                ->groupBy('o.tenantId, u.tenantId')->havingRaw($having, $parameters);
            self::assertCount(2, $this->client->query((clone $builder)->get($table)), $having);
            self::assertSame([['aggregate' => 2]], $this->client->query((clone $builder)->count($table)), $having);
            $page = $this->client->paginate($builder->orderByAsc('o.tenantId'), $table, 1);
            self::assertSame(2, $page['total']);
            self::assertCount(1, $page['items']);
        }
    }

    public function testCountPreservesPositionalGroupingAcrossEquivalentSyntax(): void
    {
        $this->client->rawQuery("SET SESSION sql_mode = ''");
        foreach (['1', '(1)', '+1', '(+ (1))'] as $position) {
            $template = (new QueryBuilder())->groupBy($position);
            $rows = $this->client->query((clone $template)->get('qb_fix_orders'));
            self::assertCount(4, $rows);
            self::assertSame([['aggregate' => count($rows)]], $this->client->query($template->count('qb_fix_orders')));
        }
    }

    public function testCountAndPaginationPreserveDistinctProjectionAliases(): void
    {
        $table = 'qb_fix_orders';
        $template = (new QueryBuilder())->fields(['tenantId', 'tenantId AS tenant_copy'])->distinct();
        $rows = $this->client->query((clone $template)->get($table));
        self::assertCount(2, $rows);
        self::assertSame([['aggregate' => 2]], $this->client->query((clone $template)->count($table)));
        $page = $this->client->paginate($template->orderByAsc('tenantId'), $table, 1);
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['items']);
    }

    public function testCountUsesTheBuilderReturnedByTheInnerGet(): void
    {
        $template = new class extends QueryBuilder {
            public static ?self $inner = null;
            public static array $resets = [];

            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                $query = clone $this;
                if ($numRows === null && $this->getField() === ['o.id AS order_id, u.id AS user_id']) {
                    self::$inner ??= $query;
                }
                return $query->where('o.tenantId', 1)->build($tableName, $numRows, $columns);
            }

            public function build(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                return parent::get($tableName, $numRows, $columns);
            }

            public function reset(): QueryBuilder
            {
                self::$resets[] = $this;
                return parent::reset();
            }
        };
        $table = 'qb_fix_orders o';
        $template->fields('o.id AS order_id, u.id AS user_id')->join('qb_fix_users u', 'u.tenantId = o.tenantId');
        $count = (clone $template)->count($table);
        self::assertSame([1], $count->getLastBindParams());
        self::assertSame([['aggregate' => 4]], $this->client->query($count));
        $template::$inner = null;
        try {
            $page = $this->client->paginate($template->orderByAsc('o.id'), $table, 1);
            self::assertSame(4, $page['total']);
            self::assertCount(1, $page['items']);
            $inner = $template::$inner;
            self::assertNotNull($inner);
            self::assertNotContains($inner, $template::$resets);
            $inner->build($table, 1, 'o.id');
            self::assertSame($inner, $template::$resets[array_key_last($template::$resets)]);
            self::assertSame([['id' => 1]], $this->client->query($inner->orderByAsc('o.id')->build($table, 1, 'o.id')));
        } finally {
            $template::$inner = null;
            $template::$resets = [];
        }
    }

    public function testCountUsesOverriddenSqlGetterWhenItTrimsWhitespace(): void
    {
        $builder = new class extends QueryBuilder {
            public function getLastPrepareQuery(): ?string
            {
                $sql = parent::getLastPrepareQuery();
                return $sql === null ? null : trim($sql);
            }
        };
        $builder->fields(['o.id AS order_id', 'u.id AS user_id'])->join('qb_fix_users u', 'u.tenantId = o.tenantId')
            ->where('o.tenantId', 1)->count('qb_fix_orders o');
        self::assertSame([1], $builder->getLastBindParams());
        self::assertSame([['aggregate' => 4]], $this->client->query($builder));
    }

    public function testCountUsesTheProjectionReturnedByAnOverriddenSqlGetter(): void
    {
        $builder = new class extends QueryBuilder {
            public function getLastPrepareQuery(): ?string
            {
                $sql = parent::getLastPrepareQuery();
                return $sql !== null && str_starts_with($sql, 'SELECT  ')
                    ? "SELECT source.order_id FROM ($sql) AS source"
                    : $sql;
            }
        };
        $builder->fields(['o.id AS order_id', 'u.id AS user_id'])
            ->join('qb_fix_users u', 'u.tenantId = o.tenantId')->where('o.tenantId', 1)->count('qb_fix_orders o');
        self::assertSame([1], $builder->getLastBindParams());
        self::assertSame([['aggregate' => 4]], $this->client->query($builder));
    }

    public function testCountDebugSqlUsesTheQuoteModeFromItsInnerSelect(): void
    {
        $this->client->rawQuery("SET SESSION sql_mode = 'ANSI_QUOTES'");
        $builder = new class extends QueryBuilder {
            public array $diagnostics = [];

            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                $this->ansiQuotes()->selectRaw('1 AS "label?' . chr(92) . '"')->where('id', 2);
                return parent::get($tableName, $numRows, $columns);
            }

            public function raw(mixed $sql, mixed $param = []): QueryBuilder
            {
                parent::raw('SELECT ? AS diagnostic', [5]);
                $this->diagnostics[] = $this->getLastQuery();
                return parent::raw(str_replace('SELECT COUNT(*)', 'SELECT  COUNT(*)', $sql) . ';', $param);
            }
        };
        $builder->count('qb_fix_orders');
        self::assertSame([['aggregate' => 1]], $this->client->query($builder));
        self::assertStringContainsString('`id` = 2', $builder->getLastQuery());
        self::assertStringContainsString('"label?' . chr(92) . '"', $builder->getLastQuery());
        self::assertStringEndsWith(';', $builder->getLastQuery());
        self::assertSame(['SELECT 5 AS diagnostic'], $builder->diagnostics);
    }

    public function testCountAndPaginationUseTheDefaultProjectionChosenByGetOverride(): void
    {
        $builder = new class extends QueryBuilder {
            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                if ($columns === null && $this->getField() === null) {
                    $this->selectRaw('SUM(score + ?) AS score', [1]);
                }
                return parent::get($tableName, $numRows, $columns);
            }
        };
        $table = 'qb_fix_orders';
        $builder->where('tenantId', 1);
        $count = (clone $builder)->count($table);
        self::assertSame([1, 1], $count->getLastBindParams());
        self::assertSame([['aggregate' => 1]], $this->client->query($count));
        $page = $this->client->paginate($builder, $table, 1);
        self::assertSame(1, $page['total']);
        self::assertSame(1, $page['lastPage']);
        self::assertSame([['score' => '32']], $page['items']);
        self::assertNull($builder->getField());
        self::assertNull($builder->getLastPrepareQuery());
    }

    public function testPaginationCountsOnlyTheLatestOrdersSelectedByTheSubquery(): void
    {
        $builder = new class extends QueryBuilder {
            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                $latest = (clone $this)->orderByDesc('id')->latestOrders($tableName);
                $this->reorder()->orderByAsc('s.id')->fromSub($latest, 's');
                return parent::get(null, $numRows, ['s.id']);
            }

            private function latestOrders(mixed $tableName): QueryBuilder
            {
                return parent::get($tableName, 2);
            }
        };
        $builder->where('score', 10, '>=');
        $count = (clone $builder)->count('qb_fix_orders');
        self::assertMatchesRegularExpression('/SELECT\s+\*\s+FROM.*ORDER BY id DESC\s+LIMIT 2\)/', $count->getLastPrepareQuery());
        self::assertSame([10, 10], $count->getLastBindParams());
        self::assertSame([['aggregate' => 2]], $this->client->query($count));
        $page = $this->client->paginate($builder, 'qb_fix_orders', 1, 2);
        self::assertSame(2, $page['total']);
        self::assertSame(2, $page['lastPage']);
        self::assertSame([['id' => 4]], $page['items']);
    }

    public function testCountAndPaginationKeepBindingsInOrderAcrossLimitedOrderSources(): void
    {
        $template = new class extends QueryBuilder {
            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                $this->orderByRaw('FIELD(id, ?, ?) DESC', [3, 2]);
                return parent::get($tableName, $numRows, $columns);
            }
        };
        $latest = (new QueryBuilder())->where('tenantId', 1)->orderByDesc('id')->get('qb_fix_orders', 1, ['id', 'score']);
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_fix_archived_orders LIKE qb_fix_orders');
        $this->client->rawQuery('INSERT INTO qb_fix_archived_orders SELECT * FROM qb_fix_orders WHERE tenantId = 2');
        $backfill = (new QueryBuilder())->selectRaw('id, score + ? AS score', [100])->where('tenantId', 2)
            ->orderByAsc('id')->get('qb_fix_archived_orders', 1);
        $template->fromSub($latest, 's')->selectRaw('s.id, s.score + ? AS score', [5])
            ->where('s.score', 10, '>')->union($backfill, true);
        // UNION 的全局排序使用结果列名。
        $count = (clone $template)->count(null);
        self::assertSame([5, 1, 10, 100, 2], $count->getLastBindParams());
        self::assertSame([['aggregate' => 2]], $this->client->query($count));

        $page = $this->client->paginate($template, null, 1, 2);
        self::assertSame(2, $page['total']);
        self::assertSame([['id' => 3, 'score' => 130]], $page['items']);
    }

    public function testJoinedAndConditionSubqueriesRetainTheirValuesInPagination(): void
    {
        $users = QueryBuilder::subQuery('u')->where('tenantId', 1)->get('qb_fix_users', null, ['id', 'tenantId']);
        $this->client->rawQuery('CREATE TEMPORARY TABLE qb_fix_order_queue (order_id INT, queue_id INT)');
        $this->client->rawQuery('INSERT INTO qb_fix_order_queue VALUES (1, 10), (3, 20)');
        $eligible = QueryBuilder::subQuery()->where('queue_id', 10)->get('qb_fix_order_queue', null, 'order_id');
        $template = (new QueryBuilder())->join($users, 'u.id = o.id')
            ->joinOn('u', 'u.tenantId', 'o.tenantId')->whereIn('o.id', $eligible)
            ->fields(['o.id AS order_id', 'u.id AS user_id'])->orderByAsc('o.id');

        $users->where('tenantId', 2)->get('qb_fix_users', null, ['id', 'tenantId']);
        $eligible->where('queue_id', 20)->get('qb_fix_order_queue', null, 'order_id');

        $page = $this->client->paginate($template, 'qb_fix_orders o', 1);
        self::assertSame(1, $page['total']);
        self::assertSame([['order_id' => 1, 'user_id' => 1]], $page['items']);
    }

    public function testGetOneUsesTheDefaultProjectionOfTheOrderBuilder(): void
    {
        $builder = new class extends QueryBuilder {
            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                if ($columns === null && $this->getField() === null) {
                    $this->fields(['id', 'status']);
                }
                return parent::get($tableName, $numRows, $columns);
            }
        };
        $builder->where('tenantId', 1)->orderByAsc('id');
        $one = (clone $builder)->getOne('qb_fix_orders');
        self::assertSame([['id' => 1, 'status' => 'paid+']], $this->client->query($one));
        $explicit = $builder->getOne('qb_fix_orders', 'name');
        self::assertSame([['name' => 'Alice']], $this->client->query($explicit));
    }

    public function testCountIgnoresTopLevelOptionsAndLimitAddedByGetOverride(): void
    {
        $builder = new class extends QueryBuilder {
            private bool $hasLimit = false;

            public function limit(int $one, ?int $two = null): QueryBuilder
            {
                $this->hasLimit = true;
                return parent::limit($one, $two);
            }

            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                $this->setQueryOption(['SQL_BUFFER_RESULT', 'HIGH_PRIORITY'])->orderByAsc('id');
                if (!$this->hasLimit) {
                    $numRows ??= 1;
                }
                return parent::get($tableName, $numRows, 'id');
            }
        };
        $builder->where('tenantId', 1);
        $count = (clone $builder)->count('qb_fix_orders');
        self::assertSame([['aggregate' => 2]], $this->client->query($count));
        $sql = $count->getLastPrepareQuery();
        foreach (['HIGH_PRIORITY', 'SQL_BUFFER_RESULT', 'ORDER BY'] as $clause) {
            self::assertStringNotContainsString($clause, $sql);
        }
        self::assertSame(1, substr_count($sql, 'LIMIT'));
        $page = $this->client->paginate($builder, 'qb_fix_orders', 1, 2);
        self::assertSame(2, $page['total']);
        self::assertSame(2, $page['lastPage']);
        self::assertSame([['id' => 2]], $page['items']);
    }

    public function testCountAndPaginationKeepTopLevelSelectOptionsOutOfTheCountSource(): void
    {
        $table = 'qb_fix_orders';
        foreach ([['SQL_BUFFER_RESULT'], ['HIGH_PRIORITY'], ['SQL_BUFFER_RESULT', 'HIGH_PRIORITY']] as $options) {
            $template = (new QueryBuilder())->fields('tenantId')->distinct()->setQueryOption($options)
                ->where('score', 10, '>=')->orderByAsc('tenantId');
            $count = (clone $template)->count($table);
            self::assertSame([10], $count->getLastBindParams());
            self::assertSame([['aggregate' => 2]], $this->client->query($count));
            $page = $this->client->paginate($template, $table, 1, 2);
            self::assertSame(2, $page['total']);
            self::assertSame([['tenantId' => 2]], $page['items']);
            foreach ($options as $option) {
                self::assertStringNotContainsString($option, $count->getLastPrepareQuery());
                self::assertContains($option, $template->getQueryOptions());
            }
            $data = (clone $template)->get($table);
            foreach ($options as $option) {
                self::assertStringContainsString($option, $data->getLastPrepareQuery());
            }
        }
    }

    public function testPaginationReturnsCountWithZeroSessionSelectLimit(): void
    {
        $this->client->rawQuery('SET SESSION sql_select_limit = 0');
        $template = (new QueryBuilder())->fields('id')->orderByAsc('id');
        $page = $this->client->paginate($template, 'qb_fix_orders', 1, 2);
        self::assertSame([['id' => 2]], $page['items']);
        self::assertSame(4, $page['total']);
    }

    public function testClosingACompletedWriteStreamKeepsTheTransaction(): void
    {
        $connection = $this->client->mysqlClient();
        $connection->begin_transaction();
        $streams = [
            fn() => $this->client->rawIterate('UPDATE qb_fix_orders SET score = score + 1 WHERE id = 1'),
            fn() => $this->client->iterate((new QueryBuilder())->where('id', 2)->update('qb_fix_orders', ['score' => 22])),
        ];
        foreach ($streams as $create) {
            $stream = $create();
            self::assertTrue($stream->isComplete());
            self::assertFalse($connection->isBusy());
            self::assertSame(1, $this->client->getLastAffectRows());
            self::assertSame([], iterator_to_array($stream));
            $stream->close();
            self::assertTrue($connection->inTransaction());
            self::assertFalse($connection->isTransactionLost());
        }
        self::assertTrue($connection->rollback());
        self::assertSame([['score' => 10]], $this->client->rawQuery('SELECT score FROM qb_fix_orders WHERE id = 1'));
    }

    public function testAggregatesAndParameterizedFromSubComposeInSqlOrder(): void
    {
        $group=(new QueryBuilder())->fields(['tenantId'])->groupBy('tenantId')
            ->sum('qb_fix_orders',new Expression('score + ?',[1]),'total');
        $outer=(new QueryBuilder())->fields(['stats.tenantId',new Expression('? AS marker',[9])])
            ->fromSub($group,'stats')->where('stats.total',50,'>')->get(null);
        self::assertSame([9,1,50],$outer->getLastBindParams());
        self::assertSame([['tenantId'=>2,'marker'=>9]],$this->client->query($outer));
        $source = (new QueryBuilder())->raw('SELECT ? AS id, ? AS marker', [1, 2]);
        self::assertSame([['id' => 1, 'marker' => 2]], $this->client->query((new QueryBuilder())->getFromSub($source, 'stats')));
        $outer=(new QueryBuilder())->fromSub($group,'stats')->sum(null,'stats.total');
        self::assertSame(104,(int)$this->client->query($outer)[0]['aggregate']);
        foreach (['sum'=>100,'avg'=>25,'min'=>10,'max'=>40] as $method=>$expected) {
            $builder=(new QueryBuilder())->$method('qb_fix_orders','score');
            self::assertSame($expected,(int)$this->client->query($builder)[0]['aggregate']);
        }
    }

    public function testPaginationSupportsGroupsAndDerivedSourcesWithoutChangingTemplate(): void
    {
        $group=(new QueryBuilder())->fields(['tenantId'])->groupBy('tenantId')->sum('qb_fix_orders','score','total');
        $template=(new QueryBuilder())->fromSub($group,'stats')->orderByDesc('stats.tenantId');
        $page=$this->client->paginate($template,null,1,2);
        self::assertSame(2,$page['total']);
        self::assertSame(2,$page['lastPage']);
        self::assertSame(1,$page['items'][0]['tenantId']);
        self::assertSame(30,(int)$page['items'][0]['total']);
        self::assertNull($template->getLastPrepareQuery());
        self::assertCount(2,$this->client->query($template->get(null)));
    }

    public function testTextAndPreparedStreamsPreserveConnectionAndStatementReuse(): void
    {
        $notifications=[];
        $this->client->onQuery(static function($result)use(&$notifications):void{$notifications[]=$result;});
        $builder=(new QueryBuilder())->orderByAsc('id')->get('qb_fix_orders',null,'id,name');
        $stream=$this->client->iterate($builder);
        self::assertTrue($this->client->mysqlClient()->isBusy());
        self::assertSame([1,2,3,4],array_column(iterator_to_array($stream),'id'));
        self::assertTrue($stream->isComplete());
        self::assertFalse($this->client->mysqlClient()->isBusy());
        self::assertSame(4,$this->client->getLastAffectRows());
        self::assertSame([true],$notifications);
        $statement=$this->client->prepare('SELECT id FROM qb_fix_orders WHERE id > ? ORDER BY id');
        try {
            self::assertSame([3,4],array_column(iterator_to_array($statement->iterate([2])),'id'));
            self::assertSame([['id'=>4]],$statement->execute([3]));
        } finally {$statement->close();}
        self::assertSame([['value'=>1]],iterator_to_array($this->client->rawIterate('SELECT 1 AS value')));
    }

    public function testCompleteServerErrorInAStreamKeepsTheTransactionUsable(): void
    {
        $connection = $this->client->mysqlClient();
        $calls = 0;
        $this->client->onQuery(static function () use (&$calls): void { $calls++; });
        $sql = "SELECT IF(id=1, 'ok', JSON_EXTRACT(CONCAT('bad', id), '$')) AS value"
            . ' FROM (SELECT 1 AS id UNION ALL SELECT 2) s';
        self::assertTrue($connection->begin_transaction());
        try {
            foreach ([fn() => $this->client->rawIterate($sql),
                fn() => $this->client->iterate((new QueryBuilder())->raw($sql))] as $start) {
                $stream = $start();
                $rows = [];
                try {
                    foreach ($stream as $row) { $rows[] = $row; }
                    self::fail('Expected MySQL JSON error');
                } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
                    self::assertSame(3141, $error->getCode());
                }
                self::assertSame([['value' => 'ok']], $rows);
                self::assertTrue($stream->isClosed());
                self::assertFalse($stream->isComplete());
                self::assertFalse($connection->isBusy());
                self::assertTrue($connection->inTransaction());
                self::assertFalse($connection->isTransactionLost());
                self::assertTrue($this->client->ping());
                self::assertNull($this->client->getLastAffectRows());
            }
            self::assertSame(0, $calls);
        } finally {
            $connection->rollback();
        }
    }

    public function testEarlyStreamExitPreservesTransactionLostProtection(): void
    {
        $connection=$this->client->mysqlClient();
        $connection->begin_transaction();
        $stream=$this->client->rawIterate('SELECT id FROM qb_fix_orders ORDER BY id');
        foreach($stream as $row){self::assertSame(1,$row['id']);break;}
        self::assertTrue($stream->isClosed());
        self::assertFalse($stream->isComplete());
        self::assertTrue($connection->isTransactionLost());
        try {$this->client->rawQuery('SELECT 1');self::fail('Lost transaction must block reuse');}
        catch(TransactionLostException $error){self::assertNotEmpty($error->getMessage());}
        $this->client->close();
        $this->client->connect();
        self::assertSame([['value'=>1]],$this->client->rawQuery('SELECT 1 AS value'));
    }

    public function testStreamsWorkWithNegotiatedCompression(): void
    {
        $client=new Client(new Config(array_replace(MYSQL_CONFIG,['compress'=>true])));
        try {
            $client->connect();
            self::assertSame([['value'=>1],['value'=>2]],iterator_to_array($client->rawIterate('SELECT 1 AS value UNION ALL SELECT 2')));
            self::assertTrue($client->mysqlClient()->isCompressionEnabled());
            $statement=$client->prepare('SELECT ? AS value');
            try {self::assertSame([['value'=>'中文😀']],iterator_to_array($statement->iterate(['中文😀'])));}
            finally {$statement->close();}
        } finally {$client->close();}
    }
}
