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
        $client->connect();
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
`get()` 和 `getOne()` 省略字段或传入 `null` 时沿用当前字段配置，显式传入 `'*'` 选择全部列。
成功执行写入语句返回 `true`；影响行数和插入 ID 分别由客户端的
`getLastAffectRows()`、`getLastInsertId()` 获取。

每次完成 SQL 构建后，查询条件、字段、排序、分页、去重及锁选项会自动重置，
已构建的 SQL 和绑定参数可通过下面的方法读取。表前缀会保留，
需要取消时调用 `setPrefix('')`。以下示例中的表名和字段请替换成实际结构。

### `tlsVersions` / `setTlsVersions(array $versions): void`

```php
use EasySwoole\Mysqli\TlsVersion;

$config->setTlsVersions([TlsVersion::Tls12, TlsVersion::Tls13]); // 等价于默认版本列表
$config->setTlsVersions([TlsVersion::Tls13]);                  // 仅允许 TLS 1.3
$config->setTlsVersions(['TLSv1.2', TlsVersion::Tls13]);        // 可混用原字符串
$config->getTlsVersions(); // 始终返回字符串数组，例如 ['TLSv1.2', 'TLSv1.3']
```

- `TlsVersion` 位于 `EasySwoole\Mysqli` 命名空间，`Tls12` / `Tls13` 对应精确字符串 `'TLSv1.2'` / `'TLSv1.3'`。配置构造参数 `tlsVersions` 与 setter 均可混用枚举、字符串；默认值和 getter 的字符串数组返回格式不变。
- setter 仍只保存列表，不立即校验版本名。getter 将枚举转换为字符串，非字符串且非 `TlsVersion` 的成员会在读取时报错；空列表、非法字符串名在启用 TLS 构建连接选项时，通过 `TlsVersion::tryFrom()` 等校验报错。原字符串不增加大小写或空白归一化。
- 只允许 TLS 1.2、TLS 1.3，不能降级到旧协议；实际可用版本取决于 Swoole/OpenSSL 编译能力及服务端配置。TLS 默认关闭，版本列表本身不会开启 TLS。
- TLS 握手使用 `connect()` 的建连预算。

