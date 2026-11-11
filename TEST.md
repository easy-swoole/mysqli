# 测试说明

本项目使用 PHPUnit 10，并通过 `tests/run.php` 在 Swoole 协程环境中运行。测试分为不依赖数据库的单元测试，以及连接真实 MySQL 服务端的集成测试。

当前测试套件包含 146 个测试。最新一次在 PHP 8.4.24 和 Swoole 环境下，使用 PolarDB 测试库执行完整测试，146 个测试、1436 个断言全部通过；兼容性验证结果见文末。

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

- 验证按当前方法签名适配的 FastDb 风格 Connection 类可以继承 Client，并检查显式可选超时参数和 query 的 bool|array 返回类型。
- 验证 `mysqlClient()` 返回类型仅为协议 Connection 或 null。
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
Tests: 146
Assertions: 1436
Result: OK
Time: 55.920 seconds
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

本次单元测试：48 个测试，327 个断言，全部通过。PHP 语法检查和 git diff --check 通过。

首次远程验证因数据库服务异常出现 17 个超时错误。服务恢复后，使用相同代码、测试配置和超时预算重新执行全套测试：77 个测试、599 个断言全部通过，无错误、失败或跳过，耗时 25.970 秒，峰值内存 124.34 MB。此前的 17 个超时错误均未复现。

独立连接并发测试改为先完成建连，再计时两条 SQL 的并发执行，仍保留小于 7 秒的断言，避免远程握手耗时影响该断言。

此前 `Client::connect()` 调整为无参数接口时，全套测试为 77 个测试、599 个断言，全部通过。当前接口已按后续修改调整为下述可选超时参数。

## connect 可选超时参数验证

`Client::connect(?float $timeout = null)` 只接受本次建连的超时参数，数据库配置来自构造函数。已同步接口兼容测试及 README，并新增 7 个测试场景：

- 显式超时短于配置上限时按显式预算超时。
- 显式超时长于配置上限时仍受 maxConnectTime 限制。
- 显式 null 使用配置中的连接超时。
- 零值和负值在打开连接前被拒绝。
- 显式连接超时覆盖握手及认证的总耗时。
- 显式超时不修改配置；失败后无参数重试使用原配置，已连接时再次 connect 保留原连接。

单元测试：55 个测试、383 个断言，全部通过。
指定远程数据库全套测试：84 个测试、655 个断言，全部通过，无错误、失败或跳过；耗时 26.217 秒，峰值内存 124.34 MB。

## 查询方法显式签名验证

query 和 rawQuery 显式声明可选超时参数，query 返回类型为 bool|array，mysqlClient 返回类型收窄为 Connection|null。旧单参数子类签名不再兼容，需要同步参数和返回类型；测试中的 FastDb 风格子类已经按新接口适配，不代表未修改的旧版 FastDb 连接类仍然兼容。

新增 5 个行为测试，覆盖 query/rawQuery 的命名超时参数、适配后子类对两种查询的超时转发，以及 mysqlClient 在建连前、建连后和关闭后的返回值。保留原有查询结果数组、写操作布尔结果及超时回归测试。

本次单元测试：60 个测试、447 个断言，全部通过。
指定远程数据库全套测试：89 个测试、719 个断言，全部通过，无错误、失败或跳过；耗时 27.065 秒，峰值内存 124.34 MB。PHP 语法检查及 git diff --check 通过。

## 无符号 64 位元数据兼容验证

Codec::int8() 和 lenencInt() 在数值不超过 PHP_INT_MAX 时返回 int，超过时返回精确的十进制字符串。解析不经过浮点转换，不依赖 BCMath/GMP。affected_rows 同步允许 int|string，超范围的字符串长度及字段数量不会直接用于内存或循环操作。

新增 CodecTest 覆盖零值、32 位边界、超过浮点精确整数范围的值、64 位有符号边界及完整无符号上限 18446744073709551615；同时验证读取偏移、长度编码、截断数据和非法长度。模拟服务端测试验证 OK 包中的超大插入 ID 与影响行数能完整传递给 Client。

真实数据库新增 12 个自增 ID 场景：9007199254740993、9223372036854775807、9223372036854775808、18446744073709551614，分别通过普通查询、原生预处理和 QueryBuilder 插入。对照 SELECT CAST(id AS CHAR) 验证精确十进制值，并验证 getLastInsertId() 的类型和普通影响行数。

Codec 整数返回类型按 PHP_INT_MAX 判定，本次实际运行环境为 64 位 PHP，未在 32 位 PHP 环境运行验证。

已为 Codec 的整数解析函数、getLastInsertId() 和 getLastAffectRows() 增加中文函数注释，说明返回字符串的原因。

单元测试：87 个测试、540 个断言，全部通过。
专项验证：43 个测试、203 个断言，全部通过。
指定远程数据库全套测试：128 个测试、896 个断言，全部通过，无错误、失败或跳过；耗时 28.917 秒，峰值内存 124.34 MB。PHP 语法检查及 git diff --check 通过。

## 超过 PHP_INT_MAX 的 UPDATE 验证

DataTypeTest 新增 9 个真实数据库 UPDATE 场景：将 BIGINT UNSIGNED 字段分别更新为 9223372036854775808、18446744073709551614 和 18446744073709551615，每个值覆盖普通 SQL、原生预处理和 QueryBuilder 三种入口。PHP 中均以十进制字符串保存测试值，预处理和 QueryBuilder 以字符串绑定参数。

