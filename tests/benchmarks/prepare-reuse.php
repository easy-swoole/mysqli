<?php

declare(strict_types=1);

// 只执行 SELECT 的基准测试，使用与 tests/bootstrap.php 相同的 MYSQLI_TEST_* 环境变量。
require dirname(__DIR__) . '/bootstrap.php';

use EasySwoole\Mysqli\{Client, Config, QueryBuilder};

$iterations = filter_var($argv[1] ?? '1000', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($iterations === false) { fwrite(STDERR, "Usage: php tests/benchmarks/prepare-reuse.php [positive iterations]\n"); exit(2); }
if (MYSQL_CONFIG['host'] === '') { fwrite(STDERR, "Set MYSQLI_TEST_HOST, MYSQLI_TEST_USER, MYSQLI_TEST_PASSWORD and MYSQLI_TEST_DATABASE.\n"); exit(2); }

Swoole\Coroutine\run(static function () use ($iterations): void {
    $config = new Config(MYSQL_CONFIG);
    $config->setTimeout(5.0);
    $client = new Client($config);
    try {
        $client->connect();
        $version = $client->rawQuery('SELECT VERSION() AS version')[0]['version'];
        $builder = (new QueryBuilder())->raw('SELECT ? AS benchmark_value', [42]);
        $statement = $client->prepare('SELECT ? AS benchmark_value');
        $check = static function ($rows): void {
            if ($rows !== [['benchmark_value' => 42]]) { throw new RuntimeException('Unexpected benchmark result'); }
        };
        try {
            for ($i = 0; $i < min(25, $iterations); $i++) {
                $check($client->query($builder));
                $check($statement->execute([42]));
            }
            // 在各轮交替执行顺序，减少单边预热或负载变化带来的影响。
            $elapsed = ['builder_prepare_execute_close' => 0.0, 'manual_prepared_reuse' => 0.0];
            for ($round = 0; $round < 3; $round++) {
                $modes = $round % 2 === 0 ? array_keys($elapsed) : array_reverse(array_keys($elapsed));
                foreach ($modes as $mode) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $check($mode === 'manual_prepared_reuse' ? $statement->execute([42]) : $client->query($builder));
                    }
                    $elapsed[$mode] += (hrtime(true) - $start) / 1_000_000_000;
                }
            }
            echo json_encode([
                'php' => PHP_VERSION, 'swoole' => phpversion('swoole'), 'mysql' => $version,
                'tls' => $client->mysqlClient()->isTlsEnabled(),
                'compression' => $client->mysqlClient()->isCompressionEnabled(),
                'iterations_per_round' => $iterations, 'rounds' => 3,
                'elapsed_seconds' => $elapsed,
                'mean_microseconds_per_query' => array_map(static fn(float $seconds): float => $seconds * 1_000_000 / ($iterations * 3), $elapsed),
                'prepared_reuse_speedup' => $elapsed['builder_prepare_execute_close'] / max(PHP_FLOAT_MIN, $elapsed['manual_prepared_reuse']),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
        } finally { $statement->close(); }
    } finally { $client->close(); }
});
