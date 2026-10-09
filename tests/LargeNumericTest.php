<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LargeNumericTest extends TestCase
{
    private ?Client $client = null;
    private string $table;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            self::markTestSkipped('Set MYSQLI_TEST_* environment variables for integration tests');
        }
        $this->client = new Client(new Config(MYSQL_CONFIG));
        $this->table = 'mysqli_coroutine_large_numeric_' . bin2hex(random_bytes(6));
        $this->client->rawQuery("CREATE TABLE `{$this->table}` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            float_value FLOAT NOT NULL,
            double_value DOUBLE NOT NULL,
            decimal_value DECIMAL(65, 30) NOT NULL,
            decimal_integer DECIMAL(65, 0) NOT NULL
        ) ENGINE=InnoDB");
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->rawQuery("DROP TABLE IF EXISTS `{$this->table}`");
            } finally {
                $this->client->close();
                $this->client = null;
            }
        }
    }

    #[DataProvider('largeNumericValues')]
    public function testInsertAndUpdatePreserveLargeNumericValues(string $mode, array $insert, array $update): void
    {
        $this->writeValues($mode, $insert, false);
        self::assertSame(1, $this->client->getLastInsertId());
        self::assertSame(1, $this->client->getLastAffectRows());
        $this->assertStoredValues($insert);

        $this->writeValues($mode, $update, true);
        self::assertSame(0, $this->client->getLastInsertId());
        self::assertSame(1, $this->client->getLastAffectRows());
        $this->assertStoredValues($update);
    }

    private function writeValues(string $mode, array $values, bool $update): void
    {
        if ($mode === 'raw') {
            // 浮点数转为可回读的 JSON 数字字面量，DECIMAL 始终以字符串保存。
            $literals = [
                json_encode($values['float_value'], JSON_THROW_ON_ERROR),
                json_encode($values['double_value'], JSON_THROW_ON_ERROR),
                "'{$values['decimal_value']}'",
                "'{$values['decimal_integer']}'",
            ];
            if ($update) {
                $pairs = [];
                foreach (array_keys($values) as $index => $column) {
                    $pairs[] = "{$column} = {$literals[$index]}";
                }
                $sql = "UPDATE `{$this->table}` SET " . implode(', ', $pairs) . ' WHERE id = 1';
            } else {
                $sql = "INSERT INTO `{$this->table}` (" . implode(', ', array_keys($values))
                    . ') VALUES (' . implode(', ', $literals) . ')';
            }
            self::assertTrue($this->client->rawQuery($sql));
        } elseif ($mode === 'prepared') {
            $sql = $update
                ? "UPDATE `{$this->table}` SET float_value = ?, double_value = ?, decimal_value = ?, decimal_integer = ? WHERE id = 1"
                : "INSERT INTO `{$this->table}` (float_value, double_value, decimal_value, decimal_integer) VALUES (?, ?, ?, ?)";
            $statement = $this->client->prepare($sql);
            try {
                self::assertTrue($statement->execute(array_values($values)));
            } finally {
                $statement->close();
            }
        } else {
            $builder = new QueryBuilder();
            $update ? $builder->where('id', 1)->update($this->table, $values) : $builder->insert($this->table, $values);
            self::assertTrue($this->client->query($builder));
        }
    }

    private function assertStoredValues(array $expected): void
    {
        $sql = "SELECT float_value, double_value, decimal_value, decimal_integer,
            CAST(decimal_value AS CHAR) AS decimal_text,
            CAST(decimal_integer AS CHAR) AS integer_text FROM `{$this->table}` WHERE id = 1";
        $this->assertRows($expected, $this->client->rawQuery($sql), false);
        $statement = $this->client->prepare($sql);
        try {
            $this->assertRows($expected, $statement->execute(), true);
        } finally {
            $statement->close();
        }
    }

    private function assertRows(array $expected, array $rows, bool $binary): void
    {
        self::assertCount(1, $rows);
        $row = $rows[0];
        foreach (['float_value', 'double_value'] as $column) {
            self::assertIsFloat($row[$column]);
            self::assertTrue(is_finite($row[$column]), "{$column} must not overflow to INF or NaN");
            // 文本 FLOAT 输出的有效位数少于二进制协议；使用各自合理的相对误差。
            // 先做除法，避免接近 DOUBLE 上限时计算绝对差或容差引入溢出。
            $relativeTolerance = $column === 'float_value' ? ($binary ? 2e-7 : 5e-6) : 2e-15;
            self::assertEqualsWithDelta(1.0, $row[$column] / $expected[$column], $relativeTolerance, $column);
        }
        self::assertSame($expected['decimal_value'], $row['decimal_value']);
        self::assertSame($expected['decimal_integer'], $row['decimal_integer']);
        self::assertSame($expected['decimal_value'], $row['decimal_text']);
        self::assertSame($expected['decimal_integer'], $row['integer_text']);
    }

    public static function largeNumericValues(): array
    {
        $maximum = [
            'float_value' => 3.402823466e38,
            'double_value' => 1.7976931348623157e308,
            'decimal_value' => str_repeat('9', 35) . '.' . str_repeat('9', 30),
            'decimal_integer' => str_repeat('9', 65),
        ];
        $negative = [
            'float_value' => -$maximum['float_value'],
            'double_value' => -$maximum['double_value'],
            'decimal_value' => '-' . $maximum['decimal_value'],
            'decimal_integer' => '-' . $maximum['decimal_integer'],
        ];
        $large = [
            'float_value' => 1.234567e35,
            'double_value' => 1.234567890123456e300,
            'decimal_value' => '12345678901234567890123456789012345.123456789012345678901234567890',
            'decimal_integer' => '12345678901234567890123456789012345678901234567890123456789012345',
        ];
        $cases = [];
        foreach (['raw', 'prepared', 'builder'] as $mode) {
            $cases["{$mode}-positive-to-negative-limits"] = [$mode, $maximum, $negative];
            $cases["{$mode}-negative-limits-to-large-values"] = [$mode, $negative, $large];
            $cases["{$mode}-large-values-to-positive-limits"] = [$mode, $large, $maximum];
        }
        return $cases;
    }
}
