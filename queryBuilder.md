# QueryBuilder 使用指南

本文根据 `4.x` 分支 README 中的 QueryBuilder 用法整理，适用于 `5.x`。
QueryBuilder 负责构建预处理 SQL 和绑定参数；实际执行交给 `Client::query()`。

## 构建与执行

```php
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use Swoole\Coroutine;

Coroutine\run(function (): void {
    $client = new Client(new Config([
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'test',
        'password' => 'your-test-password',
        'database' => 'test',
        'charset' => 'utf8mb4',
    ]));
    try {
        $builder = new QueryBuilder();
        $builder->where('id', 1)->get('users');
        $rows = $client->query($builder);
        var_dump($rows);
    } finally {
        $client->close();
    }
});
```

构造器的 `get()`、`getOne()`、`insert()`、`update()` 等方法返回构造器，
不会执行数据库操作。`getOne()` 构建 `LIMIT 1` 查询，执行后仍返回行数组。
成功执行写入语句返回 `true`；影响行数和插入 ID 分别由客户端的
`getLastAffectRows()`、`getLastInsertId()` 获取。

每次完成 SQL 构建后，查询条件、字段、排序、分页、去重及锁选项会自动重置，
已构建的 SQL 和绑定参数可通过下面的方法读取。表前缀会保留，
需要取消时调用 `setPrefix('')`。以下示例中的表名和字段请替换成实际结构。

## 读取 SQL 和绑定参数

例如：
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

//执行条件构造逻辑
$builder->where('col1',2)->get('my_table');

//获取上次条件构造的预处理sql语句
echo $builder->getLastPrepareQuery();
// SELECT  * FROM `my_table` WHERE  `col1` = ? 

//获取上次条件构造的sql语句
echo $builder->getLastQuery();
// SELECT  * FROM `my_table` WHERE  `col1` = 2 

// 获取上次预处理 SQL 需要的绑定参数
var_dump($builder->getLastBindParams());
//[2]
```

## 常用查询语法糖

新增方法保留原有 `where()`、`orWhere()`、`orderBy()`、`selectForUpdate()` 等接口及行为。
值继续通过预处理参数绑定；构建完成或调用 `reset()` 后，条件、排序、索引提示和行锁设置自动清除，表前缀保留。

```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();
$builder->forceIndex('idx_userId')
    ->where('userId', $userId)
    ->whereNull('deleted_at')
    ->orderByDesc('mapId')
    ->get('order_mapping', 10);
$rows = $client->query($builder);
```

| 接口 | 用法及含义 |
| --- | --- |
| `whereIn($column, $values)` | 数组或已构建的 `QueryBuilder::subQuery()`；空数组生成 `0 = 1` |
| `whereNotIn($column, $values)` | 数组或已构建的子查询；空数组生成 `1 = 1` |
| `whereNull($column)` / `whereNotNull($column)` | `IS NULL` / `IS NOT NULL` |
| `whereBetween($column, [$min, $max])` | 包含两端的范围，必须恰好两个边界值 |
| `whereLike($column, $pattern)` | 普通字段名或 `表名.字段名`；自行提供 `%`、`_` 通配符，不自动转义通配符 |
| `whereExists($sub)` / `whereNotExists($sub)` | 已构建且不带结果别名的子查询 |
| `whereGroup($callback)` / `orWhereGroup($callback)` | 带括号的 AND / OR 条件组，支持嵌套 |
| `orderByAsc($column)` / `orderByDesc($column)` | 对应 `orderBy($column, 'ASC' / 'DESC')` |
| `forceIndex($indexes)` / `useIndex($indexes)` / `ignoreIndex($indexes)` | 强制、建议使用或排除主表索引 |
| `forUpdate($isLock = true)` | 对应现有 `selectForUpdate()` |
| `skipLocked()` / `nowait()` | 自动开启 `FOR UPDATE SKIP LOCKED` / `FOR UPDATE NOWAIT` |

集合、范围和 LIKE 方法可通过第三个参数 `'OR'` 与前面条件连接；
NULL 和 EXISTS 方法使用第二个参数，例如 `whereNull('deleted_at', 'OR')`。
旧的 `where('id', [], 'IN')` 不改变行为，空数组保护仅适用于新的集合方法。

### 条件分组与子查询

```php
$builder->where('tenantId', $tenantId)
    ->whereGroup(function (QueryBuilder $q): void {
        $q->where('status', 1)->orWhere('status', 2);
    })
    ->whereBetween('amount', [100, 500])
    ->whereLike('name', '%手机%')
    ->get('orders');
// WHERE tenantId = ? AND (status = ? OR status = ?)
// AND amount BETWEEN ? AND ? AND name LIKE ?

$sub = QueryBuilder::subQuery()->useIndex('idx_active')
    ->where('active', 1)->get('users', null, 'id');
$builder->whereIn('userId', $sub)->get('orders');

$exists = QueryBuilder::subQuery()
    ->where('payments.orderId = orders.id')
    ->where('status', 'paid')->get('payments', null, '1');
