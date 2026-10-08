# EasySwoole Coroutine MySQLi

MySQL protocol client built on `Swoole\Coroutine\Socket`. It supports connection timeouts, an end-to-end timeout for each query, text queries, native server-side prepared statements, and the public API used by `easyswoole/fast-db`.

Requirements: PHP 8.1 or newer, Swoole 5.1 or newer, OpenSSL, and zlib.

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
        'compress' => true, // negotiate classic-protocol zlib compression
    ]));

    // The second argument is the timeout for this complete query/result read.
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

`compress` defaults to `false`. When enabled, the client advertises `CLIENT_COMPRESS` and starts zlib compression after authentication if the server supports it. If the server does not advertise compression, the connection continues without it. Read the negotiated state with `$client->mysqlClient()->isCompressionEnabled()`.

`Client::query(QueryBuilder $builder, ?float $timeout)` uses the prepared-statement protocol automatically. Although the optional timeout is accepted at runtime, the method declaration keeps the 4.x single-parameter signature so `EasySwoole\FastDb\Mysql\Connection` can continue to extend the client.

A timed-out query closes its connection because unread MySQL packets make that protocol stream unsafe to reuse. The next query reconnects automatically.

See [tests/README.md](tests/README.md) for unit and FastDb integration test commands.
