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

### 条件值与原始表达式：`ConditionValue::Raw`

`where()`、`orWhere()`、`having()`、`orHaving()`、`joinWhere()` 和 `joinOrWhere()`
统一使用 `EasySwoole\Mysqli\ConditionValue::Raw` 表示“不追加比较运算或绑定值的完整 SQL 条件”。
省略条件值时默认使用此枚举；显式传入的字符串 `'DBNULL'` 不再是控制标记，会作为普通字符串绑定。

```php
use EasySwoole\Mysqli\ConditionValue;
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();
$builder->where('tenantId', 'DBNULL')->where('enabled', true)->get('orders');
// SELECT * FROM `orders` WHERE `tenantId` = ? AND `enabled` = ?
// 绑定参数：['DBNULL', true]

$builder->where('payments.orderId = orders.id')->get('payments');
// 单参数写法：整个条件是原始 SQL。

$builder->where('payments.orderId = orders.id', ConditionValue::Raw)->get('payments');
// 显式枚举写法，含义与上面的单参数写法相同。

$builder->having('COUNT(*) > 0', ConditionValue::Raw)->get('orders', null, 'COUNT(*)');
$builder->join('users u', 'u.id = o.userId', 'LEFT')
    ->joinWhere('users u', 'u.enabled = 1', ConditionValue::Raw)->get('orders o');
```

- `Raw` 是构造器控制标记，不是数据库值；`true`、`false`、`0`、`'0'`、空字符串和 `'DBNULL'` 都按普通值绑定，不会取消条件。
- 数据库 NULL 使用 PHP `null`；判断 NULL 请使用 `whereNull()` / `whereNotNull()`。此修复不改变旧的 `where('column', null)` 所指定的比较运算符，不会自动把 `= NULL` 改为 `IS NULL`。
- `Raw` 或单参数条件会原样写入 SQL，不引用整个表达式、不追加运算符、不绑定值。仅用于代码中固定或经过严格白名单检查的表达式，不拼接请求参数。
- 迁移：旧的 `where('a.id = b.id', 'DBNULL')` 改为 `where('a.id = b.id')` 或显式传 `ConditionValue::Raw`；HAVING、JOIN 及 OR 方法同理。旧的字符串控制标记不再兼容。
- 枚举只用于上述条件方法的“值”参数，不作为 INSERT/UPDATE 写入值或 Statement 绑定参数使用；不需要把布尔值转换为枚举。

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

### `orderBy($column, $direction = 'DESC', $customFieldsOrRegExp = null)`

```php
$builder->where('tenantId', $tenantId)->orderBy('name', 'DESC', "O'Reilly")->get('users');
// WHERE `tenantId` = ? ORDER BY name REGEXP ? DESC
// 参数：[$tenantId, "O'Reilly"]
```

- 第三个参数为字符串时按正则表达式值绑定，不直接拼进 SQL；不手动添加 SQL 引号或转义单引号。无效正则由数据库报错。
- 参数按最终 SQL 顺序合并：JOIN、WHERE、HAVING、UNION 各分支，再到整体 ORDER BY，不受条件方法调用先后影响。
- 同一字段加同一正则重复设置时覆盖方向，不同正则保留为不同排序项；构建完成后自动重置。
- 字段名仍使用代码常量或白名单。通过 `Client::query()` 执行预处理 SQL；`getLastQuery()` 仅供调试展示，不用于重新拼接执行。

### `union($query, $isUnionAll = false)`

```php
$archive = (new QueryBuilder())->where('status', 'closed')->orderByDesc('id')->get('archive', 2, 'id');
$builder->where('status', 'open')->union($archive)->orderByDesc('id')->get('orders', 10, 'id');
// SELECT id FROM `orders` WHERE `status` = ?
// UNION (SELECT id FROM `archive` WHERE `status` = ? ORDER BY id DESC LIMIT 2)
// ORDER BY id DESC LIMIT 10
```

- 主构造器的排序分页作用于 UNION 整体结果；要限制某个分支，先在独立 Builder 完成该分支的 `get()`，再传给 `union()`。
- 有局部排序、分页、锁选项或嵌套 UNION 的分支自动加括号；原始 SQL 分支也加括号，以保留分支范围。
- 各分支返回列数必须一致，整体排序使用结果列名或统一列别名，不使用分支表名限定字段。
- `union($builder)` 保存调用时分支的 SQL 与参数；后续重建分支不会改变已保存内容。原始字符串不带结尾分号，也不支持额外绑定参数；需要参数时传已构建 Builder 或 `raw($sql, $params)` Builder。

### `get($table, $numRows = null, $columns = null)`

```php
$builder->get('orders', 0);               // LIMIT 0，返回零行
$builder->limit(25)->get('orders', 0);    // 显式的 0 覆盖 25
$builder->limit(25)->get('orders', null); // 使用此前 LIMIT 25
```

- 只有 `null` 表示未指定条数并使用此前 `limit()` 设置；数字 `0` 不取消 LIMIT。
- 未指定条数且未设置 `limit()` 才不限制条数；偏移分页 `[0, 0]` 同样返回零行。

### `joinWhere()` / `joinOrWhere()` 的 NULL 判断

```php
$builder->join('users u', 'u.id=o.userId', 'LEFT')
    ->joinWhere('users u', 'u.deleted_at', null, 'IS')->get('orders o');
// LEFT JOIN users u on u.id=o.userId AND u.deleted_at IS NULL
```

- NULL 判断使用 `'IS'` 或 `'IS NOT'`，不使用默认的 `'='`；无需在运算符前手动添加空格。
- 第一个参数与对应 `join()` 的表名、别名保持一致；条件位于 JOIN 的 ON 部分，不是外层 WHERE。LEFT JOIN 中移动条件到 WHERE 可能改变保留行范围。
- 混用 AND/OR 时仍遵循 SQL 优先级，需确认完整 ON 表达式的含义。

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

## 内部实现与兼容性

公开 `QueryBuilder` 负责链式状态和构建结果管理，SQL 编译委托给 `src/QueryBuilder/` 内部协作类。
条件、写入、SELECT 子句、参数缓冲、表名/索引、表达式和调试格式化各自独立；完整职责表见 README 的“QueryBuilder 内部职责”。
重构保留公开方法签名、SQL/参数输出、子查询读取时机、UNION 快照、锁标记和构建器复用规则，业务调用无需迁移。
内部协作类带 `@internal` 标记，不承诺业务层直接依赖它们的兼容性。