每个场景先将字段重置为 1，确保 UPDATE 确实修改数据；验证影响行数为 1、插入 ID 为 0，并通过文本和二进制结果协议读回，严格比较完整字符串。SELECT CAST(bigint_unsigned AS CHAR) 同时提供服务端十进制值用于交叉校验。

指定远程测试数据库专项验证：DataTypeTest 共 12 个测试、164 个断言全部通过，无错误、失败或跳过，耗时 2.490 秒。新增用例沿用随机临时表及 tearDown 清理。PHP 语法检查和 git diff --check 通过。本次只修改测试和验证文档，未重复运行全套测试。

## 超大 FLOAT、DOUBLE、DECIMAL 专项验证

新增 LargeNumericTest，使用独立随机表及以下字段：FLOAT、DOUBLE、DECIMAL(65,30)、DECIMAL(65,0)。边界选择参考 [MySQL 8.0 数值类型说明](https://docs.oracle.com/cd/E17952_01/mysql-8.0-en/numeric-type-syntax.html)。

- FLOAT 验证正负 3.402823466e38，以及 1.234567e35。
- DOUBLE 验证正负 1.7976931348623157e308，以及 1.234567890123456e300。
- DECIMAL(65,30) 验证 35 位整数加 30 位小数的正负最大值，以及包含不同数字、末尾零的完整精度值。
- DECIMAL(65,0) 验证正负 65 位全 9 整数及不同数字组成的 65 位整数。

每种写入入口（普通 SQL、原生预处理、QueryBuilder）覆盖正上限插入后更新为负上限、负上限插入后更新为其他超大值、其他超大值插入后更新为正上限，共 9 个测试，每个测试执行 INSERT 和 UPDATE。预处理及 QueryBuilder 将浮点数作为 PHP float 参数发送，DECIMAL 作为字符串发送。

每次写入验证自增 ID、影响行数，并分别通过文本与二进制协议读回。FLOAT 按文本相对误差 5e-6、二进制相对误差 2e-7 校验；DOUBLE 按相对误差 2e-15 校验，同时要求结果为有限浮点数。使用实际值与期望值的比值校验误差，避免在 DOUBLE 上限附近计算绝对差导致溢出。DECIMAL 和服务端 CAST AS CHAR 的结果均逐位严格比较字符串，保留完整精度及小数末尾零。

指定远程数据库专项验证：9 个测试、450 个断言全部通过，无错误、失败或跳过，耗时 2.171 秒。每个测试结束后删除随机测试表。PHP 语法检查及 git diff --check 通过。本次只新增测试和文档，未重复运行全套测试。

## 2026-10-09 PolarDB 兼容性验证

使用提供的 PolarDB MySQL 兼容测试库执行验证。服务端返回版本为 8.0.13，version_comment 为 Source distribution，max_allowed_packet 为 1073741824（1 GiB），默认 sql_mode 为空。连接使用 TCP 3306、utf8mb4；凭据仅通过测试进程环境变量传入，不写入项目文件。

首轮 146 个测试中有 3 个失败：两个测试依赖 student 表的已有记录，而 SQL 夹具只建表；另一个测试在非严格模式下预期非法整数和过长字符串报错。这些失败来自测试前置条件，不需要修改客户端协议实现。

ClientTest 现使用当前连接的临时 student 表初始化固定记录，避免依赖或更改已存在的 student 数据。临时表直接定义结构，不使用服务端拒绝的同名 CREATE TEMPORARY TABLE ... LIKE 写法。数据错误测试仅对自己的连接启用 STRICT_ALL_TABLES，不修改全局 sql_mode。

最终完整验证：146 个测试、1436 个断言全部通过，无失败、错误或跳过；耗时 55.920 秒，峰值内存 124.34 MB。单元测试 87 个，真实数据库集成测试 59 个。

验证覆盖连接认证、文本查询、原生预处理、QueryBuilder、数据类型、超过 PHP_INT_MAX 的插入和更新、自增 ID 元数据、超大 FLOAT/DOUBLE/DECIMAL、查询超时与重连、主动断线、事务提交和回滚、保存点、锁等待和死锁、独立连接协程并发、普通协议大包及已实际协商启用的 zlib 压缩。大包测试未被跳过。

本次未修改客户端实现，只调整测试前置条件和验证文档。PHP 语法及 git diff --check 检查通过。

## 2026-10-09 事务丢失与透明重连保护

通过服务端 OK 和结果集 EOF 包的 SERVER_STATUS_IN_TRANS 标记跟踪事务。连接在事务内断开或超时关闭时保留失效标记；Client 替换连接前及协议连接的执行入口均检查该标记，后续操作抛出 TransactionLostException。首次失败保留原始异常，只有显式 close() 才能清除保护并建立新会话。

新增 2 个模拟服务端单元测试，验证 OK/EOF 状态、autocommit=0 的隐式事务、SQL 错误保留事务、保存点与提交状态、超时后各入口禁止重连以及显式关闭后恢复。新增 3 个 PolarDB 集成测试，验证真实 KILL CONNECTION 后普通 SQL、QueryBuilder、Prepare、Statement、Connect、Commit 和 Rollback 全部被拦截；事务内超时后两个测试记录仍为原值；显式关闭后新事务正常提交；正常提交、回滚后断线仍可透明重连，DDL 隐式提交清除状态。测试使用随机 InnoDB 表并在结束后删除。

验证结果：单元测试 89 个、564 个断言通过；PolarDB 事务专项 8 个、70 个断言通过；最终完整回归 151 个测试、1490 个断言全部通过，无错误、失败或跳过，耗时 61.197 秒，峰值内存 124.34 MB。PHP 语法检查及 git diff --check 通过。数据库凭据只通过环境变量传入。

恢复流程及提交响应丢失时的结果不确定性已写入 README。状态解析参照 [MySQL OK 包](https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_basic_ok_packet.html) 和 [EOF 包](https://dev.mysql.com/doc/dev/mysql-server/8.4.10/page_protocol_basic_eof_packet.html) 的协议定义。

## 2026-10-09 日期时间格式、小数精度及事务 flags

字段元数据现在保存 decimals；二进制日期时间解码依据字段类型补全零值及午夜时分秒，并根据精度保留 0～6 位小数，包括全零小数。TIME 同样保留负号和跨天小时数。修复了 DATETIME/TIMESTAMP 的长度 0/4 包只返回日期、TIME 小数统一输出六位或丢失零小数等问题。字段元数据及时间包格式参考 [MySQL 二进制结果协议](https://dev.mysql.com/doc/dev/mysql-server/8.0.46/page_protocol_binary_resultset.html)。

新增二进制解码单元测试，覆盖 TIMESTAMP/DATETIME 的 0、4、7、11 字节编码及 TIME 的 0、8、12 字节编码，验证值和读取偏移。新增 TemporalTest 的 7 个精度场景，通过预处理 INSERT/UPDATE、文本 SELECT、原生预处理 SELECT 和 QueryBuilder 严格比较 DATE、TIME、DATETIME、TIMESTAMP；覆盖零日期、午夜、部分零日期、NULL、负 TIME、838 小时边界及非零小数。使用临时表并固定测试会话时区，零日期测试仅修改自身会话 sql_mode。

begin_transaction 支持 WITH CONSISTENT SNAPSHOT、READ WRITE、READ ONLY；commit/rollback 支持 AND CHAIN、AND NO CHAIN、RELEASE、NO RELEASE，RELEASE 成功后同步关闭本地连接。未知及相互矛盾的位标记抛出 InvalidArgumentException。标记语义参考 [mysqli 常量](https://www.php.net/manual/en/mysqli.constants.php) 及 [MySQL 事务语法](https://dev.mysql.com/doc/refman/8.0/en/commit.html)。新增模拟服务端测试验证 SQL 及参数校验；新增 3 个真实事务测试验证只读拒绝写入、显式读写覆盖默认只读、一致性快照在开始时建立、提交/回滚链式事务、NO CHAIN 覆盖会话 completion_type、RELEASE 释放会话及后续重连。旧 FastDb 用例的 commit(3)/rollback(3) 同时指定 CHAIN 和 NO CHAIN，现改用明确的 NO CHAIN（2），begin(3) 仍保留合法的快照加读写组合。

PolarDB 最终全套回归：163 个测试、1769 个断言全部通过，无失败、错误或跳过，耗时 70.114 秒，峰值内存 124.34 MB。本次新增 12 个测试场景，原有事务丢失保护等回归均通过。PHP 语法和 git diff --check 检查通过。凭据仅由进程环境变量传入。

## 2026-10-09 客户端存储过程调用限制

客户端在普通查询、Prepare 及预处理执行入口检查调用 SQL，直接 CALL 抛出 UnsupportedOperationException，不发送调用命令。识别大小写、前导空白、# 和 -- 行注释、普通块注释及 MySQL 可执行注释，避免只检查字符串前缀而漏判。CALL 出现在字符串、普通注释或 CREATE PROCEDURE 的过程体中不会误判；多结果及多语句能力维持关闭。

新增模拟服务端测试：11 种 CALL 写法分别通过 Client rawQuery、Prepare、QueryBuilder 及协议连接 query/prepare 拒绝，服务端只收到后续允许的 SQL，证明调用命令没有发送。覆盖普通字符串、注释、CALL 开头的字段名、存储函数及存储过程管理语句不被拦截。

新增 PolarDB 集成测试：建立随机名称的无结果存储过程，其执行会插入测试记录；在事务中逐一验证各入口拒绝调用，错误来自客户端（错误码 0），测试记录始终为 0，事务状态及 ping 保持正常，拒绝后预处理普通查询可继续执行。过程在 finally 删除，临时表随会话关闭清理。

专项 2 个测试、202 个断言通过；完整 PolarDB 回归 165 个测试、1971 个断言全部通过，无错误、失败或跳过，耗时 71.268 秒，峰值内存 124.34 MB。PHP 语法和 git diff --check 检查通过。README 已注明 CALL/多结果限制及本项目典型生产场景中存储过程使用相对较少的说明。

## 2026-10-09 开始事务参数改为枚举

begin_transaction() 的 flags 参数改为原生 PHP 枚举 TransactionStartFlags，默认 None，保留 ConsistentSnapshot、ReadWrite、ReadOnly、ConsistentSnapshotReadWrite、ConsistentSnapshotReadOnly 六种合法模式。枚举生成相应 START TRANSACTION SQL，不再接收整数或按位或组合；传入整数时在发送命令前抛出 TypeError。commit()/rollback() 的整数参数保持原接口。

协议测试覆盖全部六个枚举值发送的 SQL，以及原先合法和非法的整数均被拒绝且连接仍可使用。真实事务测试及 FastDb 风格适配调用已迁移到枚举，验证只读事务、显式读写、一致性快照以及提交和回滚行为。README 提供枚举示例和旧整数调用的迁移说明。

MySQL 测试服务器完整回归：165 个测试、1997 个断言全部通过，无错误、失败或跳过，耗时 37.378 秒，峰值内存 124.34 MB。PHP 语法及 git diff --check 检查通过。

## 2026-10-09 提交与回滚参数改为枚举

commit()/rollback() 的 flags 参数统一改为 TransactionCompletionFlags，默认 None，支持 Chain、NoChain、Release、NoRelease、NoChainRelease、ChainNoRelease、NoChainNoRelease 八种合法模式。枚举生成对应 SQL 后缀，并明确标记 RELEASE 模式用于同步关闭本地连接；不再接受整数或开始事务的枚举类型，错误类型在命令发送前抛出 TypeError。

协议测试对 commit 和 rollback 分别验证全部八个枚举值的 SQL，测试原先合法/非法整数及错误枚举类型被拒绝，连接仍然可用。真实事务测试及 FastDb 风格适配已迁移到枚举；RELEASE 测试覆盖 Release 与 NoChainRelease 的提交/回滚，新增 NoRelease 覆盖 session completion_type=2 的验证，确认提交可见、回滚不写入，并保留连接。原有链式事务、NO CHAIN、事务丢失保护等测试继续通过。README 已更新枚举调用与迁移说明。

MySQL 测试服务器完整回归：166 个测试、2071 个断言全部通过，无错误、失败或跳过，耗时 38.745 秒，峰值内存 124.34 MB。PHP 语法及 git diff --check 检查通过。

## 2026-10-09 事务枚举归类及中文语义注释

TransactionStartFlags 和 TransactionCompletionFlags 移至 src/Transaction，命名空间统一为 EasySwoole\Mysqli\Transaction；协议实现、测试适配和 README 引用同步更新。两个枚举的全部 14 个 case 均补充中文语义注释，说明默认行为、访问模式、一致性快照及事务结束后的链式事务和连接释放行为。

MySQL 测试服务器完整回归：166 个测试、2071 个断言全部通过，无错误、失败或跳过，耗时 38.537 秒。两个枚举文件语法检查及 git diff --check 通过。


## 2026-11-08 TLS 默认关闭及证书校验

新增 TlsMode（disabled、required、verify_ca、verify_identity），默认关闭 TLS。启用后发送 MySQL SSLRequest，在同一 TCP 连接上完成 TLS 1.2/1.3 握手，禁止明文降级。TLS 握手和证书身份校验共用原有连接/查询截止时间；caching_sha2_password 的完整认证在 TLS 内发送密码。支持自定义 CA 文件/目录、DNS/IP 身份校验、客户端证书和加密私钥。DNS/IP SAN 从证书 DER 中读取，避免将格式化文本中的逗号误识别为额外身份。

验证环境为 PHP 8.4.27、Swoole 6.2.4、MySQL 9.7.4。单元测试 155 项、1518 次断言通过；VerifyIdentity 模式下，现有集成测试 75 项、1055 次断言及新增 TLS 集成测试 2 项、13 次断言通过，无跳过。集成测试在独立临时数据库运行，覆盖文本/预处理、事务、死锁、断线恢复、压缩、大包及 128 MiB 内存限制的大参数，测试后已清理临时数据库和账号。额外实测 TLS 1.2/1.3、错误 CA/身份拒绝、REQUIRE X509 双向认证、加密客户端私钥及 TLS/压缩组合通过。

测试服务器使用原 CA 签发带 IP/DNS SAN 的服务端证书并保留原证书备份，通过重启 MySQL 生效；require_secure_transport 保持关闭。README 及 tests/README.md 已补充配置、注意事项和 TLS 测试环境变量。


## 2026-11-08 查询构造与 TLS SNI 修复

修复正则排序值的 SQL 注入风险，正则参数按最终 SQL 顺序绑定；保留重复排序表达式覆盖方向的行为。UNION 主构造器的排序和分页作用于整体结果，含局部排序/分页/嵌套 UNION 的分支加括号。显式 get(table, 0) 保留 LIMIT 0；JOIN 中的 IS NULL / IS NOT NULL 正确分隔运算符。

TLS 根据 tlsServerName 或连接 DNS 名发送 SNI，IP 不发送 SNI；SNI 与证书身份校验分离，VerifyCA 不额外要求名称匹配，VerifyIdentity 仍严格验证。为兼容旧版 Swoole，在发送 MySQL 认证数据前完成原生证书链检查和独立身份检查。TLS 默认关闭不变。

单元测试 167 项、1790 次断言全部通过。实际 MySQL 服务器只读验证 8 项、26 次断言通过，包含正则中的引号、嵌套 UNION 与分支/整体排序分页、LIMIT 0、JOIN NULL、TLS 重连及 CA-only 与身份校验的区别。ClientHello 抓包覆盖三种启用模式的 DNS 默认名/覆盖名、IP 不发送 SNI，证书不可信、过期和身份不匹配仍被拒绝。README 与 QueryBuilder 指南已同步，PHP 语法和 git diff --check 通过。未修改远程服务器配置或业务数据。

## 2026-11-08 QueryBuilder 按职责重构

公开 QueryBuilder 保留链式 API 与构建结果管理，编译实现拆分到 src/QueryBuilder：QueryParts 输入快照、QueryCompiler 调度、ConditionCompiler 条件与子查询、SelectCompiler 查询子句、WriteCompiler 写入、SqlBuffer 参数与 SQL、TableReference 表名/索引、ExpressionFactory 表达式、SqlFormatter 调试展示。WHERE/HAVING 与 ON 共用运算符编译，移除未参与执行的 mysqli 参数类型字符串，区分待构建状态与最近构建结果。主文件由 1632 行缩减到 836 行。

新增公开 API 快照测试，核对全部 71 个公开方法的名称、参数名/顺序/类型/默认值、返回类型、静态属性和公开常量，并验证子类工厂、链式调用、编译异常后的恢复和 reset/prefix 规则。单元测试 170 项、1809 次断言通过；独立临时数据库中的完整集成测试 83 项、1081 次断言通过，无跳过。另对重构前后执行 900 组确定性混合调用序列，精确 SQL、参数、展示 SQL、选项、事务标记、子查询元数据与复用状态全部一致，异常后 reset/raw 的状态也一致。临时数据库已清理，README 与 QueryBuilder 指南已补充内部职责说明。


## 2026-11-08 QueryBuilder 常用场景补齐

新增不可变 Expression 与 rawExpression()、selectRaw()、whereRaw()、havingRaw()、orderByRaw()，在 SELECT、JOIN、条件、排序和写入位置按最终 SQL 顺序绑定参数。原始条件自动加括号，防止局部 OR 越过外层 AND；表达式参数按值复制，避免外部 PHP 引用改变表达式。原有 func()/now()/inc()/dec()/not() 和整条 raw() 调用保留。

新增 whereColumn()/havingColumn()/joinOn() 及 OR 版本，统一引用各段字段名，支持库、表、列三级引用及转义反引号。HAVING 与 ON 分组复用条件编译器，支持嵌套及子查询 JOIN 别名定位。修正字段名包含 using 时 JOIN 遗漏 ON 的问题，对 USING JOIN 追加 ON 条件的非法组合明确报错。

新增 upsert()；批量 onDuplicate() 的隐式更新使用每行实际插入的新值，显式映射仍使用统一更新值。校验批量行非空、字段集合一致并按第一行重排，保留显式 NULL，先执行字段白名单过滤再校验。修正重复键更新中包含点、反引号或数字格式的字面字段名，以及内部行别名与目标表重名的边界。批量隐式更新使用 INSERT 行别名，需要 MySQL 8.0.19+；单行原有调用方式保留。

公开 API 快照核对原有 71 个方法的签名及常量保持不变，新增 16 个方法。新增 37 项单元场景和 6 项真实数据库场景，README 与 queryBuilder.md 对应方法下已同步用法、参数顺序、可信 SQL、分组闭包、JOIN 定位和版本限制。

在 PHP 8.4.27、Swoole 6.2.4、MySQL 9.7.4 上通过 VerifyIdentity TLS 运行全套 296 项测试、3030 次断言，耗时 190.306 秒，无失败、错误或跳过。最后的参数引用隔离及字面字段边界修正后，再次验证全部单元测试 207 项、1939 次断言，以及 QueryBuilder 数据库专项 14 项、36 次断言，均通过。数据库测试使用独立随机测试库；新增写入场景使用连接级临时表，结束后已清理测试库。PHP 语法、13 段新增文档示例的语法、Markdown 围栏及 git diff --check 均通过。凭据仅通过进程环境变量传入，未修改远程服务器配置。


## 2026-11-09 SQL 结构参数支持枚举与字符串

新增 QueryBuilder 命名空间下的 SortDirection、JoinType、ConditionConjunction、TableLockMode、LockWaitPolicy、ComparisonOperator 六个字符串枚举，以及根命名空间的 TlsVersion；所有 case 均附中文说明。相关公开结构参数接受对应 Enum|string，固定选项按原大小写/空白规则通过 tryFrom() 校验。普通 WHERE/HAVING/ON 的字符串运算符与连接符仍保留原始文本和扩展语法，Raw/Column 严格校验范围不变。原生 null/true/false、ConditionValue::Raw 及 Expression 语义不变。

LockWaitPolicy::Default 显式清除此前的 NOWAIT/SKIP LOCKED；selectForUpdate() 省略选项、null 或旧空字符串的行为保留，setSelectLockOption() 的空字符串仍清除策略。TLS 配置支持字符串与 TlsVersion 混合数组，getter 继续返回字符串数组、保留键；版本名和空数组仍在构建 TLS 选项时校验。TLS 默认关闭，证书校验和协议版本能力位不变。

核对全部 87 个公开方法，其中 27 个方法扩大参数类型；方法及参数名、参数顺序/默认值、返回类型和公开常量保持不变。子类重写原 string 参数的方法需要同步接受相应联合类型；README 和 QueryBuilder 指南已在对应方法下注明迁移要求及每项枚举的用法。顺带修复既有 NATURAL JOIN 错误追加 ON 的问题，使用空初始条件按同名列自然连接，拒绝附加 ON/USING 条件，参照 [MySQL JOIN 语法](https://dev.mysql.com/doc/refman/8.4/en/join.html)。

新增 44 项单元测试及 4 项真实数据库场景，覆盖枚举与字符串的精确 SQL/参数/标记一致、各条件入口、命名参数、失败不部分变更状态、锁策略重置、TLS 配置构造/restore/getter/错误路径、自然连接和 TLS 1.2/1.3 实际协商。QueryBuilder/TLS 数据库专项 21 项、69 次断言通过。16 个新增或修改 PHP 文件通过 PHP 8.4 语法检查及 PHP 8.1 目标语法解析（未在 PHP 8.1 运行时执行）；20 段枚举文档示例语法及 Markdown 围栏检查通过。

最终在 PHP 8.4.27、Swoole 6.2.4、MySQL 9.7.4 上通过 VerifyIdentity TLS 执行全套回归：344 项测试、3405 次断言全部通过，无错误、失败或跳过，耗时 207.830 秒，峰值内存 94.31 MB。其中单元测试 251 项，数据库集成测试 93 项。测试使用独立随机数据库，结束后已删除；凭据仅通过进程环境变量传入，未更改服务器配置。git diff --check 通过。


## 2026-11-09 查询边界、统计分页、分批及流式读取

修复 FIELD 自定义排序值被字符过滤改写的问题，全部值在最终 ORDER BY 位置绑定，保留符号、Unicode、NULL、布尔及数值；拒绝空列表和非标量成员。whereLike、UPDATE 字段及表前缀复用标识符解析，覆盖已引用名称、库/表/列、别名及可信表尾部。getColumn/getScalar 使用顶层字段列表扫描，保留函数参数中的逗号、别名空白及引用/注释。批量 query options 先全部校验再修改状态；编译结果仅在成功后发布，reset 只清待构建状态并保留最近成功快照，raw 替换整组参数，编译失败不再泄漏部分绑定。

新增 fromSub/getFromSub 快照及参数合并，SELECT、FROM、JOIN、条件、UNION、排序参数按 SQL 位置组合。count 构造完整结果集的行数统计，去除整体排序/分页/锁，支持 DISTINCT、GROUP/HAVING、UNION；默认普通 JOIN 计数投影常量以避免重复列名，GROUP 的位置引用保留原选择列表。aggregate/sum/avg/min/max 支持列引用及 Expression，整体 UNION 聚合通过 FROM 子查询表达。Client::paginate 在副本上执行 COUNT 与数据查询，共享一次总超时，保留超大 total/lastPage 的十进制精度；整数算法另经 4099 组随机和边界对照验证。

新增 reorder、forPageAfterId 和 Client::chunkById。模板保持不变，原 OR 条件整体分组后追加游标条件，固定唯一键 ASC，不使用 OFFSET；超大整数游标保留字符串，字符键（包括看起来像数字的 VARCHAR）依数据库排序。回调可以提前停止或释放本批数据，不改变下一批的行数判定。仅支持文档列明的普通 SELECT 模板；分组、DISTINCT、UNION、派生表及锁等复杂模板明确拒绝。

新增真正逐行消费的 ResultStream、Client::iterate/rawIterate、Statement::iterate，共享结果头/行解析且不积累完整数组。结果流在 EOF/关闭前独占连接；完整消费后保留连接，提前退出/异常时关闭连接，在事务中保留事务丢失保护。读取进行中的并发 close 会被拒绝，避免旧读取影响重连会话。Client 拥有的 Statement 自动清理，手工 Statement 在完整读取后可复用；deadline、元数据及成功回调范围已在对应方法下说明。单行仍需完整解包，超大单个 BLOB 的内存需求不会因流式接口消失。

原有 QueryBuilder 87 个公开签名、Client 12 个、Connection 20 个、Statement 11 个均核对未改。QueryBuilder API 快照新增 10 个方法。新增回归覆盖包含纯构造、状态失败恢复、命名参数、服务端严格模式/ANSI_QUOTES/NO_BACKSLASH_ESCAPES、分组/UNION计数、分页、数值及字符游标、流式分步送行、提前停止、会话和事务保护、超时及真实压缩传输。README、QueryBuilder 指南与 tests/README 已同步各方法用法及注意事项。

在 PHP 8.4.27、Swoole 6.2.4、MySQL 9.7.4 上通过 VerifyIdentity TLS 执行全套回归：460 项测试、3890 次断言全部通过，无错误、失败或跳过；单元测试 355 项，数据库集成测试 105 项，耗时 241.293 秒，峰值内存 96.31 MB。32 MiB 子进程分别在压缩关闭/开启时逐行读取 40,960,000 字节结果（10,000 行），校验每行哈希、EOF 元数据和连接复用。另以真实明文连接验证文本/预处理流及压缩两种模式，全部通过。测试使用独立随机库，结束后已删除，未更改服务器全局配置；凭据仅来自进程环境变量。

新增只读 Prepare 复用基准，实际运行每轮 10 次、三轮并校验结果，记录当前服务端及传输环境；该样本不代表生产工作负载，本次未加入默认 Statement 缓存。新增容器/CI矩阵覆盖 MySQL 8.0.46、8.4.13、9.7.4，以及 PHP 8.1.34/Swoole 5.1.8 和 PHP 8.4.27/Swoole 6.2.4；包含明文、VerifyIdentity TLS、压缩和严格 SQL 模式。脚本、工作流 YAML、固定依赖、环境探测、临时证书及异常/空矩阵清理路径已自检。本机无 Docker/daemon，容器矩阵尚未实际构建运行，不能将当前 9.7.4 回归当作已完成 8.0/8.4 或最低运行时回归。

22 个新增或修改 PHP 文件通过本机语法检查及 PHP 8.1 目标语法解析（后者不是 PHP 8.1 运行时测试）；132 段文档 PHP 示例语法及 Markdown 围栏检查通过。git diff --check 通过。

## 2026-11-09 状态、规划、认证与执行上下文重构及类型声明

QueryBuilder 将待构建状态按 PendingQuery 的统一默认值及结构说明保存为写时复制数组，编译输入通过 parts() 转为只读 QueryParts，最近成功的执行结果独立保存为只读 CompiledQuery。克隆不共享可变状态对象，也不依赖子类调用父级构造或克隆钩子。调试 SQL 保留独立属性，维持它先于 reset() 发布其他结果的原可见时机，包括子类延迟发布、后续编译失败再清理的场景。

新增无状态 SelectPlanner 与只读 SelectPlan，收拢 SELECT 来源和头部、COUNT 转换、聚合表达式及游标校验。仍保留工厂 new static()、COUNT/聚合的虚 get()、分页副本的子类 __clone() 和链式调用顺序；FROM/UNION 保存调用时快照，IN/EXISTS/JOIN 延后读取子查询的语义不变。前缀输入继续保持旧接口支持范围，未因内部规划器引入更窄类型。

Connection 将握手、能力协商、TLS 升级与认证抽出到 Authenticator，以显式回调沿用错误/OK 元数据更新；共享原 Deadline，TLS 校验先于凭据发送，认证完成后再启用压缩。文本命令发包及预处理参数数量校验共用内部入口，保留原校验顺序。Client 的 ExecutionContext 集中一次执行的超时、Client 自建 Statement 清理及通知，保留 prepare()/query() 子类分派、手工 Statement 所有权、EOF 与提前停止行为、清理异常优先级和成功回调时机。

按用户明确许可补齐公开及内部方法、参数和属性的原生类型声明，保留既有方法名、参数名、顺序和默认值；数据库值与灵活的旧接口输入保留 mixed，数组和回调另加 PHPDoc。构造/析构按 PHP 规则不声明返回类型。ApiContractTest 的重构前基线覆盖 44 个符号、278 个公开或受保护方法；QueryBuilder 当前 97 个公开接口快照已同步类型声明，NativeTypeCompletenessTest 检查全部源码声明。FastDb 子类 rawQuery 适配 bool|array，README 和 QueryBuilder 指南已明确子类重写返回类型的迁移要求。

本轮新增 49 项单元测试。状态回归中的 14 项、85 次断言分别对重构前和重构后源码执行，全部通过；覆盖 raw/column/group helper 后克隆、返回数组隔离、无父级钩子的子类、重置及调试时序、来源快照和延迟子查询读取。现有伪服务端测试继续覆盖完整 TCP/TLS、压缩、并发、异常和流式路径。

最终验证环境为 PHP 8.4.27、Swoole 6.2.4、真实 MySQL 9.7.4，VerifyIdentity TLS。单元套件 404 项、5886 次断言全部通过（4.183 秒，18.00 MB）；数据库集成套件 105 项、1176 次断言全部通过（238.779 秒，92.31 MB），合计 509 项、7062 次断言，无错误、失败或跳过。首轮集成初始化出现 7 次 1 秒 TCP 建连超时；保留原超时及源码，串行重跑完整集成套件通过。测试使用独立随机库，结束后已删除；凭据仅经环境变量传入，未修改服务器全局配置。

106 个 PHP 文件通过本机语法及 PHP 8.1 目标语法解析，132 段文档 PHP 示例及 Markdown 围栏检查通过；git diff --check 通过。容器/CI 脚本自检再次通过；本机未运行 Docker 矩阵，PHP 8.1 运行时与 MySQL 8.0/8.4 的实际运行结果仍以该矩阵执行为准，不能以本次 9.7.4 回归替代。

## 2026-11-09 修复 CI 暴露的 Swoole 5.1 DNS/SNI 证书验证

GitHub Actions run 42572640475 的 PHP 8.4/MySQL 8.0、8.4、9.7 三组通过。PHP 8.1.34/Swoole 5.1.8 组构建、Composer、环境与明文/压缩探测通过，单元测试中的 DNS 正常身份、VerifyCA 不要求匹配身份、IdentityMismatch 三项出现 2 errors / 1 failure，原因都是提前关闭验证导致的 CA 验证失败；该 lane 未进入集成套件。

官方 Swoole 5.1.8 到 sslHandshake 时才创建 SSL_CTX，且仅在 verify_peer=true 时加载 CA；6.2.4 则无条件加载。原逻辑错误地认为 setProtocol 已经加载 CA，在 DNS/SNI 路径提前关闭验证，造成 5.1 信任库为空，握手后再开启也不能补做原生链构建。现保持验证开启，通过 Client::set（不提前 open_ssl）存储完整 TLS 设置，再由 enableSSL 创建上下文和发布真实原生错误。仅当 DNS 分离路径遇到原生验证错误时，清除已发送 SNI 对应的原生主机名检查，要求 peer 证书存在且原生完整链验证成功，再完成原生握手；之后仍执行独立 CertificateIdentity 及原 Deadline 检查，全部通过才发送 MySQL 凭据。未关闭 CA 验证、未以叶证书用途校验替换原生完整链、未新建连接或延长超时，默认 TLS 关闭及公开接口不变。

新增 4 项中间链回归：root→intermediate→leaf 完整链的 DNS VerifyIdentity、VerifyCA 错名正例，以及缺 intermediate、不受信任链的无认证数据负例。正例另确认只把 leaf 交给 PHP 并信任 root 无法通过，提供 intermediate 后才能通过，确保测试可发现 leaf-only 的错误替代实现。README 补充中间证书链配置要求，测试说明记录实际 CI 运行状态。

在工作区隔离构建 PHP 8.1.34、Swoole 5.1.8、OpenSSL 3.5.9，未修改系统安装；PHP 发行源码 SHA256 与 php.net 发布元数据一致。以修复前源码重跑原三项，准确复现 2 errors / 1 failure；修复后 17 项 TLS 测试、644 次断言全部通过。最终两个本地运行时分别通过 408 项单元测试、6002 次断言（8.1/5.1 耗时 4.189 秒，8.4/6.2 耗时 4.347 秒，均 18.00 MB），无失败或跳过。

两个运行时分别在真实 MySQL 9.7.4 上通过 4 项 TLS 集成测试、21 次断言：证书身份验证、CA-only 与 SNI、超时重连、预处理 Unicode 结果、TLS 1.2/1.3 指定协商均通过。只执行会话及只读查询，未创建测试库、修改业务表或服务器全局配置。CI 的 Linux/Bookworm 使用 OpenSSL 3.0，本地隔离 8.1 使用 OpenSSL 3.5.9；修复后的 PHP 8.1 Docker lane 仍需新 CI 运行确认，不能把本地对照测试写成云端已经通过。

## 2026-11-09 中文注释与 Swoole 兼容清理说明

将源码、第一方 PHP 测试及 Shell 脚本的自然语言注释统一为中文，保留 PHPDoc 标签、类型表达式、类名和协议名等技术标识。README 新增“Swoole 版本兼容与清理”，源码以 SWOOLE_51_COMPAT、SWOOLE_SNI_COMPAT 标明不同兼容约束，Dockerfile 以 SWOOLE_51_BUILD 标明 5.x 构建分支的移除条件。

明确记录：运行时没有按 Swoole 版本号分流；5.1 的 CA 加载时机属于专用初始化约束，SNI/主机名分离在 5.1.8 和 6.2.4 均需要，不能随 5.1 支持一起删除。OpenSSL 能力检查、完整证书链、独立身份校验和共享超时继续保留；构建分支只有确认矩阵不再构建任何 5.x 版本后才可删除，PHP 最低版本与 Swoole 最低版本分别维护。

对本轮修改前后 106 个 PHP 文件逐项比较，去除注释和排版空白后的所有 Token 完全一致，捕获的 PHPDoc 声明保持一致；Shell/Dockerfile 排除纯注释行后的内容完全一致。106 个 PHP 文件通过真实 PHP 8.1 语法解析，修改的 Shell 脚本通过 bash -n，文档围栏及 git diff --check 通过。本轮仅修改注释和说明，未重复运行数据库回归。

## 2026-11-11 精简重复校验、执行包装与分页处理

基于 HEAD 6f8c407，批量写入只在 WriteCompiler::appendData() 归一化一次；insertAll() 保留常数级入口形状判断及可选字段过滤，不再遍历整批预查、再次归一化。upsert() 交给同一编译路径处理字段对齐和重复键更新，删除重复行归一化与提前更新列校验。normalizeBatch() 的列集合和列名检查只由首行建立一次，各行仍检查相同字段集合并按首行字段顺序对齐，保留 NULL、表达式、REPLACE 和绑定顺序。

字段比较不再在 addColumnCondition() 中丢弃字段解析结果，统一到 ConditionCompiler 编译引用。已 prepare 的只读 SQL 不再在 execute/iterate 时重复扫描；prepare 和文本查询仍检查 SQL 支持性，每次执行仍检查参数数量、协议编码和真实会话代次。

ExecutionContext 删除内部重复登记检查和异常包装，buffered() 只接受操作回调并保留 finally 清理，原异常对象直接传播；成功通知仍在清理完成后执行。测试使用真实 PHP TypeError 验证同一异常对象、previous 和清理/通知顺序。分页参数只在入口检查一次，COUNT 与数据查询按组件生成约定直接读取；总数保留数据库原始 int/string，末页继续使用精确十进制算法，未引入浮点计算。清理多余的分批封装、规划辅助函数及对应测试和接口资源，不改 fast-db，也未增加管理层、验证框架或持久缓存。

临时插桩对 1000 行带 NULL、乱序字段和表达式的批次计数：insertAll 归一化 2→1，upsert 3→1，嵌套行 insert/replace 保持 1 次；四种路径的 SQL、4000/4001 个参数的 SHA256 与修改前完全一致。三个字段比较节点的解析次数由 12→6，构建阶段为 0，全部发生在编译阶段。同一连接的一条文本查询、一次 prepare、两次 execute 和一次 iterate，SQL 支持性扫描由 5→2，仅保留文本和 prepare 入口。插桩只在工作区副本中执行，仓库源码不含计数设施。

PHP 8.4.27/Swoole 6.2.4 单元套件 401 项、5950 次断言通过（4.347 秒，18.00 MB）；PHP 8.1.34/Swoole 5.1.8 相同 401 项、5950 次断言通过（4.229 秒，18.00 MB）。8.1 首轮在未修改的过期证书夹具中遇到服务端读取返回 false 而非空字符串的断言失败；未改源码重跑完整单元套件通过，该次情况保留在验证记录中，没有通过放松 TLS 测试处理。

在真实 MySQL 9.7.4、VerifyIdentity TLS 上，完整集成套件 104 项、1171 次断言全部通过（240.486 秒，92.31 MB），包含批量写入、严格 SQL 模式、预处理复用、错误后复用、分页、事务及大包/流式清理。合计当前套件 505 项、7121 次断言通过，无跳过。集成使用独立随机数据库，结束后已删除，未修改服务器全局配置；凭据仅通过进程环境传递。

105 个 PHP 文件通过实际 PHP 8.1 语法解析，131 段文档 PHP 示例及 Markdown 围栏检查通过，git diff --check 通过。本轮没有自动提交或推送。
