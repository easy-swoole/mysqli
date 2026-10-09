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

查询超时后，客户端会关闭连接，避免复用存在未读 MySQL 数据包的协议流。下一次查询会自动重新连接。

## 会话安全与超时

预处理语句属于创建它的服务端会话。该会话断开或重连后，执行旧 Statement 会抛出 `LogicException`，需要重新 Prepare。关闭已失效的 Statement 不会关闭新会话中的语句。

同一个连接同时只允许一个操作，包括建立连接、关闭 Statement 和关闭连接。并发调用会抛出异常，正在进行的操作仍可继续完成。可以在当前操作结束后重试关闭 Statement；需要并发查询时，应使用不同的客户端连接。

`maxConnectTime` 限制完整连接过程的总耗时，包括 TCP 建连、握手、认证和字符集设置。`rawQuery()`、`query()` 和 `prepare()` 的超时均包含必要的连接过程；`query()` 的 Prepare、Execute、结果读取及 Statement 清理共用同一个超时预算。连接过程同时受 `maxConnectTime` 限制，以先到期的截止时间为准。

超时值必须大于零。如果 Statement 清理阶段的查询预算已经耗尽，客户端会直接丢弃连接，不再为清理操作分配新的超时预算。

## 更多文档

- [测试命令与环境配置](tests/README.md)。
- [测试场景与验证记录](TEST.md)。
- [QueryBuilder 使用说明](queryBuilder.md)。