$builder->whereExists($exists)->get('orders');
```

分组闭包只收集 WHERE 条件，不在闭包内调用 `get()` 或设置排序、分页、索引、行锁。
空分组抛出 `InvalidArgumentException`；闭包异常不会添加半成品条件组。
子查询参数按 SQL 出现顺序合并，内外层的索引提示各自独立。

### 索引提示和行锁

```php
$builder->useIndex(['idx_userId', 'idx_mapId'])->ignoreIndex('idx_status')
    ->where('userId', $userId)->get('order_mapping', 10);

// 在业务事务中执行，事务由调用方开启和结束。
$builder->where('status', 'pending')->orderByAsc('id')
    ->forUpdate()->skipLocked()->get('jobs', 10);
$rows = $client->query($builder);
```

索引名支持字符串或数组，自动加反引号；不要传逗号分隔的 SQL 片段。
同类提示重复调用时覆盖，传 `[]` 清除该类提示；非空的 `USE` 和 `FORCE` 设置互相覆盖，`IGNORE` 可搭配使用。
提示仅作用于 SELECT 主表，不作用于 JOIN 表或写入目标。
`useIndex([])` 表示清除设置，不生成 MySQL 的 `USE INDEX ()`。
已有表名 SQL 片段中包含索引提示时，不要再为该表叠加这些方法。

`skipLocked()`、`nowait()` 无需先调用 `forUpdate()`，两种等待选项以后者为准；
`forUpdate(false)` 取消排他锁。它们不会自动开启业务事务，旧行锁方法继续可用。

## 强制索引（FORCE INDEX）

`forceIndex()` 为下一次 SELECT 的主表指定索引，可用于 `get()`、`getOne()` 等查询。
索引提示位于表名及别名之后、JOIN 和 WHERE 之前，不作用于 JOIN 表或写入语句。

```php
$builder->forceIndex('idx_userId')
    ->where('userId', $userId)
    ->orderBy('mapId', 'DESC')
    ->get('order_mapping', 10);
$rows = $client->query($builder);
// SELECT * FROM `order_mapping` FORCE INDEX (`idx_userId`)
// WHERE `userId` = ? ORDER BY mapId DESC LIMIT 10

$builder->forceIndex(['idx_userId', 'idx_mapId'])->get('order_mapping');
$builder->forceIndex('PRIMARY')->getOne('order_mapping');
```

传入单个索引名或索引名数组，名称自动使用反引号引用；不要传入逗号分隔的 SQL 片段。
重复调用覆盖之前的索引列表，`forceIndex([])` 取消设置。
构建完成或调用 `reset()` 后，索引设置自动清除，不影响下一条查询。

## 查询（SELECT）
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// 获取全表
$builder->get('getTable');

// 表前缀
$builder->setPrefix('easyswoole_')->get('getTable');
$builder->setPrefix(''); // 恢复无前缀，避免影响下面的示例

// 添加 SQL_CALC_FOUND_ROWS 选项。下面两个构建结果相同
$builder->withTotalCount()->where('col1', 1, '>')->get('getTable');
$builder->setQueryOption('SQL_CALC_FOUND_ROWS')->where('col1', 1, '>')->get('getTable');

// fields。支持一维数组或字符串
$builder->fields('col1, col2')->get('getTable');
$builder->get('getTable', null, ['col1','col2']);

// limit 1。下面两个结果相同
$builder->get('getTable', 1);
$builder->getOne('getTable');

// offset 1, limit 10
$builder->get('getTable', [1, 10]);

// 去重查询：对所选列的组合去重，仅影响本次查询。
$builder->distinct()->get('getTable', [2,10], ['col1', 'col2']);
$builder->distinct(true)->fields('col1')->get('getTable');

// 在构建查询前关闭去重。
$builder->distinct()->distinct(false)->get('getTable');

// 仍兼容原有查询选项写法。
$builder->setQueryOption('DISTINCT')->get('getTable', null, ['col1', 'col2']);

// where查询
$builder->where('col1', 2)->get('getTable');

// where查询2
$builder->where('col1', 2, '>')->get('getTable');

// 多条件where
$builder->where('col1', 2)->where('col2', 'str')->get('getTable');

// IN / NOT IN / LIKE 通过 where() 的 operator 参数指定
$builder->where('col3', [1, 2, 3], 'IN')->get('getTable');
$builder->where('col3', [1, 2, 3], 'NOT IN')->get('getTable');
$builder->where('col2', '%keyword%', 'LIKE')->get('getTable');

// orWhere
$builder->where('col1', 2)->orWhere('col2', 'str')->get('getTable');
```

### 关联查询（JOIN）
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// join。默认INNER JOIN
$builder->join('table2', 'table2.col1 = getTable.col2')->get('getTable');
$builder->join('table2', 'table2.col1 = getTable.col2', 'LEFT')->get('getTable');

// join Where
$builder->join('table2','table2.col1 = getTable.col2')->where('table2.col1', 2)->get('getTable');
```
### 分组与筛选（GROUP BY / HAVING）

每个用法下方列出 `getLastPrepareQuery()` 预期构建的 SQL（省略多余空格）及绑定参数。
`?` 是预处理占位符，参数由 `Client::query()` 绑定。

```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// 单字段分组
$builder->groupBy('col1')->get('getTable');
// SQL: SELECT * FROM `getTable` GROUP BY col1
// 绑定参数: []

