# 测试说明

单元测试需要 PHP 8.1 或更高版本、Swoole 5.1 或更高版本，无需数据库：

```bash
composer test:unit
```

集成测试需要可创建临时表、测试表和存储过程的测试数据库：

```bash
export FAST_DB_TEST_HOST=127.0.0.1
export FAST_DB_TEST_PORT=3306
export FAST_DB_TEST_USER=test
export FAST_DB_TEST_PASSWORD='secret'
export FAST_DB_TEST_DATABASE=test
composer test:integration
```

集成套件使用会话临时表和随机命名的持久表及存储过程，覆盖文本查询、原生预处理语句、`QueryBuilder`、显式重连和单次查询超时。临时表随会话关闭删除，持久夹具由用例清理。类型测试通过文本及预处理结果协议验证有符号和无符号整数（包括完整的 `BIGINT UNSIGNED` 范围）、定点小数、单精度和双精度浮点数、位字段、字符及二进制字符串、文本及二进制大对象、日期、跨多天的负时间、日期时间、时间戳、年份、JSON、枚举、集合和 NULL。

服务端允许超过 16 MiB 的数据包时，`LargePacketTest` 会上传、读取、更新并再次读取超过 MySQL 单包边界 `0xFFFFFF` 的负载，以长度和 SHA-256 校验分包与重组没有改变任何字节。

`TransactionTest` 使用独立的 InnoDB 会话验证提交可见性、完整回滚、回滚到保存点、锁等待错误 `1205`，以及两个真实会话间的死锁：恰好一个事务收到错误 `1213`，另一个事务成功提交。

协议恢复测试还覆盖显式回滚前的死锁状态、拒绝畸形结果后须显式重连、截断整数和二进制行解码，以及分段参数负载。

`LargeParameterMemoryTest` 在 `memory_limit=128M` 的独立 PHP 进程中运行，将同一份 16 MiB 随机二进制字符串绑定两次，分别在关闭和开启压缩时核对服务端返回的长度与 SHA-256。该测试需要 `proc_open`、可继承的测试数据库环境变量，以及足以容纳约 32 MiB 执行报文的服务端 `max_allowed_packet`。

`DeadlineTest` 验证更短的限制不能延长或重新启用已经耗尽的超时预算。`SessionStateTest` 覆盖会话代次、主动与意外断线、死锁与锁等待，以及事务结束后的连接释放。已有的模拟服务端测试通过公开客户端接口，继续覆盖完整的 PacketStream 封包、压缩、共享超时与恢复路径。

## TLS 测试

`TlsTest` 属于单元套件，使用 PHP OpenSSL 扩展生成一次性证书，并启动本地模拟 MySQL 服务端。覆盖内容包括默认关闭 TLS、SSLRequest 封包与序号、TLS 内的完整 caching_sha2 认证、不受信任或过期证书、DNS/IP 身份校验、通配符边界、SAN 解析歧义、拒绝不支持 TLS 的服务端且不回退明文，以及共享握手截止时间。测试还覆盖根证书、中间证书和叶证书构成的完整链：服务端提供完整可信链时成功，缺少或不信任中间证书时不会发送 MySQL 凭据。OpenSSL 预检确认，仅用叶证书和根信任库无法验证该链夹具。测试类结束后删除夹具私钥。

使用 TLS 运行集成测试时，请使用独立的一次性数据库，因为套件会替换夹具表：

```bash
export MYSQLI_TEST_TLS_MODE=verify_identity
export MYSQLI_TEST_TLS_CA_FILE=/secure/mysql/ca.pem
# 可选配置：MYSQLI_TEST_TLS_CA_PATH、MYSQLI_TEST_TLS_SERVER_NAME
# 双向 TLS 的可选配置：MYSQLI_TEST_TLS_CERT_FILE、MYSQLI_TEST_TLS_KEY_FILE、
# MYSQLI_TEST_TLS_KEY_PASSPHRASE
composer test:integration
```

全部集成测试及其子进程都会继承这些设置。不配置 TLS 环境变量时，集成测试连接保持默认的未加密方式。

## 查询构建、分页与流式读取

`QueryBuilderIdentifierRegressionTest` 覆盖表前缀、带反引号或数据库限定名的标识符、UPDATE 列引用，以及 `getColumn()` 和 `getScalar()` 中完整的别名与表达式。查询构造器的回归和状态测试覆盖 FIELD 排序值绑定，以及失败、重置、原始 SQL 之间的状态隔离；聚合和分页测试覆盖 DISTINCT、GROUP BY/HAVING、UNION、派生表与超大总数。

`ResultStreamTest` 和 `StreamingTest` 覆盖单次遍历、读取至 EOF 前独占连接、提前退出或析构或异常时的清理、读取阻塞期间拒绝并发关闭、语句复用和自动管理的差异、事务丢失保护，以及包含消费者处理时间的共享截止时间。客户端分页测试验证大整数计数精度与 COUNT/数据查询共享的超时预算。这些测试补充了已有的缓冲协议测试；具体容器运行时的结果，仍需执行下述矩阵确认。

