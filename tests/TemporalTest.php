<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemporalTest extends TestCase
{
    #[DataProvider('precisions')]
    public function testTemporalFormatsMatchTextPreparedAndBuilder(int $precision): void
    {
        if (MYSQL_CONFIG['host'] === '') { $this->markTestSkipped('Set MYSQLI_TEST_* to a test database.'); }
        $client = new Client(new Config(MYSQL_CONFIG));
        $table = 'mysqli_temporal_' . bin2hex(random_bytes(6));
        try {
            // 仅此测试会话允许零日期，固定时区避免 TIMESTAMP 转换影响期望值。
            $client->rawQuery("SET SESSION sql_mode = '', time_zone = '+00:00'");
            $client->rawQuery("CREATE TEMPORARY TABLE `{$table}` (id INT PRIMARY KEY,
                d DATE NULL, t TIME({$precision}) NULL, dt DATETIME({$precision}) NULL,
                ts TIMESTAMP({$precision}) NULL DEFAULT NULL)");
            $zero = $precision ? '.' . str_repeat('0', $precision) : '';
            $fraction = $precision ? '.' . substr('123456', 0, $precision) : '';
            $rows = [
                ['id' => 1, 'd' => '0000-00-00', 't' => '00:00:00' . $zero,
                    'dt' => '0000-00-00 00:00:00' . $zero, 'ts' => '0000-00-00 00:00:00' . $zero],
                ['id' => 2, 'd' => '2024-02-29', 't' => '00:00:00' . $zero,
                    'dt' => '2024-02-29 00:00:00' . $zero, 'ts' => '2024-02-29 00:00:00' . $zero],
                ['id' => 3, 'd' => '2024-02-29', 't' => '-49:02:03' . $fraction,
                    'dt' => '2024-02-29 23:59:58' . $fraction, 'ts' => '2024-02-29 23:59:58' . $fraction],
                ['id' => 4, 'd' => '2024-00-02', 't' => '838:59:59' . $zero,
                    'dt' => '2024-00-02 00:00:00' . $zero, 'ts' => null],
                ['id' => 5, 'd' => null, 't' => null, 'dt' => null, 'ts' => null],
                ['id' => 6, 'd' => '2024-02-29', 't' => '00:00:00' . $fraction,
                    'dt' => '2024-02-29 00:00:00' . $fraction, 'ts' => '2024-02-29 00:00:00' . $fraction],
            ];
            self::assertTrue($client->query((new QueryBuilder())->insertAll($table, $rows)));
            $sql = "SELECT id, d, t, dt, ts FROM `{$table}` ORDER BY id";
            self::assertSame($rows, $client->rawQuery($sql));
            $statement = $client->prepare($sql);
            try { self::assertSame($rows, $statement->execute()); }
            finally { $statement->close(); }
            self::assertSame($rows, $client->query((new QueryBuilder())->raw($sql)));
            // UPDATE 后同样读取，覆盖零小数、负 TIME 和超过 24 小时。
            $rows[1]['t'] = '-838:59:59' . $zero;
            self::assertTrue($client->query((new QueryBuilder())->where('id', 2)->update($table, ['t' => $rows[1]['t']])));
            self::assertSame($rows, $client->rawQuery($sql));
            self::assertSame($rows, $client->query((new QueryBuilder())->raw($sql)));
        } finally {
            $client->close(); // 临时表随会话关闭自动清理。
        }
    }

    public static function precisions(): array
    {
        return array_map(static fn(int $precision): array => [$precision], range(0, 6));
    }
}
