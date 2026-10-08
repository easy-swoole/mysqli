<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\TestCase;

final class DataTypeTest extends TestCase
{
    private ?Client $client = null;
    private string $table;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set FAST_DB_TEST_* or MYSQLI_TEST_* to the EasySwoole FastDb test database.');
        }
        $this->client = new Client(new Config(MYSQL_CONFIG));
        $this->table = 'mysqli_coroutine_types_' . bin2hex(random_bytes(6));
        $this->client->rawQuery("CREATE TABLE `{$this->table}` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tiny_signed TINYINT NOT NULL,
            tiny_unsigned TINYINT UNSIGNED NOT NULL,
            small_signed SMALLINT NOT NULL,
            small_unsigned SMALLINT UNSIGNED NOT NULL,
            medium_signed MEDIUMINT NOT NULL,
            medium_unsigned MEDIUMINT UNSIGNED NOT NULL,
            int_signed INT NOT NULL,
            int_unsigned INT UNSIGNED NOT NULL,
            bigint_signed BIGINT NOT NULL,
            bigint_unsigned BIGINT UNSIGNED NOT NULL,
            decimal_value DECIMAL(30, 10) NOT NULL,
            float_value FLOAT NOT NULL,
            double_value DOUBLE NOT NULL,
            bit_value BIT(8) NOT NULL,
            char_value CHAR(8) NOT NULL,
            varchar_value VARCHAR(255) NOT NULL,
            text_value TEXT NOT NULL,
            binary_value BINARY(4) NOT NULL,
            varbinary_value VARBINARY(255) NOT NULL,
            blob_value BLOB NOT NULL,
            date_value DATE NOT NULL,
            time_value TIME(6) NOT NULL,
            datetime_value DATETIME(6) NOT NULL,
            timestamp_value TIMESTAMP(6) NOT NULL,
            year_value YEAR NOT NULL,
            json_value JSON NOT NULL,
            enum_value ENUM('pending', 'done') NOT NULL,
            set_value SET('red', 'green', 'blue') NOT NULL,
            nullable_value VARCHAR(32) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->insertFixture();
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

    public function testPreparedResultPreservesCommonMysqlTypes(): void
    {
        $statement = $this->client->prepare("SELECT * FROM `{$this->table}` WHERE id = ?");
        try {
            $rows = $statement->execute([1]);
        } finally {
            $statement->close();
        }

        self::assertCount(1, $rows);
        $this->assertFixture($rows[0]);
    }

    public function testTextProtocolUsesTheSameValueSemantics(): void
    {
        $rows = $this->client->rawQuery("SELECT * FROM `{$this->table}` WHERE id = 1");
        self::assertCount(1, $rows);
        $this->assertFixture($rows[0]);
    }

    public function testPreparedNullUpdateAndWriteMetadata(): void
    {
        $statement = $this->client->prepare("UPDATE `{$this->table}` SET nullable_value = ? WHERE id = ?");
        try {
            self::assertTrue($statement->execute(['now set', 1]));
        } finally {
            $statement->close();
        }
        self::assertSame(1, $this->client->mysqlClient()?->affected_rows);
        self::assertSame([['nullable_value' => 'now set']], $this->client->rawQuery("SELECT nullable_value FROM `{$this->table}` WHERE id = 1"));
    }

    private function insertFixture(): void
    {
        $columns = [
            'tiny_signed', 'tiny_unsigned', 'small_signed', 'small_unsigned',
            'medium_signed', 'medium_unsigned', 'int_signed', 'int_unsigned',
            'bigint_signed', 'bigint_unsigned', 'decimal_value', 'float_value',
            'double_value', 'bit_value', 'char_value', 'varchar_value', 'text_value',
            'binary_value', 'varbinary_value', 'blob_value', 'date_value', 'time_value',
            'datetime_value', 'timestamp_value', 'year_value', 'json_value',
            'enum_value', 'set_value', 'nullable_value',
        ];
        $quotedColumns = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $this->client->prepare("INSERT INTO `{$this->table}` ({$quotedColumns}) VALUES ({$placeholders})");
        try {
            self::assertTrue($statement->execute([
                -128,
                255,
                -32768,
                65535,
                -8388608,
                16777215,
                -2147483648,
                4294967295,
                PHP_INT_MIN,
                '18446744073709551615',
                '12345678901234567890.1234567890',
                1.5,
                1.23456789012345,
                170,
                'char值',
                'utf8mb4：协程客户端 🚀',
                "line one\nline two",
                "\0A\xffB",
                "\0binary\xff",
                "blob\0payload\xff",
                '2024-02-29',
                '-49:02:03.123456',
                '2024-02-29 23:59:58.123456',
                '2024-02-29 23:59:58.654321',
                2024,
                '{"enabled":true,"count":2,"text":"测试"}',
                'done',
                'red,blue',
                null,
            ]));
        } finally {
            $statement->close();
        }
        self::assertSame(1, $this->client->mysqlClient()?->affected_rows);
        self::assertSame(1, $this->client->mysqlClient()?->insert_id);
    }

    private function assertFixture(array $row): void
    {
        self::assertSame(1, $row['id']);
        self::assertSame(-128, $row['tiny_signed']);
        self::assertSame(255, $row['tiny_unsigned']);
        self::assertSame(-32768, $row['small_signed']);
        self::assertSame(65535, $row['small_unsigned']);
        self::assertSame(-8388608, $row['medium_signed']);
        self::assertSame(16777215, $row['medium_unsigned']);
        self::assertSame(-2147483648, $row['int_signed']);
        self::assertSame(4294967295, $row['int_unsigned']);
        self::assertSame(PHP_INT_MIN, $row['bigint_signed']);
        self::assertSame('18446744073709551615', $row['bigint_unsigned']);
        self::assertSame('12345678901234567890.1234567890', $row['decimal_value']);
        self::assertSame(1.5, $row['float_value']);
        self::assertEqualsWithDelta(1.23456789012345, $row['double_value'], 0.00000000000001);
        self::assertSame("\xaa", $row['bit_value']);
        self::assertSame('char值', $row['char_value']);
        self::assertSame('utf8mb4：协程客户端 🚀', $row['varchar_value']);
        self::assertSame("line one\nline two", $row['text_value']);
        self::assertSame("\0A\xffB", $row['binary_value']);
        self::assertSame("\0binary\xff", $row['varbinary_value']);
        self::assertSame("blob\0payload\xff", $row['blob_value']);
        self::assertSame('2024-02-29', $row['date_value']);
        self::assertSame('-49:02:03.123456', $row['time_value']);
        self::assertSame('2024-02-29 23:59:58.123456', $row['datetime_value']);
        self::assertSame('2024-02-29 23:59:58.654321', $row['timestamp_value']);
        self::assertSame(2024, $row['year_value']);
        self::assertEquals(['enabled' => true, 'count' => 2, 'text' => '测试'], json_decode($row['json_value'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('done', $row['enum_value']);
        self::assertSame('red,blue', $row['set_value']);
        self::assertNull($row['nullable_value']);
    }
}