更多连接配置参见 [README 的 TLS 说明](README.md#tls-加密连接默认关闭)。

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
- `ConditionValue::Raw` 只用于上述条件方法的“值”参数，不作为 INSERT/UPDATE 写入值或 Statement 绑定参数使用；不需要把布尔值转换为枚举。下文的比较、连接符枚举用于其他结构参数。

## 常用查询语法糖

查询构造器提供 `where()`、`orWhere()`、`orderBy()`、`selectForUpdate()` 等链式接口。
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

`whereIn()` / `whereNotIn()` / `whereBetween()` / `whereLike()` 的第三个 `$cond` 参数、
`whereNull()` / `whereNotNull()` / `whereExists()` / `whereNotExists()` 的第二个 `$cond` 参数
均支持 `ConditionConjunction::And` / `Or` 和原字符串，默认仍为 `'AND'`。
例如 `whereNull('deleted_at', ConditionConjunction::Or)`；旧字符串连接符行为继续保留。
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

### `where()` / `orWhere()`：比较运算符与连接符枚举

```php
use EasySwoole\Mysqli\QueryBuilder\ComparisonOperator;
use EasySwoole\Mysqli\QueryBuilder\ConditionConjunction;

$builder->where(whereProp: 'amount', whereValue: 100,
        operator: ComparisonOperator::GreaterThanOrEqual, cond: ConditionConjunction::And)
    ->where('status', 'pending', ComparisonOperator::Equal, ConditionConjunction::Or)->get('orders');
```

`$operator` 接受 `ComparisonOperator|string`，默认仍为字符串 `'='`；`$cond` 接受 `ConditionConjunction|string`，默认仍为字符串 `'AND'`。第一个 WHERE 条件不输出前置连接符；`orWhere()` 固定使用 OR。

| `ComparisonOperator` | 对应 SQL |
| --- | --- |
| `Equal` | `=` |
| `NullSafeEqual` | `<=>` |
| `NotEqual` | `<>` |
| `NotEqualAlt` | `!=` |
| `GreaterThan` | `>` |
| `LessThan` | `<` |
| `GreaterThanOrEqual` | `>=` |
| `LessThanOrEqual` | `<=` |

- `ConditionConjunction::And` / `Or` 分别对应 AND / OR。枚举用于 SQL 结构参数，不放入条件值、INSERT/UPDATE 数据或 Statement 的绑定参数。
- 普通 `where()` 仍原样保留字符串 `$operator` / `$cond` 的大小写、空白及旧扩展写法，例如 `'REGEXP'`、`'LIKE'`、`'IN'`、`'BETWEEN'`、`'XOR'`。这些 SQL 结构必须来自可信代码或白名单，字符串兼容不表示构造器已验证其完整 SQL 语法。
- `ConditionValue::Raw` 继续表示完整原始条件；PHP `null`、`true`、`false` 继续按原规则使用，不新增 NULL 或布尔值枚举，也不需要转换。比较 NULL 优先使用 `whereNull()` / `whereNotNull()`。
- `ComparisonOperator`、`ConditionConjunction`、`SortDirection`、`JoinType`、`TableLockMode`、`LockWaitPolicy` 均位于 `EasySwoole\Mysqli\QueryBuilder` 命名空间；TLS 版本枚举另见 `EasySwoole\Mysqli\TlsVersion`。
- 此次扩展保留现有参数名、默认值和返回类型，枚举与相应字符串生成相同 SQL、绑定参数及事务标记。重写相关方法的子类应核对父类的新联合参数类型；将参数仅限定为 `string` 会不兼容，需接受相应 `枚举|string`（`selectForUpdate()` 的 `$option` 还接受 `null`）。未限定参数类型的更宽声明仍可兼容。

### `having()` / `orHaving()`：聚合条件中的枚举

```php
$builder->groupBy('userId')
    ->having('SUM(amount)', 100, ComparisonOperator::GreaterThan, ConditionConjunction::And)
    ->having('COUNT(*)', 5, ComparisonOperator::GreaterThanOrEqual, ConditionConjunction::Or)->get('orders');

// 保留旧的关联数组写法；数组键中的运算符覆盖第三个参数。
$builder->groupBy('userId')->having('COUNT(*)', ['>=' => 5], ComparisonOperator::Equal)->get('orders');
```

- `$operator` / `$cond` 的枚举、字符串及默认值规则与普通 `where()` 一致；`orHaving()` 固定以 OR 连接。首个 HAVING 条件不输出前置连接符。
- 旧的关联数组条件继续使用字符串键，例如 `['>=' => 5]`；PHP 枚举对象不能作为数组键。数组内运算符仍按旧规则覆盖 `$operator`，不要同时传入互相矛盾的两套写法。
- HAVING 分组回调内使用 `having()` 等 HAVING 方法，可直接传这些枚举；值、原始条件和参数顺序规则不变。

### `join($joinTable, $joinCondition, JoinType|string $joinType = '')`

```php
use EasySwoole\Mysqli\QueryBuilder\JoinType;

$builder->join(joinTable: 'users u', joinCondition: 'u.id=o.userId', joinType: JoinType::Left)
    ->get('orders o');
$builder->join('users', '', JoinType::Natural)->get('orders');
// SELECT * FROM `orders` NATURAL JOIN users
```

| `JoinType` | 对应原字符串 |
| --- | --- |
| `Default` | `''`，输出不带类型前缀的 JOIN |
| `Inner` | `'INNER'` |
| `Left` / `Right` | `'LEFT'` / `'RIGHT'` |
| `LeftOuter` / `RightOuter` | `'LEFT OUTER'` / `'RIGHT OUTER'` |
| `Natural` | `'NATURAL'` |

- 默认仍为原字符串 `''`；既有大小写和首尾空白处理不变，例如 `' left outer '` 仍可用。固定类型字符串通过枚举的 `tryFrom()` 校验；无效类型在追加 JOIN 前报错。
- 旧字符串 `'OUTER'` 继续接受，但没有对应新增枚举；这里只保留原字符串构建兼容，最终 JOIN 组合必须符合目标数据库语法，枚举不新增 SQL 方言支持。
- `Natural` 按两侧同名列连接，`$joinCondition` 必须传空字符串 `''`，不生成 ON；不允许非空条件、USING 或追加 `joinWhere()` / `joinOn()` / ON 条件组。旧字符串 `'NATURAL'` 同样使用这条规则。
- 其他类型的 `$joinCondition` 接受 ON / USING 条件或 Expression。普通表名应用表前缀，数据库限定的表名保留原数据库和表名。
- JOIN 子查询使用带别名的 `QueryBuilder::subQuery($alias)`。构建外层语句时，读取已构建的子查询 SQL、别名和参数。IN、EXISTS 和写入值中的子查询使用无别名的 `QueryBuilder::subQuery()`。

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
- 可使用 `QueryBuilder::func()`、`now()`、`inc()`、`dec()`、`not()` 或整条查询 `raw()`。`now()` 的时间间隔为带可选正负号的整数及单位，例如 `'-2h'`。省略单位时按天计算。

### `getColumn($tableName, $columns = null, $numRows = null)` / `getScalar($tableName, $columns = null)`

```php
$builder->getColumn('users', QueryBuilder::rawExpression('COALESCE(name, ?)', ['匿名']));
$builder->getScalar('orders', QueryBuilder::rawExpression('SUM(CASE WHEN status = ? THEN amount ELSE 0 END)', ['paid']));
```

- 字段参数接受单个 SQL 表达式字符串、Expression 或 `null`。Expression 中的参数随 SELECT 合并。
- 省略字段时沿用 `fields()` 中的第一项；字符串字段列表只在顶层逗号处分隔，函数参数、引号、反引号和块注释中的逗号不会截断表达式，别名及其空白保留。Expression 原样保留绑定参数。
- `getScalar()` 固定 LIMIT 1。两个方法仍返回构造器，需交给 `Client::query()` 执行；`getScalar()` 不会把执行结果自动转换为 PHP 标量。

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

### `whereRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND')`

```php
$builder->where('tenantId', $tenantId)
    ->whereRaw('name = ? OR nickname = ?', [$keyword, $keyword])->get('users');
// WHERE `tenantId` = ? AND (name = ? OR nickname = ?)
```

- 自动给该片段添加括号，以免片段内的 OR 越过外层 AND；只传条件本身，不传 `WHERE`、前置 AND/OR 或整条 SQL。
- 第三个参数只能是 AND 或 OR（忽略大小写及两侧空白）；首个条件省略前置连接符。复杂的多层条件可与 `whereGroup()` 配合。
- SQL 片段仍必须可信，所有外部值通过 `$parameters` 绑定；不需要手动加 SQL 引号。`whereRaw()` 不替代普通 `where()` 的字段和值比较。

- `$cond` 也可传 `ConditionConjunction::And` / `Or`，例如 `whereRaw('score > ?', [100], ConditionConjunction::Or)`。字符串仍只接受 AND / OR，忽略大小写及首尾空白并通过 `tryFrom()` 校验；不继承普通 `where()` 的扩展连接符兼容。

### `havingRaw(string $sql, array $parameters = [], ConditionConjunction|string $cond = 'AND')`

```php
$builder->fields(['userId', 'SUM(amount) AS total_amount'])
    ->groupBy('userId')->havingRaw('SUM(amount) > ? OR COUNT(*) >= ?', [1000, 5])->get('orders');
```

- 生成带括号的 HAVING 条件，连接符及可信 SQL 的规则与 `whereRaw()` 相同；不传 `HAVING` 关键字。
- HAVING 参数在 WHERE 参数之后、UNION 分支及整体排序参数之前；组内筛选使用 `havingGroup()`。

- `$cond` 同样支持 `ConditionConjunction`，例如 `havingRaw('SUM(amount) > ?', [100], ConditionConjunction::Or)`；只接受 AND / OR 的严格范围保持不变。

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

```php
$builder->whereColumn('orders.paid_amount', 'orders.total_amount',
    ComparisonOperator::GreaterThanOrEqual, ConditionConjunction::And)->get('orders');
```

- `$operator` 接受 `ComparisonOperator|string`，`$cond` 接受 `ConditionConjunction|string`，默认值仍为 `'='` / `'AND'`；OR 版本同样接受比较枚举。字符串经去除首尾空白及大小写归一化后，仍严格限制为上述八种运算符和 AND / OR，不接受普通 `where()` 的扩展运算符或 XOR。

### `havingColumn($first, $second, $operator = '=', $cond = 'AND')` / `orHavingColumn()`

```php
$builder->fields(['userId', 'SUM(paid) AS paid_total', 'SUM(expected) AS expected_total'])
    ->groupBy('userId')->havingColumn('paid_total', 'expected_total', '<')->get('orders');
```

- 用于 HAVING 中两个结果字段或别名的比较；参数顺序、字段引用、运算符和 OR 版本规则与 `whereColumn()` 相同。
- 这里的两个参数都不是聚合表达式 SQL；需要直接写 `SUM(...)` 等计算时用 `havingRaw()` 或 Expression。

- `$operator` / `$cond` 也可传 `ComparisonOperator` / `ConditionConjunction`，例如 `havingColumn('paid_total', 'expected_total', ComparisonOperator::LessThan, ConditionConjunction::Or)`；字符串的严格八种比较和 AND / OR 范围与 `whereColumn()` 相同。

### `joinOn($table, $first, $second, $operator = '=', $cond = 'AND')` / `joinOrOn()`

```php
$builder->join('users u', 'u.id=o.userId', 'LEFT')
    ->joinOn('users u', 'u.tenantId', 'o.tenantId')->get('orders o');
// LEFT JOIN users u on (u.id=o.userId) AND `u`.`tenantId` = `o`.`tenantId`
```

- 给已有 JOIN 的 ON 部分追加字段比较，不创建 JOIN，也不把右字段当值绑定；参数中的两个字段遵循 `whereColumn()` 的引用规则。
- `$table` 与 `join()` 中的表名、别名写法保持一致；JOIN 子查询使用其别名定位。表前缀由构造器沿用现有规则处理，不手动重复添加。
- `joinOrOn($table, $first, $second, $operator = '=')` 追加 OR。需要与常量值比较时使用 `joinWhere()`；需要括号时使用下面的 ON 分组。
- 仅适用于 ON JOIN；初始条件为 `USING(...)` 的 JOIN 不能再追加 `joinOn()`、`joinWhere()` 或 ON 分组，否则会报错。需要追加条件时从 `join()` 开始就使用 ON 条件。

- `$operator` / `$cond` 同样接受 `ComparisonOperator` / `ConditionConjunction`，例如 `joinOn('users u', 'u.tenantId', 'o.tenantId', ComparisonOperator::Equal, ConditionConjunction::And)`；OR 版本接受比较枚举，严格范围与 `whereColumn()` 相同。

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
// ... on (u.id=o.userId) AND (u.status = ? OR u.status = ?) AND `u`.`tenantId` = `o`.`tenantId`
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

- 数据必须是非空的关联行数组，每行也非空。`$option` 为数组，只接受 `field` 和 `replace`。`field` 接受列名字符串组成的数组，与 `fields()` 同时指定时只保留交集。空白名单没有可写列。
- 各行可以有不同的键顺序，绑定时统一按第一行的列顺序排列；缺列、多列或过滤后为空会抛出 `InvalidArgumentException`，不会自动补 NULL。
- 显式 `null` 是实际待写入值，与缺少字段不同；是否允许该值由数据库列约束决定。字段名必须是非空字符串且不含 NUL，反引号会被转义。
- `replace` 接受布尔值，省略或传 `false` 生成 INSERT，传 `true` 生成 REPLACE。需要重复键更新时，配合 `onDuplicate()` 或使用 `upsert()`。

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
- `upsert()` 要求非空行数据和非空更新列。可以用 `fields()` 筛选待插入的列，筛选后每行的列集合必须一致。
- 旧的单行 `onDuplicate(...)->insert()` 继续保持原有构建方式；只有批量的隐式更新改为引用每行新值，不再把二维数组误读成 NULL。
- 按插入值更新的字段必须在过滤后保留。重复键更新仅用于 INSERT，与 REPLACE 组合会抛出 `InvalidArgumentException`。构建完成或 `reset()` 后，更新列设置自动清除。


### `whereLike($column, $pattern, $cond = 'AND')` 的字段引用

```php
$builder->whereLike('`u`.`odd.name`', '%手机%')->get('users u');
```

- 支持普通名称、别名限定名及正确加反引号的各段；带点的单个名称保留为一个标识符，不会被再次引用。不传函数或完整 SQL。
- pattern 整体绑定；`%`、`_` 仍由调用方按通配符含义提供，不自动添加或转义。大小写匹配由数据库排序规则决定。

### `fields($fields)` / `setPrefix($prefix = '')`：字段与表名引用

```php
$builder->fields(['name AS display_name', 'id'])->getColumn('users');
// SELECT name AS display_name FROM `users`

$builder->setPrefix('app_')->get('`order`');
// SELECT * FROM `app_order`
$builder->get('`archive`.`order`');
// SELECT * FROM `archive`.`order`，显式数据库限定名不再添加表前缀
```

- `fields()` 接受字符串、字段数组和 Expression；字符串中的表达式、空白和别名原样保留。它也用于 INSERT/UPDATE 的字段白名单，读取表达式与写入白名单不要混用。
- `setPrefix()` 保留到显式更改；`reset()` 不清除表前缀。普通表名、已有反引号的表名及其别名均可使用；数据库限定表名不添加前缀。
- 表名、字段表达式及原始 FROM 片段仍来自可信代码；表名前缀处理不是任意 SQL 的安全过滤器。需要带参数的派生表时使用 `fromSub()`。

### `update($tableName, $tableData, $numRows = null)`：限定字段名

```php
$builder->where('u.id', 1)->update('users u', [
    '`u`.`name`' => 'Alice',
    'u.score' => QueryBuilder::inc(1),
]);
// UPDATE users u SET `u`.`name` = ?, `u`.`score` = `u`.`score` + 1 WHERE `u`.`id` = ?
```

- UPDATE 字段支持 `字段`、`别名.字段`、`数据库.表.字段` 及正确引用的各段，已加反引号的字段不会被再次引用。名称本身包含点时用单个引用名，例如 `` `odd.name` ``。
- 使用普通查询构造器构建 UPDATE 或 DELETE。在 `QueryBuilder::subQuery()` 创建的构造器上调用 `update()` 或 `delete()` 会抛出 `LogicException`。
- `inc()`、`dec()` 和省略参数或传入 `null` 的 `not()` 引用当前字段。为 `not()` 指定其他字段时传入字符串。`func($sql, $bindParams)` 的绑定值使用数组，省略参数或传入 `null` 表示没有绑定值。

### `fromSub($subquery, $alias)` / `getFromSub($subquery, $alias, $numRows = null, $columns = null)`

```php
$totals = (new QueryBuilder())->fields(['userId'])->selectRaw('SUM(amount) AS total')
    ->where('status', 'paid')->groupBy('userId')->get('orders');
$builder->fromSub($totals, 'paid')->where('paid.total', 100, '>')->get(null);
// 等价的最终构建入口：
$builder->where('paid.total', 100, '>')->getFromSub($totals, 'paid');
```

- 子查询必须已经构建为 SELECT；普通 QueryBuilder 或 `subQuery()` 均可。`fromSub()` 保存调用时 SQL 与参数的快照，后续重建原子查询不影响外层。
- `$alias` 必须是非空名称，作为一个标识符引用；推荐简单、不带点的别名。外层条件使用该别名访问子查询返回的列。
- `fromSub()` 只设置待构建的 FROM 来源，最后使用 `get(null)`；此时传入普通表名会报错。`getFromSub()` 一次完成设置和构建，仍返回 Builder。
- 表前缀和索引提示放在子查询自己的 Builder 上；外层不能给派生表设置 FORCE/USE/IGNORE INDEX。构建完成或 `reset()` 后清除 FROM 来源。

### `count($tableName, $alias = 'aggregate')`

```php
$template = (new QueryBuilder())->where('status', 'paid')->groupBy('userId');
$counter = (clone $template)->count('orders', 'total');
$total = $client->query($counter)[0]['total'];
```

- 统计当前筛选结果集的行数；有 GROUP BY 时统计分组数，有 DISTINCT 或 UNION 时统计它们产生的结果行数。它与 `aggregate(..., 'COUNT', $column)` 的列计数含义不同。
- `groupBy()` 可使用 `WITH ROLLUP`，或在支持该语法的 MySQL 版本上使用 `ROLLUP(...)`。生成的 COUNT 包含汇总行。
- 忽略整体 ORDER BY、LIMIT 和行锁；UNION 分支已经构建的局部排序、分页仍属于分支结果，不会删除。WHERE、JOIN、HAVING、DISTINCT 和 UNION 的参数保留。
- 方法构建 COUNT SQL 并返回 Builder，不直接返回数字；默认结果列名为 `aggregate`。想保留待构建模板时先 `clone`，使用 FROM 子查询时 `$tableName` 传 `null`。
- 复杂选择列表，尤其 JOIN 的 `*` 或同名列，应先用 `fields()` 给各结果列起唯一别名，确保它们可以作为派生表列名。自定义 `$alias` 是结果列名，不是 SQL 片段。

### `aggregate($tableName, $function, $column, $alias = 'aggregate')` / `sum()` / `avg()` / `min()` / `max()`

```php
$builder->where('status', 'paid')->sum('orders', 'amount', 'paid_total');
$total = $client->query($builder)[0]['paid_total'];

$builder->fields(['userId'])->groupBy('userId')->sum('orders', 'amount', 'total');
// SELECT userId, SUM(`amount`) AS `total` FROM `orders` GROUP BY userId

$builder->aggregate('orders', 'COUNT', 'amount', 'non_null_amounts');
```

- `aggregate()` 仅接受 COUNT、SUM、AVG、MIN、MAX；四个简写方法的参数为 `($tableName, $column, $alias = 'aggregate')`。它们只构建 SQL，执行后仍返回行数组。
- `$column` 可以是按段引用的字段名或带参数 Expression；只有 `aggregate(..., 'COUNT', '*')` 将星号视为 COUNT(*)。计算表达式使用 Expression，不放进字段名字符串。
- 保留 GROUP BY、HAVING 等当前条件，并把聚合表达式追加到显式 `fields()` 后；只有隐式默认 `*` 被替换。分组查询应显式选择合法的分组字段，避免与 ONLY_FULL_GROUP_BY 冲突；显式 `fields('*')` 不会自动删除。
- 空集、NULL 和结果类型遵循数据库聚合规则；例如 SUM/AVG 空集可能返回 null，DECIMAL 结果可能是字符串，不在客户端强制转浮点数。
- 不能直接对待构建 UNION 调用这些方法。先构建 UNION，再以 `fromSub($union, 'u')->sum(null, 'u.amount')` 聚合整体结果；需要统计 UNION 行数则直接使用 `count()`。

### `reorder()`

```php
$builder->where('active', true)->orderByDesc('created_at')
    ->reorder()->orderByAsc('id')->get('users');
```

- 清除待构建查询的全部普通排序和 `orderByRaw()`，随后可重新排序；不会清除条件、字段或分页，也不修改已经构建的 SQL。

### `reset()`

```php
$builder->where('id', 1)->get('users');
$completedSql = $builder->getLastPrepareQuery();
$builder->where('active', true)->reset();
// getLastPrepareQuery() 仍为 $completedSql，待构建条件已清除。
```

- 只清除待构建的字段、条件、FROM 来源、排序、分页、索引、锁和重复键设置，保留最近一次成功构建的 SQL、绑定参数及事务操作快照。
- 表前缀和 `setLockTableMode()` 的持久表锁模式保留。构建失败不会发布半成品结果；需要放弃失败查询时调用 `reset()`，再开始新的链式构建。
- 返回类型现明确为 `QueryBuilder`。子类重写 `reset()` 时需声明兼容返回类型，并返回父类结果或 `$this`；构造器克隆不要求额外调用父级 `__clone()`。

### `raw($sql, $param = [])`

```php
$builder->where('unused', 7)->raw('SELECT ? AS value', [99]);
// SQL：SELECT ? AS value；参数：[99]
```

- `$sql` 为单条可信 SQL 字符串。`$param` 为位置参数数组，按传入顺序绑定，默认空数组。构建成功后清除待构建设置。
- 构建后交给 `Client::query()` 执行。`getLastQuery()` 在读取时生成调试 SQL，仅供查看。多语句和 CALL 限制同样适用。


### `setQueryOption($options)`：语句选项

```php
$builder->setQueryOption('IGNORE')->where('id', 1)->update('users', ['name' => 'Alice']);
$builder->setQueryOption(['LOW_PRIORITY', 'IGNORE'])->insert('users', ['id' => 2]);
```

- 选项接受一个字符串或字符串数组。构建时按语句类型校验，并按 MySQL 语法顺序输出。
- `IGNORE` 用于 INSERT、UPDATE 和 DELETE，`QUICK` 用于 DELETE，`DISTINCT` 用于 SELECT。语句不支持的选项或互斥选项会在构建时抛出 `InvalidArgumentException`。

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

### `orderBy($orderByField, SortDirection|string $orderbyDirection = 'DESC', $customFieldsOrRegExp = null)`

```php
$builder->where('tenantId', $tenantId)->orderBy('name', 'DESC', "O'Reilly")->get('users');
// WHERE `tenantId` = ? ORDER BY name REGEXP ? DESC
// 参数：[$tenantId, "O'Reilly"]

$builder->orderBy('status', 'ASC', ['awaiting/payment', 'paid+confirmed'])->get('orders');
// ORDER BY FIELD (status, ?, ?) ASC
// 参数：['awaiting/payment', 'paid+confirmed']
```

- 第三个参数为字符串时按正则表达式值绑定，不直接拼进 SQL；不手动添加 SQL 引号或转义单引号。无效正则由数据库报错。
- 第三个参数为非空数组时生成 FIELD 自定义顺序，所有成员按原值绑定，不过滤引号、斜杠、加号等字符。成员只能是标量或 null；空数组、嵌套数组、对象会在修改排序前报错。
- 参数按最终 SQL 顺序合并：SELECT、JOIN、WHERE、HAVING、UNION 各分支，再到整体 ORDER BY，不受条件方法调用先后影响。
- 同一字段加同一正则重复设置时覆盖方向，不同正则保留为不同排序项；构建完成后自动重置。
- `orderBy()` 和 `groupBy()` 按传入的文本拼接表达式。字段名使用代码常量或白名单。使用表别名时按 SQL 中的别名限定字段，引用带前缀的实际表时填写完整表名。

```php
use EasySwoole\Mysqli\QueryBuilder\SortDirection;

$builder->orderBy(orderByField: 'created_at', orderbyDirection: SortDirection::Desc)->get('orders');
$builder->orderBy('name', SortDirection::Asc, '^A')->get('users');
```

- `$orderbyDirection` 接受 `SortDirection|string`，`Asc` / `Desc` 对应 ASC / DESC；默认仍为字符串 `'DESC'`。`orderByAsc()` / `orderByDesc()` 原调用保持不变。
- 字符串仍忽略大小写并去除首尾空白，例如 `' asc '`；固定方向通过 `SortDirection::tryFrom()` 校验，无效方向在覆盖已有排序项前报错。自定义排序、绑定值及排序顺序保持原规则。

### `union($query, $isUnionAll = false)`

```php
$archive = (new QueryBuilder())->where('status', 'closed')->orderByDesc('id')->get('archive', 2, 'id');
$builder->where('status', 'open')->union($archive)->orderByDesc('id')->get('orders', 10, 'id');
// SELECT id FROM `orders` WHERE `status` = ?
// UNION (SELECT id FROM `archive` WHERE `status` = ? ORDER BY id DESC LIMIT 2)
// ORDER BY id DESC LIMIT 10
```

- `$isUnionAll` 接受布尔值，默认 `false` 生成 UNION，传 `true` 生成 UNION ALL。
- 行锁设在独立的 SELECT 分支上，构建后再传给 `union()`。为整个 UNION 设置行锁会抛出 `InvalidArgumentException`。
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

- `null` 表示使用此前的 `limit()` 设置，`0` 表示限制为零行。LIMIT 数值使用非负整数或十进制数字字符串，偏移分页使用 `[偏移量, 条数]`。
- 未指定条数且未设置 `limit()` 才不限制条数；SELECT 的偏移分页 `[0, 0]` 返回零行。
- 单表 UPDATE 和 DELETE 可用 `limit(5)` 限制行数。带 JOIN 的 UPDATE 和 DELETE 不支持 ORDER BY 或 LIMIT，写入语句也不支持带偏移量的 LIMIT。这些组合会在构建时抛出 `InvalidArgumentException`。
- `get(null)` 仅在此前设置 `fromSub()` 时有效；设置派生表来源后不再传普通表名。普通查询的表名必须是非空字符串。

### `joinWhere()` / `joinOrWhere()` 的 NULL 判断

```php
$builder->join('users u', 'u.id=o.userId', 'LEFT')
    ->joinWhere('users u', 'u.deleted_at', null, 'IS')->get('orders o');
// LEFT JOIN users u on (u.id=o.userId) AND u.deleted_at IS NULL
```

- 追加 ON 条件时，`join()` 的初始条件会整体加括号。字符串与 `Expression` 使用相同分组。
- NULL 判断使用 `'IS'` 或 `'IS NOT'`，不使用默认的 `'='`；无需在运算符前手动添加空格。
- 第一个参数使用对应 `join()` 的完整表名与别名字符串，例如 `users u`。JOIN 子查询传其别名。构建时，找不到对应 JOIN 或定位到多个 JOIN 会抛出 `InvalidArgumentException`。
- 条件位于 JOIN 的 ON 部分。LEFT JOIN 中将条件移到 WHERE 可能改变保留行范围。
- 仅适用于 ON JOIN；初始条件为 `USING(...)` 时不能追加 `joinWhere()`、`joinOrWhere()`、`joinOn()` 或 ON 分组，需要追加条件时从 `join()` 开始使用 ON 条件。
- 混用 AND/OR 时仍遵循 SQL 优先级，需确认完整 ON 表达式的含义。

```php
$builder->join('users u', 'u.id=o.userId', JoinType::Left)
    ->joinWhere('users u', 'u.active', true, ComparisonOperator::Equal, ConditionConjunction::And)
    ->get('orders o');
```

- `$operator` / `$cond` 接受 `ComparisonOperator|string` / `ConditionConjunction|string`，默认仍为 `'='` / `'AND'`；普通字符串保留原大小写、空白及扩展语法兼容。`joinOrWhere()` 仍固定 OR，保留的 `$cond` 参数不改变其 OR 行为。NULL、布尔值及显式 `ConditionValue::Raw` 的含义不变。

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

行锁用于 SELECT，应在事务中执行。同时设置 `FOR UPDATE` 和 `LOCK IN SHARE MODE` 会在构建时抛出 `InvalidArgumentException`。优先用下述枚举表达等待策略。自定义字符串尾部必须来自可信代码并符合服务端语法。

```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

// FOR UPDATE 排它锁。下面两个方法效果相同
$builder->setQueryOption("FOR UPDATE")->get('getTable');
$builder->selectForUpdate(true)->get('getTable');

// FOR UPDATE NOWAIT
$builder->selectForUpdate(true, 'NOWAIT')->get('getTable');
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

### `selectForUpdate($isLock = true, LockWaitPolicy|string|null $option = null)`

```php
use EasySwoole\Mysqli\QueryBuilder\LockWaitPolicy;

$builder->selectForUpdate(isLock: true, option: LockWaitPolicy::Nowait)->get('orders');
$builder->selectForUpdate(true, LockWaitPolicy::SkipLocked)->get('orders');
// 保留 FOR UPDATE，显式清除先前的 SKIP LOCKED / NOWAIT。
$builder->skipLocked()->selectForUpdate(true, LockWaitPolicy::Default)->get('orders');
```

- `selectForUpdate()` 和 `lockInShareMode()` 的 `$isLock` 接受布尔值，默认 `true`。`Default` 不附加等待选项，`Nowait` 对应 NOWAIT，`SkipLocked` 对应 SKIP LOCKED。
- 显式枚举 `Default` 会清除已设置的等待策略；兼容旧调用，省略 `$option`、传 `null` 或字符串 `''` 不覆盖已有策略。`selectForUpdate(false)` / `forUpdate(false)` 取消排他锁及等待选项。
- 已知策略字符串继续接受，并按大小写无关及去除首尾空白的结果映射，例如 `' nowait '` 输出 NOWAIT。未知字符串尾部按旧接口原样保留，例如已有的 `OF ...` 片段；只能来自可信代码，是否能执行由目标服务端决定，不代表支持了新的数据库方言。
- 方法仅构建 SQL，不开启事务或立即申请锁；在同一客户端连接的业务事务中执行。SELECT 锁标记和等待策略在构建完成或 `reset()` 后清除。

### `setSelectLockOption(LockWaitPolicy|string $option)`

```php
$builder->setSelectLockOption(LockWaitPolicy::SkipLocked)->forUpdate()->get('orders');
$builder->skipLocked()->setSelectLockOption(LockWaitPolicy::Default)->get('orders');
```

- 只设置等待策略，不自动开启 FOR UPDATE；可与 `forUpdate()` / `selectForUpdate()` 配合。枚举 `Default` 或字符串 `''` 在本方法中都会清除旧策略。
- 已知策略字符串归一化及未知可信尾部的兼容规则与 `selectForUpdate()` 相同；不要将请求参数直接用作锁选项。

### `setLockTableMode(TableLockMode|string $method)` / `lockTable($table)`

```php
use EasySwoole\Mysqli\QueryBuilder\TableLockMode;

$builder->setLockTableMode(method: TableLockMode::Write)->lockTable(['users', 'orders']);
$builder->setLockTableMode(TableLockMode::Read)->lockTable('users');
```

- `Read` / `Write` 分别对应 READ / WRITE；未设置时仍使用 READ。`lockTable()` 生成表锁语句，表锁与 SELECT 行锁及其等待策略是不同设置。
- 用 `Client::query()` 执行 `lockTable()` 和 `unlockTable()` 构建的命令，客户端通过文本协议发送。
- 字符串继续忽略大小写，但沿用旧规则不去除首尾空白：`'read'` 有效，`' read '` 无效。固定字符串通过 `TableLockMode::tryFrom()` 校验，无效值不会覆盖此前模式。
- 可传入单个表名或非空表名数组，数组按值的顺序处理。普通表名应用表前缀，数据库限定表名按原数据库和表名引用。
- 表锁模式保留到下一次显式设置。复用构造器时，可调用 `setLockTableMode(TableLockMode::Read)` 切回 READ。

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

`raw()` 的参数替换及状态清理规则见前文对应方法；它不会继承旧查询或失败编译的参数。`getLastQuery()` 只用于查看和调试；执行时使用预处理 SQL 和绑定参数。

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

## 分页、批处理与流式读取

以下示例在 Swoole 协程内使用已经创建的 `$client`；条件模板均尚未调用 `get()`。

### `Client::paginate($query, $table, $perPage = 20, $page = 1, $timeout = null)`

```php
$template = (new QueryBuilder())->where('status', 'paid')->orderByAsc('id');
$page = $client->paginate($template, 'orders', perPage: 20, page: 2, timeout: 3.0);
// ['items' => [...], 'total' => 42, 'page' => 2, 'perPage' => 20, 'lastPage' => 3]
```

- 在独立副本上先构建 COUNT 和当前页语句，再依次执行。两条语句的 SQL 和参数在首次查询前确定。COUNT 采用 `count()` 的结果集行数规则，带 FROM 子查询时将 `$table` 传 `null`。
- `$page`、`$perPage` 必须为正整数，偏移超出 PHP 整数范围时报错；页码从 1 开始，不自动调整超出末页的请求。`total = 0` 时 `lastPage = 1`。
- `total` 和 `lastPage` 超过 PHP_INT_MAX 时返回十进制字符串，保留大数精度；业务不要强制转换为 int 或 float。
- 页码和偏移只在入口校验一次，计数直接按组件生成的 COUNT 结果读取，不重复审查结果结构。
- COUNT 和当前页查询共用一次总超时预算，涵盖预处理、执行、读取和清理。
- 两次查询不会自动开启事务。需要相同数据快照时，由调用方在同一个 Client 上开启并结束适当事务；否则并发写入可能使 total 与 items 对应不同时间点。
- 使用包含唯一键的稳定排序。深页仍使用 OFFSET；客户端最后的影响行数是当前页查询的行数，不是 total。

### `Client::iterate($builder, $timeout = null)`

```php
$builder = (new QueryBuilder())->where('active', true)->orderByAsc('id')->get('users');
$stream = $client->iterate($builder, 30.0);
try {
    foreach ($stream as $row) { processUser($row); }
} finally {
    $stream->close();
}
```

- `$builder` 必须已经构建。方法在准备语句前读取 SQL 和参数，返回 `ResultStream` 供逐行读取。将结果转成数组仍会占用完整结果所需内存。
- Prepare、Execute、逐行读取和自动 Statement 清理共用一个总超时。消费者处理每行的时间也计入该预算，处理较慢时相应设置超时或改用分批读取。
- 完整读到 EOF 后保留连接，并自动释放该方法创建的 Statement；提前 break、消费者抛异常、显式关闭或丢弃未读流会关闭连接，避免残留协议数据。
- 流结束前连接保持独占，不能在同一 Client 上发起查询、Prepare、事务或关闭连接。需要并行操作时使用其他 Client；其他协程正在 fetch 时，调用流的 `close()` 会抛并发异常，等本次读取结束后再关闭。
- 创建流时客户端插入 ID/影响行数清空；完整 SELECT 读取后 `getLastAffectRows()` 为已读取行数，`getLastInsertId()` 同步服务端元数据。提前停止或读取失败不发布成功元数据。
- `onQuery()` 仅在完整耗尽和清理成功后收到 `true`，不再传结果数组；提前停止或读取失败不会触发成功回调。

### `Client::rawIterate($query, $timeout = null)`

```php
$stream = $client->rawIterate('SELECT id, name FROM users ORDER BY id', 30.0);
try {
    foreach ($stream as $row) { processUser($row); }
} finally {
    $stream->close();
}
```

- 使用文本协议，参数是整条可信 SQL，不另接收绑定数组；需要参数绑定时使用 `Client::iterate($builder)` 或 `Statement::iterate()`。
- 连接独占、单次遍历、总超时、提前退出关闭连接和 EOF 成功回调规则与 `Client::iterate()` 一致；CALL 和多结果集仍不支持。
- 流导致连接关闭后，下一次查询前须显式 `connect()`。若同时丢失事务，先 `close()` 再 `connect()`，由业务决定如何恢复。

### `Statement::iterate($parameters = [], $timeout = null)`

```php
$statement = $client->prepare('SELECT id, name FROM users WHERE tenantId = ? ORDER BY id');
try {
    foreach ([7, 8] as $tenantId) {
        $stream = $statement->iterate([$tenantId], 30.0);
        try { foreach ($stream as $row) { processUser($row); } }
        finally { $stream->close(); }
    }
} finally {
    $statement->close();
}
```

- 参数数量、参数编码和所属会话校验与 `execute()` 一致。完整读取后 Statement 仍可再次执行，不自动释放；调用方最终负责 `close()`。
- Prepare 是此前独立操作；这里的超时覆盖本次 Execute 与逐行消费。连接断开或流提前停止后，旧 Statement 不再可复用，需要重新 Prepare；事务丢失保护优先适用。
- 通过 Client 创建的 Statement 在完整 EOF 后更新该 Client 的元数据；直接调用 Statement 不触发 Client 的 `onQuery()`，与手工 `execute()` 的回调范围一致。

### `ResultStream::close()` / `isClosed()` / `isComplete()` / `getRowsRead()`

```php
$stream = $client->rawIterate('SELECT id FROM users ORDER BY id');
$iterator = $stream->getIterator();
try {
    foreach ($iterator as $row) {
        if (shouldStop($row)) { break; }
    }
} finally {
    $stream->close(); // 显式保存迭代器时，不能只依赖 foreach break。
}
```

- 流只能遍历一次，不能 rewind 后重读；`isComplete()` 仅表示已经读到 EOF，`isClosed()` 也可能表示提前关闭或失败。`getRowsRead()` 返回流已经交付的行数。
- 流式读取避免累积所有行，但单行及其逻辑数据包仍需完整解码；一个超大 BLOB 本身仍可能需要较多内存。
- 普通 `foreach ($stream as $row)` 提前退出会自动关闭；若显式持有 `getIterator()` 的返回对象，结束后必须主动 `close()`，不要等待迭代器最终销毁。
- 正常结束后的 `close()` 可重复调用；其他协程正在读取该流时不能关闭。事务中提前关闭的恢复规则见 `rawIterate()`，对所有流入口都适用。


## 内部实现与兼容性

公开 `QueryBuilder` 负责链式入口，待构建状态按 `PendingQuery` 定义以数组写时复制保存，成功构建的结果以只读 `CompiledQuery` 保存，并由 `reset()` 发布。SELECT 语句头、COUNT 来源和聚合由 `SelectPlanner` 规划。写入语句头和子句顺序由 `QueryCompiler` 处理，批量选项、列对齐和值绑定由 `WriteCompiler` 处理。
条件、写入、SELECT 子句、参数缓冲、表名/索引、表达式和调试格式化各自独立；完整职责表见 README 的“QueryBuilder 内部职责”。
普通链式方法声明返回 `QueryBuilder`。`update()` 和 `delete()` 声明返回 `?QueryBuilder`，成功时返回构造器。构建事务 SQL 的三个方法返回 `void`。子类重写方法时须声明兼容的返回类型，例如 `QueryBuilder` 或协变的 `static`，并接受父类支持的参数。COUNT 和聚合使用子类克隆及公开方法，工厂返回子类实例。
内部协作类带 `@internal` 标记，不承诺业务层直接依赖它们的兼容性。
