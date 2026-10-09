# EasySwoole 协程 MySQLi

基于 `Swoole\Coroutine\Socket` 实现的 MySQL 协议客户端，支持连接超时、单次查询的总超时、文本查询、原生服务端预处理语句，并提供 FastDb 风格的查询及事务接口；继承接口的适配要求见下文。

## 环境要求

- PHP 8.1 或更高版本。
- Swoole 5.1 或更高版本。
- OpenSSL、Sockets 和 zlib 扩展。
- **MySQL 服务端需要 8.0。**

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

连接通过服务端 OK/EOF 数据包的 `SERVER_STATUS_IN_TRANS` 跟踪事务，支持 `BEGIN`、`START TRANSACTION`、事务接口以及 `autocommit=0` 下实际开始的事务。可以通过 `$client->mysqlClient()->inTransaction()` 查看最近确认且连接仍有效的事务状态，通过 `isTransactionLost()` 查看事务是否因连接丢失而失效。普通 SQL 错误不会直接清除事务状态；提交、回滚及 DDL 隐式提交以服务端响应为准。

事务期间断线或查询超时，首次操作仍抛出原始连接/超时异常；后续查询、Prepare、旧 Statement 执行、Connect、Commit 和 Rollback 会抛出 `EasySwoole\Mysqli\Exception\TransactionLostException`，阻止透明重连后继续执行。此标记在显式 `close()` 前一直保留，关闭 Statement 不会解除保护。

恢复时先调用 `$client->close()`，再连接并开启新的事务，由业务决定是否重试整个事务。如果在 Commit 响应到达前断线，提交结果可能无法确认，不能仅凭此异常自动重放写入。

`begin_transaction($flags)` 支持 mysqli 的一致性快照（1）、读写（2）和只读（4）标记，可用位或组合快照与访问模式。`commit($flags)`、`rollback($flags)` 支持 AND CHAIN（1）、AND NO CHAIN（2）、RELEASE（4）、NO RELEASE（8）。CHAIN 后连接仍处于新事务，RELEASE 成功后本地连接同步关闭。未知标记、同时指定只读与读写、CHAIN 与 NO CHAIN 或 RELEASE 与 NO RELEASE 会抛出 `InvalidArgumentException`。标记含义参照 [PHP mysqli 文档](https://www.php.net/manual/en/mysqli.constants.php)。

文本查询和预处理结果中的 DATE 均返回 `YYYY-MM-DD`，DATETIME/TIMESTAMP 均返回完整的 `YYYY-MM-DD HH:MM:SS`。TIME、DATETIME、TIMESTAMP 按字段小数精度（0～6）保留相应位数，包括值为零的小数，例如 TIME(3) 的 `00:00:00.000`。TIME 保留负号和超过 24 小时的小时数，NULL 仍返回 null。

同一个连接同时只允许一个操作，包括建立连接、关闭 Statement 和关闭连接。并发调用会抛出异常，正在进行的操作仍可继续完成。可以在当前操作结束后重试关闭 Statement；需要并发查询时，应使用不同的客户端连接。

`maxConnectTime` 限制完整连接过程的总耗时，包括 TCP 建连、握手、认证和字符集设置。`rawQuery()`、`query()` 和 `prepare()` 的超时均包含必要的连接过程；`query()` 的 Prepare、Execute、结果读取及 Statement 清理共用同一个超时预算。连接过程同时受 `maxConnectTime` 限制，以先到期的截止时间为准。

超时值必须大于零。如果 Statement 清理阶段的查询预算已经耗尽，客户端会直接丢弃连接，不再为清理操作分配新的超时预算。

## 存储过程限制

本项目以生产应用中常见的普通 SQL、预处理查询和应用层事务为主要使用方式；在这类项目中，存储过程的使用相对较少，因此客户端暂不支持存储过程调用及多结果集。

`rawQuery()`、`query()`、`prepare()` 及协议连接入口会在发送调用 SQL 前拒绝 `CALL`，抛出 `EasySwoole\Mysqli\Exception\UnsupportedOperationException`。判断支持大小写、前导空白、普通注释及 MySQL 可执行注释；拒绝后原连接仍可继续使用。多结果及分号多语句能力保持关闭。

此限制仅针对本客户端调用存储过程，不修改服务端配置，也不影响其他客户端。`CREATE PROCEDURE`、`DROP PROCEDURE` 等管理语句及普通 SELECT 中的存储函数不在 CALL 限制范围内。

## 更多文档

- [测试命令与环境配置](tests/README.md)。
- [测试场景与验证记录](TEST.md)。
- [QueryBuilder 使用说明](queryBuilder.md)。
