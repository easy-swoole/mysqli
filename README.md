# Mysqli
本Mysqli构造器基于 https://github.com/ThingEngineer/PHP-MySQLi-Database-Class 移植实现的协程安全版本。
## 单元测试
```php
./vendor/bin/co-phpunit tests
```

## 安装
```
composer require easyswoole/mysqli
```

## Client实例
```php
$config = new \EasySwoole\Mysqli\Config([
        'host'          => '',
        'port'          => 3300,
        'user'          => '',
        'password'      => '',
        'database'      => '',
        'timeout'       => 5,
        'charset'       => 'utf8mb4',
]);

$client = new \EasySwoole\Mysqli\Client($config);

go(function ()use($client){
    //构建sql
    $client->queryBuilder()->get('user_list');
    //执行sql
    var_dump($client->execBuilder());
});
```
## 查询构造器
QueryBuilder是一个SQL构造器，用来构造prepare sql。例如：
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

//执行条件构造逻辑
$builder->where('col1',2)->get('my_table');

//获取上次条件构造的预处理sql语句
echo $builder->getLastPrepareQuery();
// SELECT  * FROM whereGet WHERE  col1 = ? 

//获取上次条件构造的sql语句
echo $builder->getLastQuery();
//SELECT  * FROM whereGet WHERE  col1 = 2 

//获取上次条件构造的预处理sql语句所以需要的绑定参数
echo $builder->getLastBindParams();
//[2]
```

### GET
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// 获取全表
$builder->get('getTable');

// 表前缀
$builder->setPrefix('easyswoole_')->get('getTable');

// 获取总数。下面两个结果相同
$builder->withTotalCount()->where('col1', 1, '>')->get('getTable');
$builder->setQueryOption('SQL_CALC_FOUND_ROWS')->where('col1', 1, '>')->get('getTable');

// fields。支持一维数组或字符串
$builder->fields('col1, col2')->get('getTable');
$builder->get('getTable', null, ['col1','col2']);

// limit 1。下面两个结果相同
$builder->get('getTable', 1)
$builder->getOne('getTable')

// offset 1, limit 10
$builder->get('getTable',[1, 10])

// 去重查询。
$builder->get('getTable', [2,10], ['distinct col1','col2']);

// where查询
$builder->where('col1', 2)->get('getTable');

// where查询2
$builder->where('col1', 2, '>')->get('getTable');

// 多条件where
$builder->where('col1', 2)->where('col2', 'str')->get('getTable');

// whereIn, whereNotIn, whereLike，修改相应的operator(IN, NOT IN, LIKE)
$builder->where('col3', [1,2,3], 'IN')->get('getTable');

// orWhere
$builder->where('col1', 2)->orWhere('col2', 'str')->get('getTable');
```

#### Join
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// join。默认INNER JOIN
$builder->join('table2', 'table2.col1 = getTable.col2')->get('getTable');
$builder->join('table2', 'table2.col1 = getTable.col2', 'LEFT')->get('getTable');

// join Where
$builder->join('table2','table2.col1 = getTable.col2')->where('table2.col1', 2)->get('getTable');
```
#### GroupBy Having
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// groupBy. 
$builder->groupBy('col1')->get('getTable');
$builder->where('col1',2)->groupBy('col1')->get('getTable');

// having
$builder->groupBy('col1')->having('col1')->get('getTable');
$builder->groupBy('col1')->having('col1', 1, '>')->get('whereGet');

// and having. having第4个参数默认是 `AND`，默认多having是`且`关系
$builder->groupBy('col1')->having('col1', 1, '>')->having('col2', 1, '>')->get('whereGet');

// or having. 下面两种方法效果相等
$builder->groupBy('col1')->having('col1', 1, '>')->orHaving('col2', 1, '>')->get('whereGet');
$builder->groupBy('col1')->having('col1', 1, '>')->having('col2', 1, '>', 'OR')->get('whereGet');
```

#### OrderBy
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// orderBy. 默认DESC
$builder->orderBy('col1', 'ASC')->get('getTable');
$builder->where('col1',2)->orderBy('col1', 'ASC')->get('getTable');

```

#### Union
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// union. 相当于 adminTable UNION userTable
$builder->union((new QueryBuilder)->where('userName', 'user')->get('userTable'))
    ->where('adminUserName', 'admin')->get('adminTable');
```

### UPDATE
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// update 
$builder->update('updateTable', ['a' => 1]);

// limit update
$builder->update('updateTable', ['a' => 1], 5);

// where update
$builder->where('whereUpdate', 'whereValue')->update('updateTable', ['a' => 1]);
```

### DELETE
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// delete all
$builder->delete('deleteTable');

// limit delete
$builder->delete('deleteTable', 1);

// where delete
$builder->where('whereDelete', 'whereValue')->delete('deleteTable');

```

### INSERT
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// insert into
$builder->insert('insertTable', ['a' => 1, 'b' => "b"]);

// replace into
$builder->replace('replaceTable', ['a' => 1]);

```

### SUBQUERY
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// 单一where条件子查询
// 等同于 SELECT * FROM users WHERE id in ((SELECT userId FROM products WHERE  qty > 2))
$sub = $this->builder::subQuery();
$sub->where("qty", 2, ">");
$sub->get("products", null, "userId");
$builder->where("id", $sub, 'in')->get('users');

// 多where条件子查询
// 等同于 SELECT * FROM users WHERE col2 = 1 AND id in ((SELECT userId FROM products WHERE  qty > 2))
$sub = $this->builder::subQuery();
$sub->where ("qty", 2, ">");
$sub->get ("products", null, "userId");
$this->builder->where('col2',1)->where ("id", $sub, 'in')->get('users');

// INSERT 包含子结果集
// 等同于 INSERT INTO products (`productName`, `userId`, `lastUpdated`) VALUES ('test product', (SELECT name FROM users WHERE  id = 6  LIMIT 1), NOW())
$userIdQ = $this->builder::subQuery();
$userIdQ->where ("id", 6);
$userIdQ->getOne ("users", "name");
$data = Array (
    "productName" => "test product",
    "userId" => $userIdQ,
    "lastUpdated" => $this->builder->now()
);
$this->builder->insert ("products", $data);
```

### LOCK
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// FOR UPDATE 排它锁。下面两个方法效果相同
$builder->setQueryOption("FOR UPDATE")->get('getTable');
$builder->selectForUpdate(true)->get('getTable');

// FOR UPDATE NOWAIT
$builder->selectForUpdate(true, 'NOWAIT')->get('getTable');
// FOR UPDATE WAIT Second
$builder->selectForUpdate(true, 'WAIT 5')->get('getTable');
// FOR UPDATE SKIP LOCKED
$builder->selectForUpdate(true, 'SKIP LOCKED')->get('getTable');

//  LOCK IN SHARE MODE。共享锁
$builder->lockInShareMode()->get('getTable');
$builder->setQueryOption(['LOCK IN SHARE MODE'])->get('getTable');

// LOCK TABLES 获取表锁
$builder->lockTable('table');

// UNLOCK TABLES 释放表锁
$builder->unlockTable('table');

```
