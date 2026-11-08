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

### `Expression` / `rawExpression(string $sql, array $parameters = [])`

```php
use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\QueryBuilder;

$expression = QueryBuilder::rawExpression('COALESCE(name, ?) AS display_name', ['匿名']);
// 等价：new Expression('COALESCE(name, ?) AS display_name', ['匿名'])
$builder->fields(['id', $expression])->get('users');

$builder->where('id', $id)->update('users', [
    'score' => QueryBuilder::rawExpression('score + ?', [5]),
]);
```

- `Expression` 是不可变的 SQL 片段与参数对象，可放入所选字段、普通比较条件的字段或值位置，以及 INSERT/UPDATE 的值中。SQL 和参数分别由 `getSql()`、`getParameters()` 读取。
- SQL 片段必须来自可信代码，不能直接使用请求中的 SQL；绑定参数只保护值，不会让动态表名、字段名、运算符或 SQL 结构自动安全。
- 使用位置占位符 `?`，不使用 `:name`；参数只能是标量或 `null`，数组/对象需要先转换成业务所需的字符串，例如 JSON 使用 `json_encode()`。占位符的数量和顺序应与参数一致。
- SQL 不能为空或包含 NUL 字节；片段不自动添加别名、字段引用或运算符，需要时在可信片段内写明 `AS alias`。同一个 Expression 多处使用时，每个位置都会合并一次参数。
- 原有 `QueryBuilder::func()`、`now()`、`inc()`、`dec()`、`not()` 及整条查询 `raw()` 继续可用，不需要迁移旧调用。

### `getColumn($tableName, $columns = null, $numRows = null)` / `getScalar($tableName, $columns = null)`

```php
$builder->getColumn('users', QueryBuilder::rawExpression('COALESCE(name, ?)', ['匿名']));
$builder->getScalar('orders', QueryBuilder::rawExpression('SUM(CASE WHEN status = ? THEN amount ELSE 0 END)', ['paid']));
```

- 两个方法的字段参数均可直接传入 Expression；表达式中的参数随 SELECT 合并，字段参数仍兼容原来的字符串用法。
- 省略字段时沿用 `fields()` 中的第一项；`getScalar()` 固定 LIMIT 1。两个方法仍返回构造器，需交给 `Client::query()` 执行。

### `selectRaw(string $sql, array $parameters = [])`

```php
$builder->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) AS paid_total', ['paid'])
    ->where('tenantId', $tenantId)->get('orders');
// SELECT SUM(CASE WHEN status = ? THEN amount ELSE 0 END) AS paid_total ...
// 参数顺序：['paid', $tenantId]

$builder->fields(['id'])->selectRaw('COALESCE(name, ?) AS name', ['匿名'])->get('users');
```

- 第一次调用替换未设置字段时的隐式默认 `*`，重复调用追加表达式。已经通过 `fields()` 显式选择的字段会保留；`fields('*')->selectRaw(...)` 会保留显式星号。
- 之后再调用 `fields(...)` 会覆盖之前的选择。显式传给 `get()` 的第三个 `$columns` 参数也优先于待构建字段配置。
- SELECT 表达式参数排在 JOIN、WHERE、HAVING、UNION 分支和 ORDER BY 参数之前，与方法调用先后无关。构建完成或 `reset()` 后清除。
- 只传选择列表中的表达式，不传 `SELECT` 关键字或整条查询；有计算列时建议显式写别名。

### `whereRaw(string $sql, array $parameters = [], string $cond = 'AND')`

```php
$builder->where('tenantId', $tenantId)
    ->whereRaw('name = ? OR nickname = ?', [$keyword, $keyword])->get('users');
// WHERE `tenantId` = ? AND (name = ? OR nickname = ?)
```

- 自动给该片段添加括号，以免片段内的 OR 越过外层 AND；只传条件本身，不传 `WHERE`、前置 AND/OR 或整条 SQL。
- 第三个参数只能是 AND 或 OR（忽略大小写及两侧空白）；首个条件省略前置连接符。复杂的多层条件可与 `whereGroup()` 配合。
- SQL 片段仍必须可信，所有外部值通过 `$parameters` 绑定；不需要手动加 SQL 引号。`whereRaw()` 不替代普通 `where()` 的字段和值比较。

### `havingRaw(string $sql, array $parameters = [], string $cond = 'AND')`

```php
$builder->fields(['userId', 'SUM(amount) AS total_amount'])
    ->groupBy('userId')->havingRaw('SUM(amount) > ? OR COUNT(*) >= ?', [1000, 5])->get('orders');
```

- 生成带括号的 HAVING 条件，连接符及可信 SQL 的规则与 `whereRaw()` 相同；不传 `HAVING` 关键字。
- HAVING 参数在 WHERE 参数之后、UNION 分支及整体排序参数之前；组内筛选使用 `havingGroup()`。

