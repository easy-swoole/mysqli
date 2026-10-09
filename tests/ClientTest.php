<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\TimeoutException;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private ?Client $client = null;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set FAST_DB_TEST_* or MYSQLI_TEST_* to the EasySwoole FastDb test database.');
        }
        $this->client = new Client(new Config(MYSQL_CONFIG));
        foreach (glob(__DIR__ . '/resources/*.sql') as $fixture) {
            $this->client->rawQuery(trim((string) file_get_contents($fixture)));
        }
        // SQL 夹具只创建表；使用会话临时表提供固定数据，不依赖测试库已有记录。
        $this->client->rawQuery('CREATE TEMPORARY TABLE student (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->client->rawQuery("INSERT INTO student (id, name) VALUES (1, 'mysqli-fixture')");
    }

    protected function tearDown(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    public function testRawQueryAgainstFastDbFixture(): void
    {
        $rows = $this->client->rawQuery('SELECT id, name FROM student ORDER BY id LIMIT 1');
        self::assertIsArray($rows);
        self::assertNotEmpty($rows);
        self::assertArrayHasKey('id', $rows[0]);
        self::assertArrayHasKey('name', $rows[0]);
        self::assertTrue($this->client->ping());
    }

    public function testNativePreparedStatementSupportsAllScalarTypes(): void
    {
        $statement = $this->client->prepare('SELECT ? AS integer_value, ? AS float_value, ? AS string_value, ? AS null_value, ? AS bool_value');
        try {
            $rows = $statement->execute([42, 1.25, '协程 mysqli', null, true]);
        } finally {
            $statement->close();
        }

        self::assertSame(42, $rows[0]['integer_value']);
        self::assertSame(1.25, $rows[0]['float_value']);
        self::assertSame('协程 mysqli', $rows[0]['string_value']);
        self::assertNull($rows[0]['null_value']);
        self::assertSame(1, $rows[0]['bool_value']);
    }

    public function testQueryBuilderUsesPreparedProtocol(): void
    {
        $builder = (new QueryBuilder())->where('id', 1)->get('student');
        $rows = $this->client->query($builder);
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
    }

    public function testPrepareErrorIsReportedAndConnectionRemainsUsable(): void
    {
        try {
            $this->client->prepare('SELEC ? AS invalid_syntax');
            self::fail('Expected prepare syntax error');
        } catch (Exception $error) {
            self::assertSame(1064, $error->getCode());
            self::assertStringContainsString('SELEC ? AS invalid_syntax', $error->getMessage());
            self::assertStringContainsString('syntax', strtolower($error->getMessage()));
        }

        self::assertTrue($this->client->ping());
        self::assertSame([['after_prepare_error' => 1]], $this->client->rawQuery('SELECT 1 AS after_prepare_error'));
    }

    public function testExecuteErrorIsReportedAndPreparedStatementCanBeReused(): void
    {
        $table = 'mysqli_execute_error_' . bin2hex(random_bytes(5));
        $this->client->rawQuery("CREATE TABLE `{$table}` (id BIGINT PRIMARY KEY, value VARCHAR(32) NOT NULL) ENGINE=InnoDB");
        try {
            $statement = $this->client->prepare("INSERT INTO `{$table}` (id, value) VALUES (?, ?)");
            try {
                self::assertTrue($statement->execute([1, 'first']));
                self::assertSame(1, $this->client->getLastAffectRows());

                try {
                    $statement->execute([1, 'duplicate']);
                    self::fail('Expected duplicate primary-key error during execute');
                } catch (Exception $error) {
                    self::assertSame(1062, $error->getCode());
                    self::assertStringContainsString("INSERT INTO `{$table}`", $error->getMessage());
                    self::assertStringContainsString('Duplicate entry', $error->getMessage());
                }

                self::assertNull($this->client->getLastInsertId());
                self::assertNull($this->client->getLastAffectRows());
                self::assertTrue($this->client->ping());
                self::assertTrue($statement->execute([2, 'after error']));
                self::assertSame(1, $this->client->getLastAffectRows());
            } finally {
                $statement->close();
            }

            self::assertSame(
                [['id' => 1, 'value' => 'first'], ['id' => 2, 'value' => 'after error']],
                $this->client->rawQuery("SELECT id, value FROM `{$table}` ORDER BY id"),
            );
        } finally {
            $this->client->rawQuery("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testPreparedInsertReportsInvalidColumnAndInvalidDataTypes(): void
    {
        // 此场景验证严格模式的数据错误，仅设置当前测试连接，不修改服务端全局配置。
        $this->client->rawQuery("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES')");
        $table = 'mysqli_invalid_data_' . bin2hex(random_bytes(5));
        $this->client->rawQuery("CREATE TABLE `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            integer_value INT NOT NULL,
            short_value VARCHAR(8) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            try {
                $this->client->prepare("INSERT INTO `{$table}` (missing_column) VALUES (?)");
                self::fail('Expected unknown-column error during prepare');
            } catch (Exception $error) {
                self::assertSame(1054, $error->getCode());
                self::assertStringContainsString('missing_column', $error->getMessage());
                self::assertStringContainsString('Unknown column', $error->getMessage());
            }
            self::assertTrue($this->client->ping());

            $statement = $this->client->prepare("INSERT INTO `{$table}` (integer_value, short_value) VALUES (?, ?)");
            try {
                try {
                    $statement->execute(['not-an-integer', 'valid']);
                    self::fail('Expected invalid integer error during execute');
                } catch (Exception $error) {
                    self::assertSame(1366, $error->getCode());
                    self::assertStringContainsString('Incorrect integer value', $error->getMessage());
                    self::assertStringContainsString('integer_value', $error->getMessage());
                }
                self::assertNull($this->client->getLastInsertId());
                self::assertNull($this->client->getLastAffectRows());
                self::assertTrue($this->client->ping());

                try {
                    $statement->execute([1, 'longer-than-eight']);
                    self::fail('Expected data-too-long error during execute');
                } catch (Exception $error) {
                    self::assertSame(1406, $error->getCode());
                    self::assertStringContainsString('Data too long', $error->getMessage());
                    self::assertStringContainsString('short_value', $error->getMessage());
                }
                self::assertTrue($this->client->ping());
                self::assertTrue($statement->execute([42, 'valid']));
                self::assertSame(1, $this->client->getLastAffectRows());
            } finally {
                $statement->close();
            }

            self::assertSame(
                [['integer_value' => 42, 'short_value' => 'valid']],
                $this->client->rawQuery("SELECT integer_value, short_value FROM `{$table}` ORDER BY id"),
            );
        } finally {
            $this->client->rawQuery("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testPreparedInsertRejectsInvalidJsonAndStatementCanBeReused(): void
    {
        $table = 'mysqli_invalid_json_' . bin2hex(random_bytes(5));
        $this->client->rawQuery("CREATE TABLE `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            document JSON NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {
            $statement = $this->client->prepare("INSERT INTO `{$table}` (document) VALUES (?)");
            try {
                try {
                    $statement->execute(['{"enabled":true,}']);
                    self::fail('Expected invalid JSON error during execute');
                } catch (Exception $error) {
                    self::assertSame(3140, $error->getCode());
                    self::assertStringContainsString('Invalid JSON text', $error->getMessage());
                    self::assertStringContainsString('document', $error->getMessage());
                }

                self::assertNull($this->client->getLastInsertId());
                self::assertNull($this->client->getLastAffectRows());
                self::assertTrue($this->client->ping());
                self::assertSame([['row_count' => 0]], $this->client->rawQuery("SELECT COUNT(*) AS row_count FROM `{$table}`"));

                $validJson = '{"enabled":true,"items":[1,2],"nested":{"text":"测试"}}';
                self::assertTrue($statement->execute([$validJson]));
                self::assertSame(1, $this->client->getLastAffectRows());
            } finally {
                $statement->close();
            }

            $rows = $this->client->rawQuery("SELECT document FROM `{$table}`");
            self::assertCount(1, $rows);
            self::assertEquals(
                ['enabled' => true, 'items' => [1, 2], 'nested' => ['text' => '测试']],
                json_decode($rows[0]['document'], true, flags: JSON_THROW_ON_ERROR),
            );
        } finally {
            $this->client->rawQuery("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    public function testSingleQueryTimeoutClosesDesynchronizedConnection(): void
    {
        try {
            $this->client->rawQuery('SELECT SLEEP(0.2)', 0.03);
            self::fail('Expected query timeout');
        } catch (TimeoutException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
            self::assertFalse($this->client->mysqlClient()?->isConnected() ?? false);
        }

        self::assertSame([['reconnected' => 1]], $this->client->rawQuery('SELECT 1 AS reconnected'));
    }
}