## 重构与原生类型约束

`ApiContractTest` 将现有公开和受保护方法与重构前的基线比较，检查方法名、参数名及顺序与默认值、静态性、可见性、引用语义和已有输入类型范围。类型补全允许在原先没有类型的位置增加原生声明。`QueryBuilderPublicApiTest` 还记录当前完整的 QueryBuilder 签名。有意修改 API 时，应先审查变更，再更新对应基线。

`NativeTypeCompletenessTest` 检查源码声明的全部属性、方法参数与返回值，包括私有方法。构造和析构方法按 PHP 要求不声明返回类型，引擎生成的枚举属性不参与检查。灵活的旧接口输入和数据库值保留 `mixed`，数组结构与回调签名另以 PHPDoc 描述。

`QueryBuilderStateTest` 覆盖原始条件、列比较和条件组设置后的克隆隔离，子类构造及克隆钩子，重置与调试结果的发布时机，完成结果的复制，以及 FROM、UNION、IN、EXISTS 和 JOIN 的子查询快照。

`SelectPlannerTest`、`AuthenticatorTest` 和 `ClientExecutionContextTest` 覆盖子类方法分派、来源快照、能力与认证检查、共享执行截止时间、原异常传播、finally 清理的异常优先级、自建语句清理和观察回调时机。已有的模拟服务端与真实数据库测试继续验证拆分后的完整调用路径。

## 可复现容器矩阵

Docker 启动后，运行 `bash tests/docker/run-matrix.sh --all` 执行全部组合，或从 `bash tests/docker/run-matrix.sh --list` 中选择一组。无需主机安装 PHP/Swoole，也无需已有的 MySQL 服务。精确版本和隔离规则见[容器测试说明](docker/README.md)。

四个测试组合分别覆盖 PHP 8.1.34/Swoole 5.1.8 通过明文连接 MySQL 8.0.46，以及 PHP 8.4.27/Swoole 6.2.4 通过 VerifyIdentity TLS 连接 MySQL 8.0.46、8.4.13 和 9.7.4。每组都会测试关闭与开启压缩的情况，无需为压缩额外扩充矩阵。服务端使用严格 SQL 模式、独立的一次性数据库和临时证书。每个 PHP/Swoole 运行时执行一次单元套件，每组都执行数据库集成套件。明文组合只排除专门要求 TLS 的集成测试，其余选定用例意外跳过时测试失败。

`bash tests/docker/self-check.sh` 只检查测试脚本，不启动容器。自检通过不代表 Docker 镜像构建或数据库矩阵已经通过。当前主机没有 Docker 运行环境。[首次 CI 运行](https://github.com/easy-swoole/mysqli/actions/runs/42572640475)中，PHP 8.4 下的 MySQL 8.0/8.4/9.7 三组通过，PHP 8.1/Swoole 5.1 的 DNS/SNI 证书验证单元测试失败。该问题已在本地 PHP 8.1.34/Swoole 5.1.8 中复现，并在保持证书链验证开启的前提下修复。两个受支持的运行时分支均通过本地单元套件和真实 TLS 测试；上述记录不代表修复后的 PHP 8.1 容器组合已通过，仍需相应 CI 结果确认。`tests/docker/` 下固定的依赖快照仅用于临时测试工作区，根目录 composer.lock 的管理规则不变。

## 预处理语句复用基准

使用前文配置的独立测试数据库环境变量运行：

```bash
php tests/benchmarks/prepare-reuse.php 1000
```

基准只执行 `SELECT ? AS benchmark_value`，对比 `Client::query($builder)` 每次查询都准备、执行并关闭语句，与手工准备 `Statement` 后多次复用执行的耗时。它会校验每次结果、预热、在三轮测试中交替执行顺序，并以 JSON 报告 PHP/Swoole/MySQL 版本、TLS/压缩设置、总耗时和每次查询的平均微秒数。

可选参数为每轮的正整数执行次数。耗时受网络延迟、服务端负载和传输设置影响，报告的加速比仅为实测结果，不是测试通过阈值或生产性能承诺。语句只在当前服务端会话内可复用，断线后需要重新准备。此命令不执行或验证 Docker 矩阵。

## 流式读取内存上限

`StreamMemoryIntegrationTest` 在 `memory_limit=32M` 的子进程中，通过真实文本协议迭代器读取 10,000 行、每行 4 KiB 的数据，共 40,960,000 字节负载。测试逐行校验哈希、EOF 元数据和连接复用，并分别覆盖关闭与开启压缩。流的总超时预算为 120 秒，以适应远程网络传输，不改变库的默认超时。每个子进程都必须在 32 MiB 内完成，而不是先缓存全部结果。
