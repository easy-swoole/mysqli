# EasySwoole 协程 MySQLi

基于 `Swoole\Coroutine\Socket` 实现的 MySQL 协议客户端，支持连接超时、单次查询的总超时、文本查询、原生服务端预处理语句，并提供 FastDb 风格的查询及事务接口；继承接口的适配要求见下文。

## 环境要求

- PHP 8.1 或更高版本。
- Swoole 5.1 或更高版本。
- OpenSSL、Sockets 和 zlib 扩展。
- **MySQL 服务端需要 8.0。**

**当前暂不支持 TLS/SSL 加密连接，数据库通信使用未加密的 TCP。** 无法连接要求 TLS/SSL 的 MySQL 服务端或账号；OpenSSL 扩展用于认证相关的加密操作，不代表连接已启用 TLS。

## 使用示例

`Client::connect(?float $timeout = null)` 始终使用构造函数传入的 `Config` 建立连接，不接受配置数组。可选参数仅指定本次建连的总超时（秒），不会修改配置；省略或传入 `null` 时使用 `maxConnectTime`。显式超时仍受 `maxConnectTime` 上限约束，以先到期的截止时间为准。查询方法会在需要时自动建连，也可以主动调用 `connect()`。

数据库操作需要在 Swoole 协程中执行：

```php
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use Swoole\Coroutine;

Coroutine\run(function (): void {
    $client = new Client(new Config([
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'test',
        'password' => 'secret',
        'database' => 'test',
        'maxConnectTime' => 1.0,
        'timeout' => 2.0,
        'charset' => 'utf8mb4',
        'compress' => true, // 协商启用 MySQL 经典协议的 zlib 压缩
    ]));

    // 第二个参数为本次查询的总超时，包含必要的建连和结果读取。
    $rows = $client->rawQuery('SELECT * FROM student WHERE id = 1', 0.5);

    $statement = $client->prepare('SELECT * FROM student WHERE id = ?');
    try {
        $rows = $statement->execute([1], 0.5);
    } finally {
        $statement->close();
    }

    $client->close();
});
```

## 压缩与接口兼容

`compress` 默认为 `false`。启用后，客户端通过 `CLIENT_COMPRESS` 协商压缩；如果服务端支持，则在认证成功后启用 zlib 压缩。如果服务端不支持，连接仍以未压缩方式继续使用。可以通过 `$client->mysqlClient()->isCompressionEnabled()` 查看实际协商结果。

`Client::query(QueryBuilder $builder, ?float $timeout = null): bool|array` 自动使用服务端预处理协议；`rawQuery(string $query, ?float $timeout = null)` 使用文本查询协议。两者显式声明可选超时参数，支持 `timeout: 0.5` 这样的命名参数。

继承 `Client` 的类（包括 FastDb 连接类）如果重写了 `query()` 或 `rawQuery()`，需要同步接受可选超时参数并传给父类；重写 `query()` 时还需要声明兼容的返回类型。旧的单参数子类方法签名不能继续使用。`mysqlClient()` 仅返回协议层 `Connection` 或 `null`，不再包含原生 `mysqli` 类型。

查询超时后，客户端会关闭连接，避免复用存在未读 MySQL 数据包的协议流。未处于事务时，下一次查询会自动重新连接。

## 会话安全与超时

预处理语句属于创建它的服务端会话。该会话断开或重连后，执行旧 Statement 会抛出 `LogicException`，需要重新 Prepare；事务丢失保护生效时优先抛出 `TransactionLostException`。关闭已失效的 Statement 不会关闭新会话中的语句。

连接通过服务端 OK/EOF 数据包的 `SERVER_STATUS_IN_TRANS` 跟踪事务，支持 `BEGIN`、`START TRANSACTION`、事务接口以及 `autocommit=0` 下实际开始的事务。可以通过 `$client->mysqlClient()->inTransaction()` 查看最近确认且连接仍有效的事务状态，通过 `isTransactionLost()` 查看事务是否因连接丢失而失效。普通 SQL 错误不会直接清除事务状态；InnoDB 死锁错误 `1213` 会同步清除已自动回滚的事务状态，锁等待超时 `1205` 则保留事务状态；提交、回滚及 DDL 隐式提交以服务端响应为准。

