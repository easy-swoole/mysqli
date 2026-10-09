# 测试说明

本项目使用 PHPUnit 10，并通过 `tests/run.php` 在 Swoole 协程环境中运行。测试分为不依赖数据库的单元测试，以及连接真实 MySQL 服务端的集成测试。

当前测试套件包含 77 个测试。本次在 PHP 8.4.24 和 Swoole 环境下，使用恢复后的指定远程测试数据库执行完整测试，共完成 596 个断言，全部通过。

## 环境要求

- PHP 8.1 或更高版本
- Swoole 5.1 或更高版本
- OpenSSL 扩展
- zlib 扩展
- PHPUnit 10.5
- 集成测试需要独立的 MySQL 测试数据库
- 大包测试要求服务端 `max_allowed_packet` 大于 16 MiB
- 事务、锁等待和死锁测试要求表引擎支持事务，当前使用 InnoDB

安装依赖：

```bash
composer install
```

## 运行命令

仅运行单元测试，无需连接 MySQL：

```bash
composer test:unit
```

配置真实测试数据库：

```bash
export MYSQLI_TEST_HOST=127.0.0.1
export MYSQLI_TEST_PORT=3306
export MYSQLI_TEST_USER=test
export MYSQLI_TEST_PASSWORD='secret'
export MYSQLI_TEST_DATABASE=test
```

也可以使用兼容 FastDb 的环境变量名称：

```bash
export FAST_DB_TEST_HOST=127.0.0.1
export FAST_DB_TEST_PORT=3306
export FAST_DB_TEST_USER=test
export FAST_DB_TEST_PASSWORD='secret'
export FAST_DB_TEST_DATABASE=test
```

运行集成测试：

```bash
composer test:integration
```

运行全部测试：

```bash
composer test
```

运行指定测试类或场景：

```bash
php tests/run.php --filter CompressionTest --testdox
php tests/run.php --filter testPreparedInsertReportsInvalidColumnAndInvalidDataTypes --testdox
```

未配置数据库环境变量时，集成测试会被跳过。

## 单元测试场景

### 配置

文件：`tests/ConfigTest.php`

- 接受 FastDb 风格的配置数组。
- 验证主机、端口、超时和最大连接时间。
- 验证 `compress` 压缩配置及 `toArray()` 输出。
- 验证查询超时与连接超时独立赋值，未知字段（含旧别名 `maxConnectTim`）被忽略。
- 验证默认值、getter/setter、`restore()`、SplBean 序列化及过滤行为。
- Config 仅存储超时值，包括零值和负数；相关测试不再断言 Config 会拒绝这些值。

### FastDb API 兼容

文件：`tests/FastDbApiCompatibilityTest.php`

- 验证 FastDb 风格的 Connection 类可以继承当前 Client。
- 保留 `mysqlClient()` 与 mysqli mock 的类型兼容性。
- FastDb 中存在但当前客户端不使用的额外配置不会造成异常。
- 该测试不依赖 `easyswoole/fast-db` 包。

### 连接超时

文件：`tests/ConnectionTimeoutTest.php`

- 使用本地模拟服务端接受 TCP 连接但不发送 MySQL 握手包。
- 验证握手在配置时间内超时。
- 验证抛出 `TimeoutException`，且错误信息包含握手超时上下文。

### MySQL 协议

文件：`tests/ProtocolTest.php`

- 模拟查询响应超时，验证连接关闭，避免复用存在未读数据的协议流。
- 验证文本协议查询、结果集字段定义和文本行解析。
- 验证 `COM_STMT_PREPARE`、参数定义、字段定义、`COM_STMT_EXECUTE` 二进制行解析和 `COM_STMT_CLOSE`。
- 验证 `CLIENT_COMPRESS` 协商发生在认证阶段，认证成功后启用 zlib 压缩。
- 验证压缩帧序号与经典协议包序号。
- 验证一个压缩帧中包含多个经典 MySQL 包时可以正确拆包。
- 验证压缩数据和未压缩负载的协议处理。

### QueryBuilder

文件：`tests/QueryBuilderTest.php`

