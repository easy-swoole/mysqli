<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use Swoole\Coroutine;

try {
    Coroutine\run(static function (): void {
        foreach ([false, true] as $compress) {
            $config = MYSQL_CONFIG;
            $config['compress'] = $compress;
            $config['timeout'] = 60.0;
            $config['maxConnectTime'] = 5.0;
            $client = new Client(new Config($config));
            $client->connect();
            try {
                $data = random_bytes(0x1000000);
                $digest = hash('sha256', $data);
                $statement = $client->prepare('SELECT OCTET_LENGTH(?) AS bytes, SHA2(?, 256) AS digest');
                $row = $statement->execute([$data, $data])[0];
                $statement->close();
                if ($row['bytes'] !== strlen($data) || $row['digest'] !== $digest) {
                    throw new RuntimeException('Large parameter length or digest mismatch');
                }
                echo json_encode(['case' => 'large', 'compress' => $compress, 'bytes' => $row['bytes'], 'verified' => true]), PHP_EOL;
                unset($data);

                // 10 字节命令 + 1 字节位图 + 1 字节类型标记 + 6 字节类型信息
                // + 两个 4 字节字符串长度 + 1 字节布尔值，共 27 字节开销。
                // 执行载荷恰好为 0xffffff 字节，因此必须追加空尾包。
                $data = random_bytes(intdiv(0xffffff - 27, 2));
                $statement = $client->prepare('SELECT OCTET_LENGTH(?) AS bytes, SHA2(?, 256) AS digest, ? AS flag');
                $row = $statement->execute([$data, $data, true])[0];
                $statement->close();
                if ($row['bytes'] !== strlen($data) || $row['digest'] !== hash('sha256', $data) || $row['flag'] !== 1) {
                    throw new RuntimeException('Exact-boundary parameter mismatch');
                }
                echo json_encode(['case' => 'exact_boundary', 'compress' => $compress, 'verified' => true]), PHP_EOL;
                unset($data);
            } finally {
                $client->close();
            }
        }
        echo json_encode(['peak_memory_bytes' => memory_get_peak_usage(true)]), PHP_EOL;
    });
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
