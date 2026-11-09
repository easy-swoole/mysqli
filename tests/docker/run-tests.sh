#!/usr/bin/env bash
set -euo pipefail
: "${MYSQLI_MATRIX_TLS_MODE:?Set the transport mode through run-matrix.sh}"
: "${MYSQLI_EXPECT_MYSQL_VERSION:?Set the exact expected server version}"
: "${MYSQLI_EXPECT_PHP_VERSION:?Set the exact expected PHP version}"
: "${MYSQLI_EXPECT_SWOOLE_VERSION:?Set the exact expected Swoole version}"
mkdir -p /workspace/project /results
# Composer 和 PHPUnit 均不得写入主机上的仓库检出目录。
tar -C /source --exclude='./.git' --exclude='./vendor' --exclude='./tests/docker/results' -cf - . \
    | tar -C /workspace/project -xf -
cd /workspace/project
cp tests/docker/dependencies.lock.json composer.lock
composer validate --no-check-publish --no-check-all --check-lock
composer install --prefer-dist --no-interaction --no-progress --no-scripts
composer check-platform-reqs
php tests/docker/probe.php | tee /results/environment.jsonl
if [ "${MYSQLI_MATRIX_RUN_UNIT:-0}" = 1 ]; then
    php tests/run.php --testsuite unit --fail-on-skipped --do-not-cache-result --log-junit /results/unit.xml
fi
integration_arguments=(--testsuite integration --fail-on-skipped --do-not-cache-result --log-junit /results/integration.xml)
if [ "$MYSQLI_MATRIX_TLS_MODE" = disabled ]; then
    # 这些测试明确要求 TLS，执行前先排除，确保其余所有
    # 集成测试都实际运行，而不会被静默跳过。
    integration_arguments+=(--filter '/^(?!.*TlsIntegrationTest)/')
fi
php tests/run.php "${integration_arguments[@]}"