### `orderByRaw(string $sql, array $parameters = [])`

```php
$builder->orderByRaw('FIELD(status, ?, ?) DESC', ['paid', 'pending'])
    ->orderByDesc('id')->get('orders');
```

- 传完整排序项，可在可信片段内写明 ASC/DESC；方法不会自动追加方向，不传 `ORDER BY` 关键字。
- 外部值使用参数绑定，字段和方向仍由可信代码或白名单决定；绑定 `?` 不能代替字段标识符。
- 可与普通 `orderBy()` 混用，按调用顺序排列；UNION 主构造器上的排序作用于整体结果。构建完成或 `reset()` 后清除。

### `whereColumn($first, $second, $operator = '=', $cond = 'AND')` / `orWhereColumn()`

```php
$builder->whereColumn('orders.paid_amount', 'orders.total_amount', '>=')->get('orders');
// WHERE `orders`.`paid_amount` >= `orders`.`total_amount`
```

- 两个参数都是字段引用，不绑定为字符串值；参数顺序是“左字段、右字段、运算符”，不是“左字段、运算符、右字段”。需要比较业务值时继续使用 `where()`。
- 支持列名、`表或别名.列`、`库.表.列`，各段统一使用反引号引用；可接受正确的反引号引用，不接收函数、算术表达式或 `AS` 别名片段。这些表达式使用 Raw 方法或 Expression。
- 支持 `=`、`<=>`、`<>`、`!=`、`>`、`<`、`>=`、`<=`；其他运算符、空字段段或畸形引用会报错。字段名仍应来自可信代码或白名单。
- `orWhereColumn($first, $second, $operator = '=')` 以 OR 连接；AND/OR 组合需要括号时放入 `whereGroup()`。

### `havingColumn($first, $second, $operator = '=', $cond = 'AND')` / `orHavingColumn()`

```php
$builder->fields(['userId', 'SUM(paid) AS paid_total', 'SUM(expected) AS expected_total'])
    ->groupBy('userId')->havingColumn('paid_total', 'expected_total', '<')->get('orders');
```

- 用于 HAVING 中两个结果字段或别名的比较；参数顺序、字段引用、运算符和 OR 版本规则与 `whereColumn()` 相同。
- 这里的两个参数都不是聚合表达式 SQL；需要直接写 `SUM(...)` 等计算时用 `havingRaw()` 或 Expression。

### `joinOn($table, $first, $second, $operator = '=', $cond = 'AND')` / `joinOrOn()`

```php
$builder->join('users u', 'u.id=o.userId', 'LEFT')
    ->joinOn('users u', 'u.tenantId', 'o.tenantId')->get('orders o');
// LEFT JOIN users u on u.id=o.userId AND `u`.`tenantId` = `o`.`tenantId`
```

- 给已有 JOIN 的 ON 部分追加字段比较，不创建 JOIN，也不把右字段当值绑定；参数中的两个字段遵循 `whereColumn()` 的引用规则。
- `$table` 与 `join()` 中的表名、别名写法保持一致；JOIN 子查询使用其别名定位。表前缀由构造器沿用现有规则处理，不手动重复添加。
- `joinOrOn($table, $first, $second, $operator = '=')` 追加 OR。需要与常量值比较时使用 `joinWhere()`；需要括号时使用下面的 ON 分组。
- 仅适用于 ON JOIN；初始条件为 `USING(...)` 的 JOIN 不能再追加 `joinOn()`、`joinWhere()` 或 ON 分组，否则会报错。需要追加条件时从 `join()` 开始就使用 ON 条件。

### `havingGroup($callback)` / `orHavingGroup($callback)`

```php
$builder->fields(['userId', 'SUM(amount) AS total', 'COUNT(*) AS count'])
    ->groupBy('userId')->havingGroup(function (QueryBuilder $q): void {
        $q->having('total', 1000, '>')->orHaving('count', 5, '>=');
    })->get('orders');
// HAVING (`total` > ? OR `count` >= ?)
```

- 闭包内使用 `having()`、`orHaving()`、`havingColumn()`、`havingRaw()` 或嵌套 HAVING 分组；不要在此闭包用 `where()` 代替 HAVING 条件。
- `havingGroup()` 以 AND 连接整个组，`orHavingGroup()` 以 OR 连接；作为首个 HAVING 条件时省略前置连接符。
- 闭包仅收集 HAVING 条件，不调用 `get()`、`reset()` 或设置排序、分页、JOIN 等其他内容。返回值不参与构建；空组报错，闭包异常不会追加半成品组。

### `joinWhereGroup($table, $callback)` / `joinOrWhereGroup($table, $callback)`

```php
$builder->join('users u', 'u.id=o.userId', 'LEFT')
    ->joinWhereGroup('users u', function (QueryBuilder $q): void {
        $q->where('u.status', 1)->orWhere('u.status', 2);
    })->joinOn('users u', 'u.tenantId', 'o.tenantId')->get('orders o');
// ... on u.id=o.userId AND (u.status = ? OR u.status = ?) AND `u`.`tenantId` = `o`.`tenantId`
```

