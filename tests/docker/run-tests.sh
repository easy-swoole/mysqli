#!/usr/bin/env bash
set -euo pipefail
: "${MYSQLI_MATRIX_TLS_MODE:?Set the transport mode through run-matrix.sh}"
: "${MYSQLI_EXPECT_MYSQL_VERSION:?Set the exact expected server version}"
: "${MYSQLI_EXPECT_PHP_VERSION:?Set the exact expected PHP version}"
: "${MYSQLI_EXPECT_SWOOLE_VERSION:?Set the exact expected Swoole version}"
mkdir -p /workspace/project /results
# Neither Composer nor PHPUnit may write into the host checkout.
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
    # These tests explicitly require TLS. Exclude them before execution, so every
    # remaining integration test must actually run rather than silently skip.
    integration_arguments+=(--filter '/^(?!.*TlsIntegrationTest)/')
fi
php tests/run.php "${integration_arguments[@]}"