- `get`、`getOne`、`getColumn` 和 `getScalar`。
- `where` 条件和参数绑定。
- `join`、带条件的 join 和 `group`。
- 普通、限量、带条件和带锁的 `update`。
- `FOR UPDATE`。
- 普通、限量和带条件的 `delete`。
- 单行 `insert` 和批量 `insertAll`。
- 子查询和 `union`。
- 原始 SQL 表达式。

## 真实服务器集成测试场景

### 客户端基本功能及异常

文件：`tests/ClientTest.php`

- 使用 FastDb 测试夹具执行原始 SQL 查询。
- 原生 Prepare 支持整数、浮点数、字符串、NULL 和布尔参数。
- QueryBuilder 使用服务端预处理协议执行。
- 单次查询超时后关闭失步连接，下一次查询自动重连。
- Prepare 阶段执行错误 SQL，验证服务端返回 `1064`，异常包含原 SQL，之后连接仍可查询。
- Prepare 成功后 Execute 触发重复主键，验证返回 `1062`，客户端元数据被清空，连接仍可用，同一个 Statement 可以再次成功执行。
- Prepare 引用不存在字段，验证返回 `1054 Unknown column`。
- Execute 向整数列写入非数字字符串，验证返回 `1366 Incorrect integer value`。
- Execute 写入超过字段长度的字符串，验证返回 `1406 Data too long`。
- Execute 写入非法 JSON，验证返回 `3140 Invalid JSON text`，且错误数据不会落库。
- 非法 JSON 失败后复用同一个 Statement 写入包含数组、嵌套对象和中文的合法 JSON，并解码比较完整结构。
- 数据错误后验证没有产生脏记录，Statement 和连接均可继续使用。

### 字段数据类型

文件：`tests/DataTypeTest.php`

- 通过 Prepare 二进制结果协议验证常见字段类型。
- 通过普通查询文本协议验证相同的 PHP 值语义。
- 覆盖有符号和无符号整数，包括超过 PHP 整数范围的 `BIGINT UNSIGNED`。
- 覆盖 DECIMAL、FLOAT、DOUBLE 和 BIT。
- 覆盖 CHAR、VARCHAR、BINARY、VARBINARY、TEXT 和 BLOB。
- 覆盖 DATE、负数多日 TIME、DATETIME、TIMESTAMP 和 YEAR。
- 覆盖 JSON、ENUM、SET 和 NULL。
- 验证 Prepare 更新 NULL 值及写操作元数据。

### 写操作元数据

文件：`tests/MetadataTest.php`

- QueryBuilder INSERT 返回正确的自增 ID 和影响行数。
- 直接 Prepare INSERT 更新 Client 的 `lastInsertId` 和 `lastAffectRows`。
- 验证原始 INSERT、UPDATE、无变化 UPDATE 和 DELETE 的元数据。
- SQL 执行失败后清空前一次写操作留下的元数据。

### 断线检测与恢复

文件：`tests/DisconnectTest.php`

- 服务端主动断开后，`ping()` 返回失败，下一次查询重新连接。
- 连接建立后被服务端 KILL，再执行 SQL 时抛出明确异常，后续查询能够重连。
- 查询正在等待结果时断开连接，验证异常和连接状态。

### FastDb 调用方式

文件：`tests/FastDbUsageTest.php`

- 使用本地兼容类复现 FastDb 的连接继承和调用方式。
- 验证连接池健康检查所需的 `ping()`。
- 验证 QueryBuilder 查询调用。
- 验证通过 `mysqlClient()` 直接调用事务开始、提交和回滚。

### 协程并发与非阻塞等待

文件：`tests/ConcurrencyTest.php`

- 建立两个独立客户端连接，并启动两个 Swoole 协程。
- 两个协程分别执行 `SELECT SLEEP(5)` 和 `SELECT SLEEP(3)`。
- 验证两条 SQL 均返回成功结果。
- 验证 3 秒查询至少等待约 3 秒，5 秒查询至少等待约 5 秒。
- 验证两条查询总耗时小于 7 秒，明显低于同步串行执行所需的 8 秒。
- 证明一个协程等待 MySQL Socket 时会让出执行权，不会阻塞另一个协程。
- 每个并发协程使用独立 Connection；单个 Connection 仍禁止同时执行多个命令，以保护 MySQL 协议包序列。

