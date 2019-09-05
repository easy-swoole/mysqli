# Mysqli
本Mysqli构造器基于 https://github.com/ThingEngineer/PHP-MySQLi-Database-Class 移植实现的协成安全版本。
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
```
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// 获取全表
$builder->get('getTable');

// limit 1
$builder->get('getTable', 1)

// offset 1, limit 10
$builder->get('getTable',[1, 10])

// 针对colums查询
$builder->get('getTable', null, ['col1','col2']);

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

// join。默认INNER JOIN
$builder->join('table2', 'table2.col1 = getTable.col2')->get('getTable');
$builder->join('table2', 'table2.col1 = getTable.col2', 'LEFT')->get('getTable');

// join Where
$builder->join('table2','table2.col1 = getTable.col2')->where('table2.col1',2)->get('getTable');
```

### UPDATE
```
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// update 
$builder->update('updateTable', ['a' => 1]);

// limit update
$builder->update('updateTable', ['a' => 1], 5);

// where update
$builder->where('whereUpdate', 'whereValue')->update('updateTable', ['a' => 1]);

// 上锁更新。lock update
$builder->setQueryOption("FOR UPDATE")->where('whereUpdate', 'whereValue')->update('updateTable', ['a' => 1], 5);

```

### DELETE
```
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
```
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

$builder->insert('insertTable', ['a' => 1, 'b' => "b"]);

```

### UNION
```angular2html
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// 具体用法可看单元测试(mysqli/tests/QueryBuilderTest.php),union部分
$builder->union((new QueryBuilder)->where('userName', 'user')->get('user'))
    ->where('adminUserName', 'admin')->get('admin');

```