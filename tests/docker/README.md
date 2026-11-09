# 可复现容器测试矩阵

该目录仅用于开发和 CI，不修改主机 MySQL、不连接远程测试服务器。需要已运行的 Docker Engine/Docker Desktop，以及主机 Bash、Python 3、OpenSSL；无需安装主机 PHP/Swoole。

```bash
bash tests/docker/self-check.sh
bash tests/docker/run-matrix.sh --list
bash tests/docker/run-matrix.sh php84-mysql84-tls
# 顺序执行全部四组，复用 Docker 构建缓存：
bash tests/docker/run-matrix.sh --all
```

`self-check.sh` 检查脚本语法、矩阵 JSON、依赖快照、环境校验器和临时证书；主机有 Ruby/Psych 时同时解析 workflow YAML，有 PHP 时同时检查 PHP 脚本。自检成功不表示 Docker 镜像已经构建或数据库测试已经运行。

| Lane | PHP | Swoole | MySQL | 主连接传输 | 单元测试 |
| --- | --- | --- | --- | --- | --- |
| `php81-mysql80-plain` | 8.1.34 | 5.1.8 | 8.0.46 | 明文 | 执行 |
| `php84-mysql80-tls` | 8.4.27 | 6.2.4 | 8.0.46 | VerifyIdentity | 复用相同当前运行时的单元覆盖 |
| `php84-mysql84-tls` | 8.4.27 | 6.2.4 | 8.4.13 | VerifyIdentity | 执行 |
| `php84-mysql97-tls` | 8.4.27 | 6.2.4 | 9.7.4 | VerifyIdentity | 复用相同当前运行时的单元覆盖 |

PHP 8.1/Swoole 5.1 代表最低支持分支，实际固定的是表中的补丁版本，不声称运行了 PHP 8.1.0 或 Swoole 5.1.0。矩阵不是全部维度的笛卡尔积；两个 PHP/Swoole 运行时各执行一次单元测试，所有 lane 都执行数据库集成套件。明文 lane 在执行前排除仅适用于 TLS 的 `TlsIntegrationTest`；其余用例使用 `--fail-on-skipped`，缺少数据库或大包能力不会被静默当作成功。

每组都额外以压缩关闭/开启执行传输探测，并运行现有 `CompressionTest`、`LargeParameterMemoryTest`，因此覆盖明文+压缩以及 TLS+压缩。TLS lane 设置服务端 `require_secure_transport=ON`，证书以临时 CA 签发，SAN 包含内部 DNS 名 `mysql`，客户端使用 VerifyIdentity。证书有效期两天，运行结束删除；CA 私钥不复制到数据库/测试容器。现有 TLS 集成测试继续验证 TLS 1.2/1.3 的指定版本协商。

每次创建独立 Docker 网络、数据卷和 `mysqli_ci` 数据库，使用只适用于这些临时容器的固定测试账号；不向主机发布 3306 端口。所有连接继承以下服务端严格模式：

```text
STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
```

`max_allowed_packet` 设置为 1 GiB，保证大包测试有条件执行。探测器核对真实 PHP/Swoole/MySQL 版本、会话 SQL 模式、TLS 版本/密码套件、压缩协商、文本和预处理读写结果；信息写入 `results/<lane>/environment.jsonl`，不包含账号密码。镜像 ID/解析后的仓库 digest、构建日志、数据库日志和 JUnit XML 也保存在该目录，并在 CI 上传七天。

源代码以只读方式挂载，Composer 安装和测试在容器的临时工作区中执行，既不覆盖主机 vendor，也不写主机 composer.lock。退出、失败或中断会删除本次创建的容器、卷、网络及临时证书；镜像保留以便下次复用缓存。

## 固定测试依赖

`dependencies.lock.json` 是当前 Composer 锁文件的测试专用快照，容器会将它复制为临时工作区的 composer.lock，然后检查快照与 composer.json 一致并安装依赖。根目录 composer.lock 继续保持原先不跟踪的策略。

修改 composer.json 或有意更新测试依赖时，在一次性工作区使用项目的 PHP 8.1 平台约束解析依赖，验证两个运行时，再将生成的锁文件更新到此快照。不要手工修改其中包版本或 content-hash。该快照不影响库使用者解析依赖。

## 镜像及源码依据

主要组件均使用明确版本标签，Swoole 从对应官方 release 源码构建，Composer 固定为 2.11.1。发行版基础层和标签仍可能由上游重新构建；如需逐字节重复镜像，使用测试产物记录的镜像 digest 固定 `FROM` / MySQL 镜像，并维护更新。

- [官方 MySQL 镜像及 8.4.13/9.7.4 标签](https://hub.docker.com/_/mysql)
- [官方 MySQL 8.0 历史标签](https://hub.docker.com/_/mysql/tags?name=8.0)
- [官方 PHP 8.1 标签](https://hub.docker.com/_/php/tags?name=8.1)
- [官方 PHP 运行时镜像](https://hub.docker.com/_/php)
- [Swoole 5.1.8](https://github.com/swoole/swoole-src/releases/tag/v5.1.8)、[Swoole 6.2.4](https://github.com/swoole/swoole-src/releases/tag/v6.2.4)
- [官方 Composer 镜像](https://hub.docker.com/_/composer)