### 普通协议大包

文件：`tests/LargePacketTest.php`

- 构造超过 `0xFFFFFF` 字节的 LONGBLOB 数据。
- 使用 Prepare INSERT 上传跨多个 MySQL 包的参数。
- 使用普通文本协议读取并校验长度及 SHA-256。
- 使用 Prepare UPDATE 写入新的大包数据。
- 使用 Prepare 二进制协议重新读取完整内容。
- 验证分片和重组没有丢失或修改数据。

### 压缩协议

文件：`tests/CompressionTest.php`

- 启用 `compress` 并确认服务端实际协商 `CLIENT_COMPRESS`。
- 验证压缩普通查询和较大结果集读取。
- 验证压缩 Prepare 查询及较大参数上传。
- 在压缩协议下执行超过 16 MiB 边界的 LONGBLOB INSERT。
- 验证自增 ID 和影响行数。
- 读取大字段长度和 SHA-256，确认内容完整。
- 对大字段执行超过 16 MiB 的 UPDATE，并再次校验长度和摘要。
- 覆盖经典 MySQL 包头导致压缩层需要额外分片时的序号同步。

### 事务、锁和死锁

文件：`tests/TransactionTest.php`

- 一个连接提交事务后，另一个独立连接可以看到提交数据。
- 完整回滚后数据不会保留。
- 回滚到 SAVEPOINT 时保留保存点之前的写入。
- 两个连接制造行锁竞争，验证锁等待超时返回 `1205`，且连接之后仍可使用。
- 两个独立事务以相反顺序锁定记录，制造真实死锁。
- 验证死锁双方恰好一个返回 `1213`，另一个事务成功提交。

## 测试数据清理

集成测试使用带随机后缀的临时表，并在 `finally` 或 `tearDown()` 中删除。测试中断时可能留下以下前缀的表：

- `mysqli_coroutine_*`
- `mysqli_execute_error_*`
- `mysqli_invalid_data_*`
- `mysqli_compress_*`

测试数据库应仅用于测试，避免与业务表共用 schema。事务和断线测试会创建多个独立连接，并可能执行 `KILL CONNECTION` 断开测试自身建立的连接。

## 最近一次完整验证

使用真实 MySQL 测试服务器执行：

```text
Tests: 77
Assertions: 596
Result: OK
Time: 25.624 seconds
Peak memory: 124.34 MB
```

完整测试同时覆盖配置、协议、QueryBuilder、字段类型、Prepare/Execute 错误、超时、断线、大包、压缩协议、写操作元数据和真实事务并发场景。

## 2026-10-09 回归修复验证

新增 10 个不依赖真实数据库的协议回归测试，覆盖：

- 断线及同一 Connection 重连后拒绝旧 Statement，旧 Statement 关闭不会误关新会话中复用相同 ID 的语句。
- 查询等待期间拒绝 Statement 关闭、连接关闭、连接建立及其他查询，原查询和随后重试关闭均正常完成。
- Client 握手期间并发 connect 不会替换正在建立的连接。
- 完整连接过程共享 maxConnectTime，rawQuery、QueryBuilder 和 prepare 的超时包含连接及认证。
- rawQuery 的连接与结果读取、QueryBuilder 的连接与 Prepare/Execute 共用一个查询预算。
- Statement 清理预算耗尽时直接丢弃连接，不再发送命令。

本次单元测试：48 个测试，325 个断言，全部通过。PHP 语法检查和 git diff --check 通过。

首次远程验证因数据库服务异常出现 17 个超时错误。服务恢复后，使用相同代码、测试配置和超时预算重新执行全套测试：77 个测试、596 个断言全部通过，无错误、失败或跳过，耗时 25.624 秒，峰值内存 124.34 MB。此前的 17 个超时错误均未复现。

独立连接并发测试改为先完成建连，再计时两条 SQL 的并发执行，仍保留小于 7 秒的断言，避免远程握手耗时影响该断言。