- ON 分组闭包中使用 WHERE 条件方法：`where()`、`orWhere()`、`whereColumn()`、`whereRaw()`、`whereGroup()` 等；不在闭包中调用 `joinWhere()`、`joinOn()` 或 HAVING 方法。
- `$table` 的定位规则与 `joinOn()` 相同。分组位于 ON 中，LEFT JOIN 下将这些条件移到外层 WHERE 会改变保留行范围。
- 仅适用于 ON JOIN，不能向初始条件为 `USING(...)` 的 JOIN 追加分组。
- `joinWhereGroup()` 以 AND 追加整个组，`joinOrWhereGroup()` 以 OR 追加；前面的初始 JOIN 条件不会自动被包进同一个组，需确认完整 ON 的优先级。
- 闭包不构建/执行查询，仅收集条件；支持嵌套 WHERE 分组，空组或错误回调会报错且不追加半成品组。

### `insertAll($tableName, $insertData, $option = [])` 的批量校验

```php
$builder->insertAll('users', [
    ['id' => 1, 'name' => 'Alice', 'ignored' => 'x'],
    ['name' => 'Bob', 'id' => 2],
], ['field' => ['id', 'name']]);
```

- 数据必须是非空的关联行数组，每行也非空。启用 `$option['field']` 白名单时，先过滤白名单之外的字段，再按保留字段校验每行列集合一致。
- 各行可以有不同的键顺序，绑定时统一按第一行的列顺序排列；缺列、多列或过滤后为空会抛出 `InvalidArgumentException`，不会自动补 NULL。
- 显式 `null` 是实际待写入值，与缺少字段不同；是否允许该值由数据库列约束决定。字段名必须是非空字符串且不含 NUL，反引号会被转义。
- `insertAll()` 本身仍只插入数据；需要重复键更新时配合 `onDuplicate()`，或使用 `upsert()`。原有单行 `insert()` 调用继续保留。

### `upsert($tableName, array $rows, array $updateColumns)` / `onDuplicate($updateColumns)`

```php
$rows = [
    ['id' => 1, 'name' => 'Alice'],
    ['id' => 2, 'name' => 'Bob'],
];
$builder->upsert('users', $rows, ['name']);
// 相同批量语义：$builder->onDuplicate(['name'])->insertAll('users', $rows);

$builder->upsert('users', $rows, ['name' => '统一名称']);
// 所有发生冲突的行都把 name 更新为同一个绑定值。

$builder->upsert('users', $rows, ['name', 'status' => 'synced']);
// name 使用每行的新值，status 使用统一固定值。
```

- 由表的 PRIMARY KEY / UNIQUE 约束决定是否冲突；方法不创建唯一索引，也不额外查询是否存在。`upsert()` 与其他构建方法一样返回 Builder，实际执行交给 `Client::query()`。
- 字段列表 `['name']` 表示按每一行实际插入的新值更新；它使用 INSERT 行别名语法，**需要 MySQL 8.0.19 或更高版本**。批量 `onDuplicate(['name'])->insertAll()` 同样有此要求。
- 显式映射 `['name' => '统一名称']` 表示冲突时统一写入该值，不读取每行的 name。显式值也可使用 Expression 或原有 `func()` 等表达式；固定标量参数在各行插入参数之后绑定。
- `upsert()` 要求非空行数据和非空更新列，所有行的列集合一致；隐式更新列必须存在于每一行中。它不接受 `insertAll()` 的白名单选项，需要时先自行筛选行数据，或用带白名单的 `insertAll()` 配合 `onDuplicate()`。
- 旧的单行 `onDuplicate(...)->insert()` 继续保持原有构建方式；只有批量的隐式更新改为引用每行新值，不再把二维数组误读成 NULL。
- 重复键更新不是 REPLACE；不要把隐式新值更新与 `insertAll()` 的 REPLACE 选项组合使用。构建完成或 `reset()` 后，更新列设置自动清除。


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
- 参数按最终 SQL 顺序合并：SELECT、JOIN、WHERE、HAVING、UNION 各分支，再到整体 ORDER BY，不受条件方法调用先后影响。
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
- 第一个参数使用对应 `join()` 的完整表名与别名字符串，例如 `users u`；JOIN 子查询则传其别名。条件位于 JOIN 的 ON 部分，不是外层 WHERE。LEFT JOIN 中移动条件到 WHERE 可能改变保留行范围。
- 仅适用于 ON JOIN；初始条件为 `USING(...)` 时不能追加 `joinWhere()`、`joinOrWhere()`、`joinOn()` 或 ON 分组，需要追加条件时从 `join()` 开始使用 ON 条件。
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
