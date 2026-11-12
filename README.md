# EasySwoole 协程 MySQLi

基于 `Swoole\Coroutine\Socket` 实现的 MySQL 协议客户端，支持连接超时、单次查询的总超时、文本查询、原生服务端预处理语句，并提供 FastDb 风格的查询及事务接口；继承接口的适配要求见下文。

## 环境要求

- PHP 8.1 或更高版本。
- Swoole 5.1 或更高版本；兼容代码及后续清理条件见 [Swoole 版本兼容与清理](#swoole-版本兼容与清理)。
- OpenSSL、Sockets 和 zlib 扩展。
- MySQL 服务端 8.0 或更高版本；容器兼容矩阵与执行状态见 [测试说明](tests/README.md)。

**TLS 默认关闭。** 可通过 `tlsMode` 显式启用强制加密、CA 校验或服务端身份校验，详见下方 TLS 配置。

## 使用示例

`new Config($options)` 和 `Config::restore($options)` 接受配置属性名，未知键会抛出 `InvalidArgumentException`。传入 FastDb 的完整连接池配置时，连接池专用字段仍由 FastDb 和连接池使用。

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

## TLS 加密连接（默认关闭）

`tlsMode` 默认是 `TlsMode::Disabled`（字符串 `'disabled'`），不发送 SSLRequest，保持原有未加密 TCP 连接方式。
只有显式启用才会协商 TLS；所有启用模式都禁止回退到未加密连接。

```php
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\TlsMode;

$client = new Client(new Config([
    'host' => 'db.example.com',
    'port' => 3306,
    'user' => 'app',
    'password' => getenv('MYSQL_PASSWORD'),
    'database' => 'app',
    'tlsMode' => TlsMode::VerifyIdentity,
    'tlsCaFile' => '/secure/mysql/ca.pem',
    'maxConnectTime' => 5.0,
    'timeout' => 3.0,
    'compress' => true, // TLS 可与现有 zlib 协议压缩同时启用
]));

// 在 Swoole 协程内：
$client->connect();
var_dump($client->mysqlClient()->isTlsEnabled()); // true
$rows = $client->rawQuery("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version', 'Ssl_cipher')");
```

### `tlsMode` / `setTlsMode()`

| 枚举值 | 配置字符串 | 行为 |
| --- | --- | --- |
| `TlsMode::Disabled` | `'disabled'` | 默认值，不启用 TLS |
| `TlsMode::Required` | `'required'` | 强制加密，不验证服务端证书身份 |
| `TlsMode::VerifyCA` | `'verify_ca'` | 强制加密并验证证书链和有效期，不校验连接地址与证书身份 |
| `TlsMode::VerifyIdentity` | `'verify_identity'` | 验证证书链、有效期以及预期 DNS 名或 IP 身份 |

- 构造配置和 `setTlsMode()` 均接受上表的枚举或字符串；`getTlsMode()` 返回枚举。无效模式会报错，不会按 Disabled 处理。
- Required 只能保证连接加密，不防止冒充服务端；需要服务端身份校验时使用 VerifyIdentity。
- 服务端不支持 TLS、TLS 握手失败、证书校验失败或超时，连接会关闭，不发送后续认证数据，也不尝试明文重连。
- Swoole 必须编译支持 OpenSSL；仅安装 PHP 的 OpenSSL 扩展不足以让 Swoole Socket 支持 TLS。未启用 TLS 的既有连接不依赖新增握手路径。
- 配置在建立连接时生效；修改配置后，先显式 `close()`，再重新连接。事务中不要为了切换配置而关闭连接。

### `tlsCaFile` / `tlsCaPath`

```php
$config->setTlsCaFile('/secure/mysql/ca.pem');
// 或使用已经按 OpenSSL 要求建立哈希索引的 CA 目录：
$config->setTlsCaPath('/secure/mysql/ca-directory');
```

- 分别为可读的 PEM CA 文件和 CA 目录；VerifyCA / VerifyIdentity 未指定时使用 Swoole/OpenSSL 的默认信任库。
- 服务端使用中间 CA 时，应发送叶证书和必要的中间证书链。客户端由 OpenSSL 验证完整链；仅有可信根 CA 不会自动补齐服务端遗漏的中间证书，缺链或不受信任的链都会拒绝连接。
- 私有 CA、自签 CA 通常需要显式配置。通过可信渠道取得 CA，不使用从未验证连接中临时下载的证书建立信任。
- 显式指定但不存在、不可读或类型不正确的路径会报错。Required 不验证证书链，即使提供 CA 文件也不会自动变成 VerifyCA。

### `tlsServerName`

```php
$config->setTlsServerName('mysql.internal.example.com');
```

- 指定 TLS 的 SNI 名称，同时作为 VerifyIdentity 校验的预期证书身份；不改变 TCP 连接地址，默认使用 `host`。
- DNS 名随 ClientHello 发送 SNI，供代理选择证书或后端；IP 字面量不发送 SNI。DNS 名末尾根域点会去除，名称不能包含空白或 NUL。
- SNI 与证书校验独立：VerifyCA 只检查证书链和有效期，VerifyIdentity 再检查名称匹配；均在发送 MySQL 认证信息前完成校验。
- 连接 IP 时，默认要求证书的主题备用名称（subjectAltName）包含对应 IP，不接受仅把 IP 写进 CN 或 DNS 主题备用名称（SAN）的证书。
- DNS 匹配不区分大小写；优先匹配 DNS 主题备用名称（SAN），没有 DNS 主题备用名称（SAN）时兼容 CN。通配符仅允许 `*.example.com` 形式，且只匹配一层子域名。
- 此字段不是跳过校验的开关。仅在明确知道服务端真实证书名称时覆盖；需要关闭身份校验但保留 CA 校验，应明确选择 VerifyCA。

### `tlsCertFile` / `tlsKeyFile` / `tlsKeyPassphrase`

```php
$config->setTlsCertFile('/secure/mysql/client-cert.pem');
$config->setTlsKeyFile('/secure/mysql/client-key.pem');
$config->setTlsKeyPassphrase(getenv('MYSQL_TLS_KEY_PASSPHRASE') ?: '');
```

- 用于服务端要求客户端证书的双向 TLS，例如 MySQL 账号配置了 `REQUIRE X509`。普通单向 TLS 不需要这些字段。
- 客户端证书和私钥必须同时配置、互相匹配；文件必须可读。私钥加密时配置解密口令，未加密私钥保持空字符串。
- 口令用于解密客户端私钥，不是数据库密码；客户端证书也不代替 MySQL 用户名和数据库密码。
- 不将私钥、解密口令或数据库密码提交到代码仓库；限制私钥文件读取权限。`Config` 的序列化包含配置值，不要把完整的 `Config` 配置打到日志。

### `tlsVersions` / `setTlsVersions(array $versions): void`

```php
use EasySwoole\Mysqli\TlsVersion;

$config->setTlsVersions([TlsVersion::Tls12, TlsVersion::Tls13]); // 等价于默认版本列表
$config->setTlsVersions([TlsVersion::Tls13]);                  // 仅允许 TLS 1.3
$config->setTlsVersions(['TLSv1.2', TlsVersion::Tls13]);        // 可混用原字符串
$config->getTlsVersions(); // 始终返回字符串数组，例如 ['TLSv1.2', 'TLSv1.3']
```

- `TlsVersion` 位于 `EasySwoole\Mysqli` 命名空间，`Tls12` / `Tls13` 对应精确字符串 `'TLSv1.2'` / `'TLSv1.3'`。配置构造参数 `tlsVersions` 与设置方法均可混用枚举、字符串；默认值和读取方法的字符串数组返回格式不变。
- 设置方法仍只保存列表，不立即校验版本名。读取方法将枚举转换为字符串，非字符串且非 `TlsVersion` 的成员会在读取时报错；空列表、非法字符串名在启用 TLS 构建连接选项时，通过 `TlsVersion::tryFrom()` 等校验报错。原字符串不增加大小写或空白归一化。
- 只允许 TLS 1.2、TLS 1.3，不能降级到旧协议；实际可用版本取决于 Swoole/OpenSSL 编译能力及服务端配置。TLS 默认关闭，版本列表本身不会开启 TLS。
- TLS 握手计入 `maxConnectTime`，也计入触发自动连接的单次查询总超时，不会获得独立的新超时预算。

### 认证、查询与重连

- 原有 `mysql_native_password`、`caching_sha2_password` 认证均可与 TLS 配合。`caching_sha2_password` 完整认证在 TLS 内传递密码，不再走未加密连接的 RSA 公钥请求流程。
- 文本查询、服务端预处理、事务、压缩和大包继续使用同一套 MySQL 分包逻辑；TLS 只包裹传输层。
- 自动重连仍按配置协商和验证 TLS，不会因为前一次失败而关闭校验。事务丢失保护与现有行为一致。
- `isTlsEnabled()` 表示当前有效连接是否已完成 TLS，关闭连接后返回 false。服务端实际协商的版本与密码套件可查询 `Ssl_version`、`Ssl_cipher`。

## 压缩与接口兼容

`compress` 默认为 `false`。启用后，客户端通过 `CLIENT_COMPRESS` 协商压缩；如果服务端支持，则在认证成功后启用 zlib 压缩。如果服务端不支持，连接仍以未压缩方式继续使用。可以通过 `$client->mysqlClient()->isCompressionEnabled()` 查看实际协商结果。

`Client::query(QueryBuilder $builder, ?float $timeout = null): bool|array` 自动使用服务端预处理协议；`rawQuery(string $query, ?float $timeout = null): bool|array` 使用文本查询协议。两者显式声明可选超时参数，支持 `timeout: 0.5` 这样的命名参数。

本地 PHP 异常（例如 `TypeError`）直接传播，不统一包装为 mysqli 异常；数据库和协议错误保留各自的异常类型。`query()` 自建的 Statement 仍在 `finally` 中关闭，成功回调在清理完成后触发。

继承 `Client` 的类（包括 FastDb 连接类）如果重写了 `query()` 或 `rawQuery()`，需要同步接受可选超时参数并传给父类；重写这两个方法时都需要声明兼容的 `bool|array` 返回类型。旧的单参数子类方法签名不能继续使用。`mysqlClient()` 仅返回协议层 `Connection` 或 `null`，不再包含原生 `mysqli` 类型。

查询超时后，客户端会关闭连接，避免复用存在未读 MySQL 数据包的协议流。未处于事务时，下一次查询会自动重新连接。

## 会话安全与超时

预处理语句绑定到创建它的连接和服务端会话。该会话断开或重连后，执行旧 Statement 会抛出 `LogicException`，需要重新准备语句；事务丢失保护生效时优先抛出 `TransactionLostException`。关闭已失效的 Statement 不会关闭新会话中的语句。

连接通过服务端 OK/EOF 数据包的 `SERVER_STATUS_IN_TRANS` 跟踪事务，支持 `BEGIN`、`START TRANSACTION`、事务接口以及 `autocommit=0` 下实际开始的事务。可以通过 `$client->mysqlClient()->inTransaction()` 查看最近确认且连接仍有效的事务状态，通过 `isTransactionLost()` 查看事务是否因连接丢失而失效。普通 SQL 错误不会直接清除事务状态；InnoDB 死锁错误 `1213` 会同步清除已自动回滚的事务状态，锁等待超时 `1205` 则保留事务状态；提交、回滚及 DDL 隐式提交以服务端响应为准。

事务期间断线或查询超时，首次操作仍抛出原始连接/超时异常；后续查询、预处理语句创建、旧 Statement 执行、连接、提交和回滚 会抛出 `EasySwoole\Mysqli\Exception\TransactionLostException`，阻止透明重连后继续执行。此标记在显式 `close()` 前一直保留，关闭 Statement 不会解除保护。

恢复时先调用 `$client->close()`，再连接并开启新的事务，由业务决定是否重试整个事务。如果在提交响应到达前断线，提交结果可能无法确认，不能仅凭此异常自动重放写入。

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

`maxConnectTime` 限制完整连接过程的总耗时，包括 TCP 建连、握手、认证和字符集设置。`rawQuery()`、`query()` 和 `prepare()` 的超时均包含必要的连接过程；`query()` 的预处理、执行、结果读取及 Statement 清理共用同一个超时预算。连接过程同时受 `maxConnectTime` 限制，以先到期的截止时间为准。

超时值必须大于零。如果 Statement 清理阶段的查询预算已经耗尽，客户端会直接丢弃连接，不再为清理操作分配新的超时预算。

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
- COUNT 和当前页查询共用一次总超时预算，包括必要的建连、预处理、执行、读取和清理；不是每条语句各获得完整的 `$timeout`。
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
- 预处理、执行、逐行读取和自动 Statement 清理共用一个总超时。消费者处理每行的时间也计入该预算，处理较慢时相应设置超时或改用分批读取。
- 完整读到 EOF 后保留连接，并自动释放该方法创建的 Statement；提前退出循环、消费者抛异常、显式关闭或丢弃未读流会关闭连接，避免残留协议数据。
- 流结束前连接保持独占，不能在同一 Client 上发起查询、创建预处理语句、事务或关闭连接。需要并行操作时使用其他 Client；其他协程正在读取时，调用流的 `close()` 会抛并发异常，等本次读取结束后再关闭。
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
- 在事务中提前停止、读取错误或超时会保留事务丢失保护，不能透明重连后继续该事务；调用方应显式关闭 Client，再按业务规则开始新事务，不能直接继续提交旧事务。

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
- 预处理是此前的独立操作；这里的超时覆盖本次执行与逐行消费。连接断开或流提前停止后，旧 Statement 不再可复用，需要重新准备语句；事务丢失保护优先适用。
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


## 存储过程限制

本项目以生产应用中常见的普通 SQL、预处理查询和应用层事务为主要使用方式；在这类项目中，存储过程的使用相对较少，因此客户端暂不支持存储过程调用及多结果集。

`rawQuery()`、`query()`、`prepare()` 及协议连接入口会在发送调用 SQL 前拒绝 `CALL`，抛出 `EasySwoole\Mysqli\Exception\UnsupportedOperationException`。判断支持大小写、前导空白、普通注释及 MySQL 可执行注释；拒绝后原连接仍可继续使用。多结果及分号多语句能力保持关闭。

此限制仅针对本客户端调用存储过程，不修改服务端配置，也不影响其他客户端。`CREATE PROCEDURE`、`DROP PROCEDURE` 等管理语句及普通 SELECT 中的存储函数不在 CALL 限制范围内。

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
| `groupExistingWhere()` | 将全部已有 WHERE 整体分组，供继续追加必须同时满足的约束 |
| `whereGroup($callback)` / `orWhereGroup($callback)` | 带括号的 AND / OR 条件组，支持嵌套 |
| `orderByAsc($column)` / `orderByDesc($column)` | 对应 `orderBy($column, 'ASC' / 'DESC')` |
| `forceIndex($indexes)` / `useIndex($indexes)` / `ignoreIndex($indexes)` | 强制、建议使用或排除主表索引 |
| `forUpdate($isLock = true)` | 对应现有 `selectForUpdate()` |
| `skipLocked()` / `nowait()` | 自动开启 `FOR UPDATE SKIP LOCKED` / `FOR UPDATE NOWAIT` |

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
// LEFT JOIN users u on u.id=o.userId AND `u`.`tenantId` = `o`.`tenantId`
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
// ... on u.id=o.userId AND (u.status = ? OR u.status = ?) AND `u`.`tenantId` = `o`.`tenantId`
```

- ON 分组闭包中使用 WHERE 条件方法：`where()`、`orWhere()`、`whereColumn()`、`whereRaw()`、`whereGroup()` 等；不在闭包中调用 `joinWhere()`、`joinOn()` 或 HAVING 方法。
- `$table` 的定位规则与 `joinOn()` 相同。分组位于 ON 中，LEFT JOIN 下将这些条件移到外层 WHERE 会改变保留行范围。
- 仅适用于 ON JOIN，不能向初始条件为 `USING(...)` 的 JOIN 追加分组。
- `joinWhereGroup()` 以 AND 追加整个组，`joinOrWhereGroup()` 以 OR 追加；前面的初始 JOIN 条件不会自动被包进同一个组，需确认完整 ON 的优先级。
- 闭包不构建/执行查询，仅收集条件；支持嵌套 WHERE 分组，空组或错误回调会报错且不追加半成品组。

### `insert($tableName, $insertData)` / `replace($tableName, $insertData)`

- 可传入单个关联行或按整数键组织的多行数组。需要使用任意外层键时，调用 `insertAll()`。
- 设置 `fields()` 后，每行只保留白名单中的列。若某行原有数据在过滤后没有任何列，抛出 `InvalidArgumentException`。
- 显式传入空数组可构建一行默认值。是否允许默认值由数据库列约束决定。

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

- 由表的 PRIMARY KEY / UNIQUE 约束决定是否冲突；方法不创建唯一索引，也不额外查询是否存在。`upsert()` 与其他构建方法一样返回构造器，实际执行交给 `Client::query()`。
- 字段列表 `['name']` 表示按每一行实际插入的新值更新；它使用 INSERT 行别名语法，**需要 MySQL 8.0.19 或更高版本**。批量 `onDuplicate(['name'])->insertAll()` 同样有此要求。
- 显式映射 `['name' => '统一名称']` 表示冲突时统一写入该值，不读取每行的 name。显式值也可使用 Expression 或原有 `func()` 等表达式；固定标量参数在各行插入参数之后绑定。
- `upsert()` 要求非空行数据和非空更新列。可以用 `fields()` 筛选待插入的列，筛选后每行的列集合必须一致。
- 旧的单行 `onDuplicate(...)->insert()` 继续保持原有构建方式；只有批量的隐式更新改为引用每行新值，不再把二维数组误读成 NULL。
- 按插入值更新的字段必须在过滤后保留。固定值或表达式更新列由 `$updateColumns` 指定。
- 重复键更新仅用于 INSERT。与 REPLACE 组合会抛出 `InvalidArgumentException`。构建完成或 `reset()` 后，更新列设置自动清除。


### 通用调用规则

- 先调用条件、排序、索引及锁方法，最后调用 `get()`、`getOne()`、`update()` 等构建方法，再交给 `$client->query($builder)` 执行。构建方法本身不访问数据库。
- GROUP BY、HAVING、UNION 和行锁用于 SELECT。INSERT 和 REPLACE 接收写入值，不能同时配置 JOIN、WHERE、ORDER BY 或 LIMIT。
- `get()` 等方法完成构建时就会清除当前设置，并非等到执行后才清除；已成功构建的 SQL 和绑定参数保留。手动 `reset()` 也只清待构建状态，表前缀不会自动清除。
- 构建失败不覆盖最近成功结果；放弃失败条件后使用 `reset()` 开始新查询。`setQueryOption([...])` 会先验证整批选项，遇到非法项不会部分应用。
- 字段名、索引名、SQL 表达式和 AND/OR 连接符使用代码常量或白名单。原始 SQL 片段须符合插入位置的语法。参数绑定用于条件值。
- 集合、范围和 LIKE 方法的第三个参数是 `$cond`；NULL 和 EXISTS 方法的第二个参数是 `$cond`，默认均为 `'AND'`。可用 `ConditionConjunction::And` / `Or` 或原字符串；建议仅使用 AND / OR，首个条件的连接符自动省略。
- AND/OR 仍遵循 SQL 的优先级，需要括号时使用条件组。这些 WHERE 方法也可用于 UPDATE、DELETE，写入前应确认条件范围。

### `setQueryOption($options)`：语句选项

```php
$builder->setQueryOption('IGNORE')->where('id', 1)->update('users', ['name' => 'Alice']);
$builder->setQueryOption(['LOW_PRIORITY', 'IGNORE'])->insert('users', ['id' => 2]);
```

- 选项接受一个字符串或字符串数组。构建时按语句类型校验，并按 MySQL 语法顺序输出。
- `IGNORE` 用于 INSERT、UPDATE 和 DELETE，`QUICK` 用于 DELETE，`DISTINCT` 用于 SELECT。语句不支持的选项或互斥选项会在构建时抛出 `InvalidArgumentException`。

### `fields($fields)` / `setPrefix($prefix = '')`：字段与表名引用

```php
$builder->fields(['name AS display_name', 'id'])->getColumn('users');
// SELECT name AS display_name FROM `users`

$builder->setPrefix('app_')->get('`order`');
// SELECT * FROM `app_order`
$builder->get('`archive`.`order`');
// SELECT * FROM `archive`.`order`，显式数据库限定名不再添加表前缀
```

- `fields()` 接受字符串、字段数组和 Expression；字符串中的表达式、空白和别名原样保留。用于 INSERT、REPLACE 或 UPDATE 的字段白名单时，传入要保留的原始列名。
- `setPrefix()` 保留到显式更改；`reset()` 不清除表前缀。普通表名、已有反引号的表名及其别名均可使用；数据库限定表名不添加前缀。
- 表名、字段表达式及原始 FROM 片段仍来自可信代码；表名前缀处理不是任意 SQL 的安全过滤器。需要带参数的派生表时使用 `fromSub()`。

### `getField(): ?array`：读取显式配置的字段

```php
$builder = new QueryBuilder();
$builder->getField();                      // null
$builder->fields('*')->getField();         // ['*']
$builder->fields(['id', 'name'])->getField(); // ['id', 'name']
$builder->fields([])->getField();          // []
```

- 未显式设置字段时返回 `null`，构建 SELECT 时默认选择 `*`。
- `fields()` 设置的字段和 `selectRaw()` 添加的表达式均按数组返回。显式设置的空数组也会原样返回。
- 此方法读取待构建状态。请在 `get()` 等构建方法前读取，构建完成或调用 `reset()` 后会恢复为 `null`。

### `get($tableName, $numRows = null, $columns = null)` / `getOne($tableName, $columns = null)`

```php
$builder->fields('id')->get('users');           // SELECT id FROM `users`
$builder->fields('id')->getOne('users');        // SELECT id FROM `users` LIMIT 1
$builder->fields('id')->getOne('users', '*');   // SELECT * FROM `users` LIMIT 1
```

- 省略 `$columns` 或传入 `null` 时，使用当前的 `fields()` 和 `selectRaw()` 配置。未配置时默认选择 `*`。
- 显式传入字段会覆盖当前配置。传入 `'*'` 选择全部列，空字段数组会抛出 `InvalidArgumentException`。
- `getOne()` 固定生成 `LIMIT 1`。

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
- `fields()` 可限制赋值列。UPDATE 至少需要一个可写字段，过滤后无列会抛出 `InvalidArgumentException`。

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
- `fromSub()` 只设置待构建的 FROM 来源，最后使用 `get(null)`；此时传入普通表名会报错。`getFromSub()` 一次完成设置和构建，仍返回构造器。
- 表前缀和索引提示放在子查询自己的构造器上；外层不能给派生表设置 FORCE/USE/IGNORE INDEX。构建完成或 `reset()` 后清除 FROM 来源。

### `count($tableName, $alias = 'aggregate')`

```php
$template = (new QueryBuilder())->where('status', 'paid')->groupBy('userId');
$counter = (clone $template)->count('orders', 'total');
$total = $client->query($counter)[0]['total'];
```

- 统计当前筛选结果集的行数；有 GROUP BY 时统计分组数，有 DISTINCT 或 UNION 时统计它们产生的结果行数。它与 `aggregate(..., 'COUNT', $column)` 的列计数含义不同。
- 忽略整体 ORDER BY、LIMIT 和行锁；UNION 分支已经构建的局部排序、分页仍属于分支结果，不会删除。WHERE、JOIN、HAVING、DISTINCT 和 UNION 的参数保留。
- 方法构建 COUNT SQL 并返回构造器，不直接返回数字；默认结果列名为 `aggregate`。想保留待构建模板时先 `clone`，使用 FROM 子查询时 `$tableName` 传 `null`。
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

### `raw($sql, $param = [])`

```php
$builder->where('unused', 7)->raw('SELECT ? AS value', [99]);
// SQL：SELECT ? AS value；参数：[99]
```

- `$sql` 为单条可信 SQL 字符串。`$param` 为位置参数数组，按传入顺序绑定，默认空数组。构建成功后清除待构建设置。
- 构建后交给 `Client::query()` 执行。`getLastQuery()` 在读取时生成调试 SQL，仅供查看；执行使用预处理 SQL 和绑定参数。多语句和 CALL 限制同样适用。


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

- 接受值数组或已经调用 `get()` 等完成构建的 `QueryBuilder::subQuery()`；普通构造器或未构建子查询会抛出 `InvalidArgumentException`。
- 数组元素逐个绑定，不传 `'1,2,3'` 这样的字符串；子查询应只选择一列，且不要设置结果别名。
- 空数组生成 `0 = 1`，不是忽略条件；与其他条件使用 OR 连接时，最终范围仍取决于完整表达式。
- 数组包含 `null` 不等于额外匹配 NULL 行；需要时另加 `whereNull()` 并通过条件组表达 OR。
- 子查询在外层构建前不要重建或修改，外层构建时才读取它的 SQL 和参数。
- 旧写法 `where('id', [], 'IN')` 保持原行为，空数组保护仅适用于此新方法。

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereIn(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

### `whereNotIn($column, $values, $cond = 'AND')`

```php
$builder->whereNotIn('status', ['cancelled', 'closed'])->get('orders');
```

- 参数和子查询要求与 `whereIn()` 相同；子查询只选一列、不带结果别名，并在外层构建前保持不变。
- 空数组生成 `1 = 1`。用于 UPDATE、DELETE 时，它本身不会缩小操作范围；尤其注意与 OR 的组合。
- 列表或子查询结果包含 NULL 时，遵循 SQL 的 NULL 比较规则，可能导致原本预期保留的行也不返回；需要排除集合中的 NULL，或明确设计 NULL 条件。
- 旧的 `where(..., 'NOT IN')` 不增加空数组保护。

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereNotIn(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

### `whereNull($column, $cond = 'AND')`

```php
$builder->whereNull('deleted_at')->get('orders');
```

- 生成 `IS NULL`，不绑定值；匹配数据库 NULL，不匹配空字符串、字符串 `'NULL'` 或数值 0。
- `$cond` 是第二个参数，例如 `whereNull('deleted_at', 'OR')`。需要与其他条件组成一个整体时放入 `whereGroup()`。
- 该方法不改变旧的 `where($column, null)` 行为；判断 NULL 时优先使用明确的 NULL 方法。

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereNull(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

### `whereNotNull($column, $cond = 'AND')`

```php
$builder->whereNotNull('paid_at')->get('orders');
```

- 生成 `IS NOT NULL`，不绑定值；空字符串和 0 仍可能满足条件。如果还要排除这些值，需要另加条件。
- `$cond` 是第二个参数；字段名使用固定值或白名单。

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereNotNull(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

### `whereBetween($column, [$min, $max], $cond = 'AND')`

```php
$builder->whereBetween('amount', [100, 500])->get('orders');
```

- 数组必须恰好两个值，否则抛出 `InvalidArgumentException`；按传入顺序绑定，不自动排序边界，也不检查大小关系。
- 包含两端边界，不能表达“含起点、不含终点”。时间范围需要半开区间时使用原有 `where('created_at', $start, '>=')->where('created_at', $end, '<')`。
- 使用与字段类型匹配的值；日期时间字符串的格式、精度和时区由调用方统一。NULL 边界遵循 SQL 的 NULL 比较规则。

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereBetween(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

### `whereLike($column, $pattern, $cond = 'AND')`

```php
$builder->whereLike('u.name', '%手机%')->get('users u');
```

- 字段接受普通名称、`别名.字段名`、`数据库.表.字段` 或正确加反引号的各段，采用与普通条件相同的逐段引用规则；名称本身包含点时使用单个引用名，例如 `` `u`.`odd.name` ``。不传 `LOWER(name)` 等表达式或完整 SQL。
- 不自动添加 `%`，也不转义 `%`、`_`；它们仍是通配符。要按字面量搜索这些字符时，由调用方按会话的 SQL 转义规则处理。
- pattern 整体通过参数绑定，不需要手动添加 SQL 引号；字符串 `'DBNULL'` 也会正常绑定。
- 是否区分大小写由字段和表达式的排序规则决定，前置 `%` 的匹配需要关注查询成本。

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereLike(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

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

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereExists(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

### `whereNotExists($subquery, $cond = 'AND')`

```php
$builder->whereNotExists($sub)->get('orders');
```

- 子查询、别名、关联条件及参数合并要求与 `whereExists()` 相同；这里的 `$sub` 可使用上一节构建的子查询。
- 表示子查询没有符合条件的记录；没有关联条件时，可能使整个外层查询全部满足或全部不满足，而不是逐条判断关联关系。
- `$cond` 是第二个参数。用于写入时，应先确认关联条件和最终 WHERE 范围。

- `$cond` 同时接受 `ConditionConjunction::And` / `Or` 与原字符串，例如 `whereNotExists(..., cond: ConditionConjunction::Or)`；参数位置、默认字符串 `'AND'` 及旧连接符字符串行为不变。

### `groupExistingWhere()`

`where()` 和 `orWhere()` 按调用顺序连接条件，不会自动为之前的条件添加括号。SQL 中 AND 的优先级高于 OR，因此下面的连续调用不能保证所有结果都满足 `id = 7`：

```php
$builder = new QueryBuilder();
$builder->where('status', 1)
    ->orWhere('score', 2)
    ->where('id', 7)
    ->get('users');
// getLastBindParams(): [1, 2, 7]
```

生成的参数化 SQL 为：

```sql
SELECT * FROM `users`
WHERE `status` = ? OR `score` = ? AND `id` = ?
```

代入参数后，等价于 `status = 1 OR (score = 2 AND id = 7)`。例如 `id = 8, status = 1, score = 0` 的记录也会命中。链式调用中最后添加 `where('id', 7)`，不会让该条件自动约束前面的全部 OR 分支；UPDATE、DELETE 也遵循同样的优先级。

需要让已有条件的所有分支都满足 `id = 7` 时，在追加该条件前调用 `groupExistingWhere()`：

```php
$builder = new QueryBuilder();
$builder->where('status', 1)
    ->orWhere('score', 2)
    ->groupExistingWhere()
    ->where('id', 7)
    ->get('users');
// getLastBindParams(): [1, 2, 7]
```

生成的参数化 SQL 为：

```sql
SELECT * FROM `users`
WHERE (`status` = ? OR `score` = ?) AND `id` = ?
```

此时命中的记录必须满足 `id = 7`，并且在 `status = 1`、`score = 2` 中至少满足一项；上述 `id = 8` 的记录不会命中。`groupExistingWhere()` 将调用时已经积累的全部 WHERE 条件整体包成一组；没有 WHERE 时保持不变，参数顺序、JOIN、字段、排序、分页和锁定选项均保留。

用于 UPDATE、DELETE 时也在最终构建前完成分组和范围约束：

```php
$builder = new QueryBuilder();
$builder->where('status', 1)->orWhere('score', 2)
    ->groupExistingWhere()->where('id', 7)
    ->delete('users');
// DELETE FROM `users` WHERE (`status` = ? OR `score` = ?) AND `id` = ?
```

该方法只分组调用时已有的条件，适合在最终构建前追加实体主键、关联外键等范围约束。之后继续添加的 OR 条件仍遵循普通 SQL 优先级，不会自动纳入之前的分组；需要预先明确一个条件组时，也可以使用下面的 `whereGroup()`。

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
- 闭包返回值不参与构建，不需要返回构造器。空条件组会抛出 `InvalidArgumentException`；闭包抛异常时，整个组不会加入外层，外层此前条件保留。
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
- 主构造器的排序分页作用于 UNION 整体结果；要限制某个分支，先在独立构造器完成该分支的 `get()`，再传给 `union()`。
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
// LEFT JOIN users u on u.id=o.userId AND u.deleted_at IS NULL
```

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

### `orderByAsc($column)`

```php
$builder->orderByAsc('created_at')->orderByAsc('id')->get('orders', 10);
```

- 等价于 `orderBy($column, 'ASC')`。字段名用固定值或白名单，不直接接收请求参数。
- 多次调用不同字段按调用顺序排列；同一字段再次设置会覆盖原方向。
- 不提供旧 `orderBy()` 的第三个自定义排序参数；需要自定义排序时继续调用原方法。分页需要稳定顺序时可追加唯一字段。

### `orderByDesc($column)`

```php
$builder->orderByDesc('mapId')->get('order_mapping', 10);
```

- 等价于旧的 `orderBy($column, 'DESC')`；字段安全、多字段顺序和同字段覆盖规则与 `orderByAsc()` 相同。
- `orderByAsc('id')->orderByDesc('id')` 最终按 `id DESC`，不会同时生成两个相反方向。构建完成后排序设置自动清除。

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
- 字符串继续忽略大小写，但沿用旧规则不去除首尾空白：`'read'` 有效，`' read '` 无效。固定字符串通过 `TableLockMode::tryFrom()` 校验，无效值不会覆盖此前模式。
- 可传入单个表名或非空表名数组，数组按值的顺序处理。普通表名应用表前缀，数据库限定表名按原数据库和表名引用。
- 表锁模式保留到下一次显式设置。复用构造器时，可调用 `setLockTableMode(TableLockMode::Read)` 切回 READ。

### `forUpdate($isLock = true)`

```php
// 在调用方已开启的业务事务内执行。
$builder->where('id', $id)->forUpdate()->get('orders');
$rows = $client->query($builder);
```

- 等价于 `selectForUpdate($isLock)`，用于 SELECT；不自动开启事务，调用方负责开启、提交或回滚事务，并在同一客户端连接上执行。
- 需要跨语句持有锁时，在事务内执行；不要把 `get()` 或 `forUpdate()` 当作已经向服务端申请锁，实际锁定发生在查询执行时。
- `forUpdate(false)` 取消排他锁及其等待选项。已调用 `skipLocked()` 或 `nowait()` 后再调用 `forUpdate(true)`，会保留已有等待选项。
- 两种行锁只能选一种。同时开启 `forUpdate()` 和 `lockInShareMode()` 时，构建会抛出 `InvalidArgumentException`。行锁只作用于配置它的 SELECT。

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

## Swoole 版本兼容与清理

当前最低要求为 `ext-swoole >=5.1`。TLS 运行时代码没有按版本号分流，而是根据可用方法、验证模式、SNI 和原生错误码处理。`tests/docker/Dockerfile` 中的 `case "$SWOOLE_VERSION" in 5.*)` 才是构建时的版本判断。

相关注释使用以下标记，便于检索兼容原因与移除边界：

| 标记或位置 | 保留原因 | 停止支持 5.1 后的处理 |
| --- | --- | --- |
| `SWOOLE_51_COMPAT`，`PacketStream::enableTls()` 初始化 | 5.1 在握手时才创建 SSL_CTX，且只有开启验证才加载 CA；因此握手前始终保持验证开启 | 可重新评估初始化流程。当前 `Client::enableSSL()` 还负责可靠提供原生错误码；只要继续使用该方法，保存配置前移除 `open_ssl` 的步骤在 6.2 中也必须保留，不能单独删除 |
| `SWOOLE_51_BUILD`，Dockerfile 的 `5.*` 分支 | 为 5.1 构建显式添加 `--enable-openssl` | 确认矩阵不再构建任何 5.x 版本后，可以删除该 `case` 分支，继续校验目标运行时具有 OpenSSL/TLS 能力 |
| `SWOOLE_SNI_COMPAT`，TLS 握手恢复、`verifyTlsCertificate()`、`TlsOptions` | 已验证 5.1.8 和 6.2.4 都会根据 SNI 执行原生主机名检查，需要分开实现 VerifyCA 和 VerifyIdentity | 继续保留。只有目标 Swoole 已正确支持 SNI 与身份校验分离，并通过相应用例后，才能简化此流程；停止支持 5.1 本身不足以删除这些分支 |
| `method_exists()`、TLS 常量检查 | 检查扩展是否编译了所需 OpenSSL 方法和协议能力 | 属于通用能力检查，继续保留 |

```bash
rg -n 'SWOOLE_(51_COMPAT|51_BUILD|SNI_COMPAT)' src tests/docker/Dockerfile
```

后续提高 Swoole 最低版本时，按以下顺序清理：

1. 更新 `composer.json` 的 `ext-swoole` 约束和环境说明；PHP 的最低版本独立维护。移除 Swoole 5.1 不代表同时移除 PHP 8.1。
2. 移除或替换 `tests/docker/matrix.json` 中使用 Swoole 5.1.8 的条目，确认不再构建任何 5.x 版本后再删除 Dockerfile 中对应的分支；仍支持 PHP 8.1 时，为它保留受支持的 Swoole 测试组合。依赖约束变化后按测试说明重新生成依赖快照。
3. 对照上述标记审查运行时逻辑。完整证书链验证、独立 `CertificateIdentity` 校验、共享 `Deadline` 和默认关闭 TLS 的规则继续保留；只有经过目标版本验证的兼容实现才可简化。
4. 执行单元套件、`TlsIntegrationTest` 和更新后的容器矩阵，保留 DNS/SNI、VerifyCA 错名正例、VerifyIdentity 错名负例、完整/缺失中间链、过期/不受信任证书、认证前拒绝、超时及压缩覆盖。相关测试描述的是功能和安全约束，不随 5.1 矩阵一起删除。

## 内部职责划分

- `Client`：公开查询 API、元数据及成功回调；对外超时参数继续使用秒数。
- `Client\ExecutionContext`：一次执行的总超时预算、Client 自建 Statement 的清理及成功通知；手工准备的 Statement 仍由调用方管理。
- `Connection`：命令调度、会话元数据和完整操作的并发保护。
- `Protocol\Authenticator`：握手解析、能力协商、TLS 升级及认证；沿用 Connection 的错误和 OK 状态解析，共享连接 Deadline。
- `PacketStream`：Socket 收发、包序号、分包重组、压缩及缓冲区；不判断事务或自动重试 SQL。
- `SessionState`：服务端事务状态、异常断开后的事务丢失保护、显式关闭和会话代次。
- `Deadline`：基于单调时钟的截止时间；连接、认证及该次命令的内部收发共享预算，建连还受 `maxConnectTime` 上限约束。
- `ResultDecoder`、`ParameterEncoder`、`PacketPayload`：结果解码、参数编码和有界发送分片。

上述辅助类属于内部实现。本轮补齐属性、参数和返回值的原生类型声明，保留既有方法名、参数名、顺序和默认值；构造和析构方法按 PHP 规则不声明返回类型。数据库值及旧接口中支持多种输入的参数保留 `mixed`，避免隐式转换或提前抛出 TypeError 改变调用行为。数组结构和回调签名另以 PHPDoc 标注。

## QueryBuilder 内部职责

`QueryBuilder` 提供带原生类型声明的公开链式构建方法；编译逻辑位于 `src/QueryBuilder/`，内部协作类不作为业务 API 使用。

| 类 | 职责 |
| --- | --- |
| `QueryBuilder` | 按投影、条件、关联、锁、读取和写入组织链式入口，管理待构建状态和结果发布 |
| `PendingQuery` | 统一待构建状态的默认值、结构说明和编译快照转换；状态采用数组写时复制，克隆无需依赖子类调用父级钩子 |
| `CompiledQuery` | 只读的最近成功结果：SQL、绑定参数、查询选项和事务标记 |
| `SelectPlanner` / `SelectPlan` | 规划 SELECT 语句头、COUNT 来源状态和聚合表达式 |
| `QueryParts` | 保存本次编译所需的条件与选项快照 |
| `QueryCompiler` | 构建写入语句头，按语句类型和绑定顺序协调子句编译，生成 SELECT 行锁及事务标记 |
| `ConditionCompiler` | WHERE、HAVING、JOIN ON 条件值、条件组和子查询，共用运算符处理 |
| `SelectCompiler` | JOIN、GROUP BY、UNION、ORDER BY、LIMIT 及排序项规范化 |
| `WriteCompiler` | 批量选项、写入字段过滤、列对齐和值绑定，以及重复键更新 |
| `SqlBuffer` | 保存 SQL 和按 SQL 出现顺序排列的参数；不再维护未使用的 mysqli 类型字符串 |
| `TableReference` | 表名及字段引用、表名前缀处理、索引名校验及索引提示渲染 |
| `SqlList` | 在引号、块注释和嵌套表达式之外解析选择列表 |
| `Pagination` | 分页参数校验与大整数总数、末页计算 |
| `ExpressionFactory` | 保持 now/inc/dec/not/func 的既有表达式格式 |
| `SqlFormatter` | 仅生成调试展示 SQL，不参与数据库执行 |

各编译器仅依赖本次 SQL 缓冲和所需输入，不回调整个 Builder 读取私有状态，也不负责连接数据库。
每次编译使用独立的 SQL 缓冲。构建完成后统一记录调试结果，再调用 `reset()` 发布可执行结果并清除待构建状态。
UNION 保存已构建分支快照，IN/EXISTS 在外层构建时读取子查询；这些时序和 `reset()` 保留前缀等既有规则不变。
`QueryBuilderPublicApiTest` 记录公开接口快照；API 契约测试保护方法名、命名参数和默认值，类型完整性测试保护原生类型声明。有意增加或调整公开 API 时，应审查并同步更新相应基线。

### 原生类型与子类重写

- 普通链式方法声明返回 `QueryBuilder`。`update()` 和 `delete()` 声明返回 `?QueryBuilder`，成功时返回构造器。`startTransaction()`、`commit()` 和 `rollback()` 只构建 SQL，返回 `void`。
- 继承 QueryBuilder 并重写 `get()`、`where()`、`reset()` 等方法时，需要补兼容的返回类型；可声明 `QueryBuilder` 或协变的 `static`。参数可以保持不加类型的更宽声明，但不能缩窄父类支持的输入。工厂仍使用 `new static()`，COUNT、聚合仍经过子类方法。
- 继承 Client 并重写 `rawQuery()` 时同步声明 `bool|array`；`query()` 原有返回类型不变。`Connection::set_charset()` 明确返回 `bool`。这轮类型声明会影响子类方法的 PHP 继承检查，旧的无返回类型重写需要同步适配。
