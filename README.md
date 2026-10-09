# EasySwoole 协程 MySQLi

基于 `Swoole\Coroutine\Socket` 实现的 MySQL 协议客户端，支持连接超时、单次查询的总超时、文本查询、原生服务端预处理语句，并兼容 `easyswoole/fast-db` 使用的公共 API。

## 环境要求

- PHP 8.1 或更高版本。
- Swoole 5.1 或更高版本。
- OpenSSL、Sockets 和 zlib 扩展。
- **MySQL 服务端需要 8.0。**

## 使用示例

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

`Client::query($builder, $timeout)` 自动使用服务端预处理协议。为了保持 `EasySwoole\FastDb\Mysql\Connection` 的继承兼容性，方法声明保留 4.x 的单参数签名 `query(QueryBuilder $builder)`，可选超时参数在运行时读取。

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
