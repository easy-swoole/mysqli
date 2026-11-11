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

    public function testCustomFieldOrderingPreservesPunctuationAndWorksWithAnsiQuotes(): void
    {
        $builder = (new QueryBuilder())->orderBy('status', 'ASC', ['paid+', 'a/b', "O'Reilly", '中文'])
            ->get('qb_fix_orders', null, 'id');
        self::assertSame([1,2,3,4], array_column($this->client->query($builder), 'id'));
        self::assertSame(['paid+', 'a/b', "O'Reilly", '中文'], $builder->getLastBindParams());
    }

    public function testGetColumnPreservesCompleteFunctionAndAlias(): void
    {
        $builder = (new QueryBuilder())->fields(["CONCAT(COALESCE(name, 'guest'), ', ', status) AS display_name"])
            ->where('id', 3)->getColumn('qb_fix_orders');
        self::assertSame([['display_name'=>"guest, O'Reilly"]], $this->client->query($builder));
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

    public function testCountsHandleJoinDuplicateNamesDistinctGroupsAndUnion(): void
    {
        $builder=(new QueryBuilder())->join('qb_fix_users u','u.tenantId=o.tenantId','INNER')->count('qb_fix_orders o');
        self::assertSame([['aggregate'=>6]],$this->client->query($builder));
        $builder->fields(['tenantId'])->distinct()->orderByDesc('tenantId')->limit(1)->count('qb_fix_orders');
        self::assertSame([['aggregate'=>2]],$this->client->query($builder));
        $builder->groupBy('tenantId')->having('SUM(score)',40,'>')->count('qb_fix_orders');
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

    public function testAggregatesAndParameterizedFromSubComposeInSqlOrder(): void
    {
        $group=(new QueryBuilder())->fields(['tenantId'])->groupBy('tenantId')
            ->sum('qb_fix_orders',new Expression('score + ?',[1]),'total');
        $outer=(new QueryBuilder())->fields(['stats.tenantId',new Expression('? AS marker',[9])])
            ->fromSub($group,'stats')->where('stats.total',50,'>')->get(null);
        self::assertSame([9,1,50],$outer->getLastBindParams());
        self::assertSame([['tenantId'=>2,'marker'=>9]],$this->client->query($outer));
        $commented=(new QueryBuilder())->raw('SELECT ? AS id, ? AS marker -- source comment',[1,2]);
        self::assertSame([['id'=>1,'marker'=>2]],$this->client->query((new QueryBuilder())->getFromSub($commented,'stats')));
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
        self::assertSame([['value'=>1]],$this->client->rawQuery('SELECT 1 AS value'));
    }

    public function testStreamsWorkWithNegotiatedCompression(): void
    {
        $client=new Client(new Config(array_replace(MYSQL_CONFIG,['compress'=>true])));
        try {
            self::assertSame([['value'=>1],['value'=>2]],iterator_to_array($client->rawIterate('SELECT 1 AS value UNION ALL SELECT 2')));
            self::assertTrue($client->mysqlClient()->isCompressionEnabled());
            $statement=$client->prepare('SELECT ? AS value');
            try {self::assertSame([['value'=>'中文😀']],iterator_to_array($statement->iterate(['中文😀'])));}
            finally {$statement->close();}
        } finally {$client->close();}
    }
}