// 先筛选记录，再分组
$builder->where('col1', 2)->groupBy('col1')->get('getTable');
// SQL: SELECT * FROM `getTable` WHERE `col1` = ? GROUP BY col1
// 绑定参数: [2]

// 不传条件值时，having() 将条件表达式直接加入 SQL
$builder->groupBy('col1')->having('col1')->get('getTable');
// SQL: SELECT * FROM `getTable` GROUP BY col1 HAVING col1
// 绑定参数: []

// 分组后使用 HAVING 筛选
$builder->groupBy('col1')->having('col1', 1, '>')->get('whereGet');
// SQL: SELECT * FROM `whereGet` GROUP BY col1 HAVING `col1` > ?
// 绑定参数: [1]

// 多个 HAVING 条件默认通过 AND 连接
$builder->groupBy('col1')->having('col1', 1, '>')->having('col2', 1, '>')->get('whereGet');
// SQL: SELECT * FROM `whereGet` GROUP BY col1 HAVING `col1` > ? AND `col2` > ?
// 绑定参数: [1, 1]

// 通过 orHaving() 使用 OR 连接
$builder->groupBy('col1')->having('col1', 1, '>')->orHaving('col2', 1, '>')->get('whereGet');
// SQL: SELECT * FROM `whereGet` GROUP BY col1 HAVING `col1` > ? OR `col2` > ?
// 绑定参数: [1, 1]

// having() 第四个参数传入 OR，与上一种写法等效
$builder->groupBy('col1')->having('col1', 1, '>')->having('col2', 1, '>', 'OR')->get('whereGet');
// SQL: SELECT * FROM `whereGet` GROUP BY col1 HAVING `col1` > ? OR `col2` > ?
// 绑定参数: [1, 1]
```

### 排序（ORDER BY）
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// orderBy. 默认DESC
$builder->orderBy('col1', 'ASC')->get('getTable');
$builder->where('col1',2)->orderBy('col1', 'ASC')->get('getTable');

```

### 合并查询（UNION）
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// union. 相当于 adminTable UNION userTable
$builder->union((new QueryBuilder)->where('userName', 'user')->get('userTable'))
    ->where('adminUserName', 'admin')->get('adminTable');
```

## 更新（UPDATE）
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

## 删除（DELETE）
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

## 插入（INSERT / REPLACE）
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// insert into
$builder->insert('insertTable', ['a' => 1, 'b' => "b"]);

// replace into
$builder->replace('replaceTable', ['a' => 1]);

```

## 子查询
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// 单一where条件子查询
// 等同于 SELECT * FROM users WHERE id in ((SELECT userId FROM products WHERE  qty > 2))
$sub = QueryBuilder::subQuery();
$sub->where("qty", 2, ">");
$sub->get("products", null, "userId");
$builder->where("id", $sub, 'in')->get('users');

// 多where条件子查询
// 等同于 SELECT * FROM users WHERE col2 = 1 AND id in ((SELECT userId FROM products WHERE  qty > 2))
$sub = QueryBuilder::subQuery();
$sub->where ("qty", 2, ">");
$sub->get ("products", null, "userId");
$builder->where('col2',1)->where ("id", $sub, 'in')->get('users');

// INSERT 包含子结果集
// 等同于 INSERT INTO products (`productName`, `userId`, `lastUpdated`) VALUES ('test product', (SELECT name FROM users WHERE  id = 6  LIMIT 1), NOW())
$userIdQ = QueryBuilder::subQuery();
$userIdQ->where ("id", 6);
$userIdQ->getOne ("users", "name");
$data = Array (
    "productName" => "test product",
    "userId" => $userIdQ,
    "lastUpdated" => QueryBuilder::now()
);
$builder->insert ("products", $data);
```

## 锁

行锁示例应在事务中执行。锁选项直接拼入 SQL，请根据目标数据库支持的语法选择。

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

// UNLOCK TABLES 释放表锁，无需传入表名
$builder->unlockTable();

```

## 批量插入

```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();
$builder->insertAll('users', [
    ['name' => 'Alice', 'age' => 21],
    ['name' => 'Bob', 'age' => 22],
]);
```

## 原始 SQL 与绑定参数

```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();
$builder->raw('SELECT * FROM users WHERE age > ? AND name = ?', [18, 'Alice']);
// 在协程内通过 $client->query($builder) 执行。
```

`getLastQuery()` 是用于查看和调试的 SQL；执行时使用预处理 SQL 和绑定参数。

## 事务语句

```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();
$builder->startTransaction();
// 在协程内执行 $client->query($builder)，然后执行本事务的其他查询。

$builder->commit();
// 执行 $client->query($builder) 提交事务。

$builder->rollback();
// 异常路径中执行 $client->query($builder) 回滚事务。
```

以上方法只构建事务语句；整个事务需要使用同一个客户端连接执行。