事务期间断线或查询超时，首次操作仍抛出原始连接/超时异常；后续查询、Prepare、旧 Statement 执行、Connect、Commit 和 Rollback 会抛出 `EasySwoole\Mysqli\Exception\TransactionLostException`，阻止透明重连后继续执行。此标记在显式 `close()` 前一直保留，关闭 Statement 不会解除保护。

恢复时先调用 `$client->close()`，再连接并开启新的事务，由业务决定是否重试整个事务。如果在 Commit 响应到达前断线，提交结果可能无法确认，不能仅凭此异常自动重放写入。

`begin_transaction(TransactionStartFlags $flags = TransactionStartFlags::None)` 使用 `EasySwoole\Mysqli\Transaction\TransactionStartFlags` 枚举。支持 `None`（默认）、`ConsistentSnapshot`、`ReadWrite`、`ReadOnly`、`ConsistentSnapshotReadWrite` 和 `ConsistentSnapshotReadOnly`。快照与访问模式通过组合枚举值表达，无需按位或；不再接受整数，旧调用需迁移为枚举，否则抛出 `TypeError`。例如：

```php
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;

$client->mysqlClient()->begin_transaction();
$client->mysqlClient()->begin_transaction(TransactionStartFlags::ReadOnly);
$client->mysqlClient()->begin_transaction(TransactionStartFlags::ConsistentSnapshotReadWrite);
```

`commit()`、`rollback()` 同样使用 `EasySwoole\Mysqli\Transaction\TransactionCompletionFlags` 枚举，默认 `None`。支持 `Chain`、`NoChain`、`Release`、`NoRelease`，以及组合值 `ChainNoRelease`、`NoChainRelease`、`NoChainNoRelease`。不再接受整数或按位或组合，旧整数调用会抛出 `TypeError`。枚举仅列出合法选项，不提供相互矛盾的组合。

```php
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;

$client->mysqlClient()->commit(TransactionCompletionFlags::ChainNoRelease);
$client->mysqlClient()->rollback(TransactionCompletionFlags::NoChainNoRelease);
$client->mysqlClient()->commit(TransactionCompletionFlags::Release);
```

CHAIN 后连接仍处于新事务；RELEASE 成功后本地连接同步关闭。`None` 不追加选项，由服务端 `completion_type` 决定默认结束方式；需要明确结束且保留连接时使用 `NoChainNoRelease`。选项语义参照 [MySQL 事务语法](https://dev.mysql.com/doc/refman/8.0/en/commit.html)。

文本查询和预处理结果中的 DATE 均返回 `YYYY-MM-DD`，DATETIME/TIMESTAMP 均返回完整的 `YYYY-MM-DD HH:MM:SS`。TIME、DATETIME、TIMESTAMP 按字段小数精度（0～6）保留相应位数，包括值为零的小数，例如 TIME(3) 的 `00:00:00.000`。TIME 保留负号和超过 24 小时的小时数，NULL 仍返回 null。

同一个连接同时只允许一个操作，包括建立连接、关闭 Statement 和关闭连接。并发调用会抛出异常，正在进行的操作仍可继续完成。可以在当前操作结束后重试关闭 Statement；需要并发查询时，应使用不同的客户端连接。

`maxConnectTime` 限制完整连接过程的总耗时，包括 TCP 建连、握手、认证和字符集设置。`rawQuery()`、`query()` 和 `prepare()` 的超时均包含必要的连接过程；`query()` 的 Prepare、Execute、结果读取及 Statement 清理共用同一个超时预算。连接过程同时受 `maxConnectTime` 限制，以先到期的截止时间为准。

超时值必须大于零。如果 Statement 清理阶段的查询预算已经耗尽，客户端会直接丢弃连接，不再为清理操作分配新的超时预算。

## 存储过程限制

本项目以生产应用中常见的普通 SQL、预处理查询和应用层事务为主要使用方式；在这类项目中，存储过程的使用相对较少，因此客户端暂不支持存储过程调用及多结果集。

`rawQuery()`、`query()`、`prepare()` 及协议连接入口会在发送调用 SQL 前拒绝 `CALL`，抛出 `EasySwoole\Mysqli\Exception\UnsupportedOperationException`。判断支持大小写、前导空白、普通注释及 MySQL 可执行注释；拒绝后原连接仍可继续使用。多结果及分号多语句能力保持关闭。

此限制仅针对本客户端调用存储过程，不修改服务端配置，也不影响其他客户端。`CREATE PROCEDURE`、`DROP PROCEDURE` 等管理语句及普通 SELECT 中的存储函数不在 CALL 限制范围内。

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

### 通用调用规则

- 先调用条件、排序、索引及锁方法，最后调用 `get()`、`getOne()`、`update()` 等构建方法，再交给 `$client->query($builder)` 执行。构建方法本身不访问数据库。
- `get()` 等方法完成构建时就会清除当前设置，并非等到执行后才清除；已生成的 SQL 和绑定参数保留。表前缀不会自动清除。
- 字段名、索引名、SQL 表达式和 AND/OR 连接符使用代码常量或白名单；参数绑定只保护条件值，不保护 SQL 结构。
- 集合、范围和 LIKE 方法的第三个参数是 `$cond`；NULL 和 EXISTS 方法的第二个参数是 `$cond`，默认均为 `'AND'`。只传 `'AND'` 或 `'OR'`，首个条件的连接符自动省略。
- AND/OR 仍遵循 SQL 的优先级，需要括号时使用条件组。这些 WHERE 方法也可用于 UPDATE、DELETE，写入前应确认条件范围。

### `forceIndex($indexes)`

```php
$builder->forceIndex('idx_userId')->where('userId', $userId)->get('order_mapping', 10);
$builder->forceIndex(['idx_userId', 'idx_mapId'])->get('order_mapping');
```

- 只作用于本次 SELECT 的主表，提示放在表名及别名之后、JOIN 之前；不作用于 JOIN 表或 UPDATE、DELETE 的目标表。
- 传单个索引名或名称数组，不传逗号分隔的 SQL。名称自动用反引号引用，名称内部的反引号会被转义；空白名称、非字符串数组元素和 NUL 字节会抛出 `InvalidArgumentException`。
- 重复调用覆盖本类提示；`forceIndex([])` 清除 FORCE 设置。非空 `forceIndex()` 会清除已有 USE 设置，`ignoreIndex()` 设置保留。
- 使用实际存在的索引；构造器不检查索引是否存在，也不保证强制索引能改善性能。已有表名 SQL 片段中带索引提示时，不要再为该表叠加此方法。
- 子查询中调用时只作用于该子查询的主表，不会传递到外层。

### `useIndex($indexes)`

```php
$builder->useIndex(['idx_userId', 'idx_mapId'])->get('order_mapping');
```

- 参数格式、名称引用、异常及 SELECT 主表作用范围与 `forceIndex()` 相同；它是索引使用建议，不保证服务端最终采用指定索引。
- 重复调用覆盖 USE 列表；非空设置会清除 FORCE 列表，可搭配 `ignoreIndex()`。
- `useIndex([])` 只清除 USE 设置，不生成 MySQL 的 `USE INDEX ()`，也不会恢复此前被覆盖的 FORCE 列表。
- 构建完成后自动清除；主表与子查询的设置各自独立。

### `ignoreIndex($indexes)`

```php
$builder->ignoreIndex('idx_status')->where('status', 1)->get('orders');
```

- 参数格式、名称引用、异常及作用范围与 `forceIndex()` 相同，表示排除指定索引。
- 可与 USE 或 FORCE 搭配；不要同时要求使用和忽略同一个索引。
- 重复调用覆盖 IGNORE 列表；`ignoreIndex([])` 清除本类提示，不改变 USE 或 FORCE 设置。构建完成后自动清除。

### `whereIn($column, $values, $cond = 'AND')`

```php
$builder->whereIn('id', [1, 2, 3])->get('orders');
$sub = QueryBuilder::subQuery()->where('active', 1)->get('users', null, 'id');
$builder->whereIn('userId', $sub)->get('orders');
```

- 接受值数组或已经调用 `get()` 等完成构建的 `QueryBuilder::subQuery()`；普通 Builder 或未构建子查询会抛出 `InvalidArgumentException`。
- 数组元素逐个绑定，不传 `'1,2,3'` 这样的字符串；子查询应只选择一列，且不要设置结果别名。
- 空数组生成 `0 = 1`，不是忽略条件；与其他条件使用 OR 连接时，最终范围仍取决于完整表达式。
- 数组包含 `null` 不等于额外匹配 NULL 行；需要时另加 `whereNull()` 并通过条件组表达 OR。
- 子查询在外层构建前不要重建或修改，外层构建时才读取它的 SQL 和参数。
- 旧写法 `where('id', [], 'IN')` 保持原行为，空数组保护仅适用于此新方法。

### `whereNotIn($column, $values, $cond = 'AND')`

```php
$builder->whereNotIn('status', ['cancelled', 'closed'])->get('orders');
```

- 参数和子查询要求与 `whereIn()` 相同；子查询只选一列、不带结果别名，并在外层构建前保持不变。
- 空数组生成 `1 = 1`。用于 UPDATE、DELETE 时，它本身不会缩小操作范围；尤其注意与 OR 的组合。
- 列表或子查询结果包含 NULL 时，遵循 SQL 的 NULL 比较规则，可能导致原本预期保留的行也不返回；需要排除集合中的 NULL，或明确设计 NULL 条件。
- 旧的 `where(..., 'NOT IN')` 不增加空数组保护。

### `whereNull($column, $cond = 'AND')`

```php
$builder->whereNull('deleted_at')->get('orders');
```

- 生成 `IS NULL`，不绑定值；匹配数据库 NULL，不匹配空字符串、字符串 `'NULL'` 或数值 0。
- `$cond` 是第二个参数，例如 `whereNull('deleted_at', 'OR')`。需要与其他条件组成一个整体时放入 `whereGroup()`。
- 该方法不改变旧的 `where($column, null)` 行为；判断 NULL 时优先使用明确的 NULL 方法。

### `whereNotNull($column, $cond = 'AND')`

```php
$builder->whereNotNull('paid_at')->get('orders');
```

- 生成 `IS NOT NULL`，不绑定值；空字符串和 0 仍可能满足条件。如果还要排除这些值，需要另加条件。
- `$cond` 是第二个参数；字段名使用固定值或白名单。

### `whereBetween($column, [$min, $max], $cond = 'AND')`

```php
$builder->whereBetween('amount', [100, 500])->get('orders');
```

- 数组必须恰好两个值，否则抛出 `InvalidArgumentException`；按传入顺序绑定，不自动排序边界，也不检查大小关系。
- 包含两端边界，不能表达“含起点、不含终点”。时间范围需要半开区间时使用原有 `where('created_at', $start, '>=')->where('created_at', $end, '<')`。
- 使用与字段类型匹配的值；日期时间字符串的格式、精度和时区由调用方统一。NULL 边界遵循 SQL 的 NULL 比较规则。

### `whereLike($column, $pattern, $cond = 'AND')`

```php
$builder->whereLike('u.name', '%手机%')->get('users u');
```

- 字段只传未加反引号的普通名称或 `别名.字段名`，方法自动逐段引用；不传 `LOWER(name)` 等表达式或完整 SQL。
- 不自动添加 `%`，也不转义 `%`、`_`；它们仍是通配符。要按字面量搜索这些字符时，由调用方按会话的 SQL 转义规则处理。
- pattern 整体通过参数绑定，不需要手动添加 SQL 引号；字符串 `'DBNULL'` 也会正常绑定。
- 是否区分大小写由字段和表达式的排序规则决定，前置 `%` 的匹配需要关注查询成本。

### `whereExists($subquery, $cond = 'AND')`

```php
$sub = QueryBuilder::subQuery()
    ->where('payments.orderId = orders.id')
    ->where('status', 'paid')->get('payments', null, '1');
$builder->whereExists($sub)->get('orders');
```

- 必须传已构建且不带结果别名的 `QueryBuilder::subQuery()`，否则抛出 `InvalidArgumentException`。`get(..., null, '1')` 可明确表达只检查存在性。
- EXISTS 只判断有无记录；若要检查“当前外层记录”的关联数据，必须显式写关联条件。没有关联条件时，只判断整个子查询是否有记录。
- 关联列比较应使用代码中固定的 SQL 表达式，如示例的单参数 `where()`；`where('payments.orderId', 'orders.id')` 会把后者当字符串值绑定。
- 内外层别名要与各自 SQL 一致。子查询在外层构建前不要重建或修改，参数会在外层构建时按 SQL 顺序合并。

### `whereNotExists($subquery, $cond = 'AND')`

```php
$builder->whereNotExists($sub)->get('orders');
```

- 子查询、别名、关联条件及参数合并要求与 `whereExists()` 相同；这里的 `$sub` 可使用上一节构建的子查询。
- 表示子查询没有符合条件的记录；没有关联条件时，可能使整个外层查询全部满足或全部不满足，而不是逐条判断关联关系。
- `$cond` 是第二个参数。用于写入时，应先确认关联条件和最终 WHERE 范围。

### `whereGroup($callback)`

```php
$builder->where('tenantId', $tenantId)
    ->whereGroup(function (QueryBuilder $q): void {
        $q->where('status', 1)->orWhere('status', 2);
    })->get('orders');
// WHERE tenantId = ? AND (status = ? OR status = ?)
```

- 闭包拿到一个独立 Builder，只有其中的 WHERE 条件被收集；支持继续嵌套 `whereGroup()`、`orWhereGroup()`。
- 在闭包内调用条件方法，不调用 `get()`、`reset()` 等构建/清理方法，也不设置字段、JOIN、排序、分页、索引或锁；这些设置不会合并到外层。
- 闭包返回值不参与构建，不需要返回 Builder。空条件组会抛出 `InvalidArgumentException`；闭包抛异常时，整个组不会加入外层，外层此前条件保留。
- 默认以 AND 连接前面的条件；它是首个条件时不添加前置 AND。组内绑定参数保持 SQL 出现顺序。

### `orWhereGroup($callback)`

```php
$builder->where('status', 'pending')
    ->orWhereGroup(function (QueryBuilder $q): void {
        $q->where('status', 'retry')->where('attempts', 3, '<');
    })->get('jobs');
```

- 闭包及嵌套要求与 `whereGroup()` 相同，只是以 OR 连接整个分组；作为首个条件时省略前置 OR。
- 它不会把之前的条件一起括起来。例如租户限制后直接 OR 一个组，可能使该组绕开租户限制；需要 `tenantId AND (A OR B)` 时，把 A、B 都放入同一个 `whereGroup()`。

### `orderByAsc($column)`

```php
$builder->orderByAsc('created_at')->orderByAsc('id')->get('orders', 10);
```

- 等价于旧的 `orderBy($column, 'ASC')`，保留旧方法的字段处理规则；字段名用固定值或白名单，不直接接收请求参数。
- 多次调用不同字段按调用顺序排列；同一字段再次设置会覆盖原方向。
- 不提供旧 `orderBy()` 的第三个自定义排序参数；需要自定义排序时继续调用原方法。分页需要稳定顺序时可追加唯一字段。

### `orderByDesc($column)`

```php
$builder->orderByDesc('mapId')->get('order_mapping', 10);
```

- 等价于旧的 `orderBy($column, 'DESC')`；字段安全、多字段顺序和同字段覆盖规则与 `orderByAsc()` 相同。
- `orderByAsc('id')->orderByDesc('id')` 最终按 `id DESC`，不会同时生成两个相反方向。构建完成后排序设置自动清除。

### `forUpdate($isLock = true)`

```php
// 在调用方已开启的业务事务内执行。
$builder->where('id', $id)->forUpdate()->get('orders');
$rows = $client->query($builder);
```

- 等价于 `selectForUpdate($isLock)`，用于 SELECT；不自动开启事务，调用方负责开启、提交或回滚事务，并在同一客户端连接上执行。
- 需要跨语句持有锁时，在事务内执行；不要把 `get()` 或 `forUpdate()` 当作已经向服务端申请锁，实际锁定发生在查询执行时。
- `forUpdate(false)` 取消排他锁及其等待选项。已调用 `skipLocked()` 或 `nowait()` 后再调用 `forUpdate(true)`，会保留已有等待选项。
- 不要与 `lockInShareMode()` 混用；当前接口不会自动替你消除两种锁模式的冲突。外层的锁设置也不会自动传播到子查询。

### `skipLocked()`

```php
$builder->where('status', 'pending')->orderByAsc('id')->skipLocked()->get('jobs', 10);
```

- 自动开启 `FOR UPDATE SKIP LOCKED`，不必先调用 `forUpdate()`，仍需由调用方管理事务。
- 跳过被其他事务锁定的行；即使存在符合条件的记录，也可能返回不足 LIMIT 数量或空结果，不能据此断定队列没有任务。
- `skipLocked()->nowait()` 最终使用 NOWAIT；`forUpdate(false)` 会取消锁及等待选项。构建完成后自动重置。
- 队列场景中，在同一事务内锁定、更新任务状态并提交；提交后再进行耗时的外部工作，避免长时间占锁。

### `nowait()`

```php
$builder->where('id', $id)->nowait()->get('orders');
```

- 自动开启 `FOR UPDATE NOWAIT`，无需预先调用 `forUpdate()`，仍需由调用方管理事务。
- 行锁冲突会使查询报错，不会像 `skipLocked()` 一样跳过冲突记录；业务需要捕获异常并决定回滚或重试。
- NOWAIT 不等于客户端查询超时，也不保证 SQL 的所有执行阶段都不等待；连接、网络及查询本身仍使用客户端超时配置。
- `nowait()->skipLocked()` 最终使用 SKIP LOCKED；`forUpdate(false)` 取消锁及等待选项。构建完成后自动重置。

## 更多文档

- [测试命令与环境配置](tests/README.md)。
- [测试场景与验证记录](TEST.md)。
- [QueryBuilder 使用说明](queryBuilder.md)。

## 协议错误与大参数

收到格式错误或截断的协议数据时，抛出 `EasySwoole\Mysqli\Exception\ProtocolException` 并立即关闭连接，避免下一条查询读取遗留报文。非事务连接可在下一次查询时重新建立；事务内发生协议错误时，仍遵循上述事务丢失保护。

预处理参数采用分段编码与分包发送，避免将多个大字符串重复拼成整条执行报文。结果字段及行解码由 `Protocol\ResultDecoder` 负责；压缩接收缓冲区使用读取游标，减少逐行读取时的字符串复制。

## 内部职责划分

- `Client`：公开查询 API、元数据及成功回调；对外超时参数继续使用秒数。
- `Connection`：命令调度、认证流程和完整操作的并发保护。
- `PacketStream`：Socket 收发、包序号、分包重组、压缩及缓冲区；不判断事务或自动重试 SQL。
- `SessionState`：服务端事务状态、异常断开后的事务丢失保护、显式关闭和会话代次。
- `Deadline`：基于单调时钟的截止时间；连接、认证及该次命令的内部收发共享预算，建连还受 `maxConnectTime` 上限约束。
- `ResultDecoder`、`ParameterEncoder`、`PacketPayload`：结果解码、参数编码和有界发送分片。

上述辅助类属于内部实现。`Client`、`Connection`、`Statement` 和 `QueryBuilder` 的既有公开方法签名保持不变。
