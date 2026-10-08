<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\TestCase;

final class CompressionTest extends TestCase
{
    private ?Client $client = null;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            self::markTestSkipped('Set MYSQLI_TEST_* environment variables for integration tests');
        }
        $config = MYSQL_CONFIG;
        $config['compress'] = true;
        $config['timeout'] = 60.0;
        $config['maxConnectTime'] = 10.0;
        $this->client = new Client(new Config($config));
    }

    protected function tearDown(): void
    {
        $this->client?->close();
    }

    public function testCompressedQueriesAndPreparedStatements(): void
    {
        $rows = $this->client->rawQuery("SELECT VERSION() AS version, REPEAT('x', 262144) AS payload");
        self::assertTrue($this->client->mysqlClient()?->isCompressionEnabled());
        self::assertNotEmpty($rows[0]['version']);
        self::assertSame(262144, strlen($rows[0]['payload']));
        self::assertSame(str_repeat('x', 262144), $rows[0]['payload']);

        $statement = $this->client->prepare('SELECT LENGTH(?) AS length, SHA2(?, 256) AS digest');
        $value = str_repeat('prepared-compression-', 20000);
        $result = $statement->execute([$value, $value]);
        self::assertSame(strlen($value), $result[0]['length']);
        self::assertSame(hash('sha256', $value), $result[0]['digest']);
        $statement->close();
    }

    public function testCompressedLargeInsertReadAndUpdateAcrossPacketBoundary(): void
    {
        $table = 'mysqli_compress_' . bin2hex(random_bytes(5));
        $quotedTable = "`{$table}`";
        $this->client->rawQuery("CREATE TABLE {$quotedTable} (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payload LONGBLOB NOT NULL) ENGINE=InnoDB");
        try {
            $first = str_repeat('A', 0xffffff + 1024);
            $insert = $this->client->prepare("INSERT INTO {$quotedTable} (payload) VALUES (?)");
            self::assertTrue($insert->execute([$first]));
            self::assertSame(1, $this->client->getLastAffectRows());
            $id = $this->client->getLastInsertId();
            self::assertGreaterThan(0, $id);
            $insert->close();

            $read = $this->client->prepare("SELECT LENGTH(payload) AS length, SHA2(payload, 256) AS digest FROM {$quotedTable} WHERE id = ?");
            $row = $read->execute([$id])[0];
            self::assertSame(strlen($first), $row['length']);
            self::assertSame(hash('sha256', $first), $row['digest']);
            unset($first, $row);

            $second = str_repeat('B', 0xffffff + 2048);
            $update = $this->client->prepare("UPDATE {$quotedTable} SET payload = ? WHERE id = ?");
            self::assertTrue($update->execute([$second, $id]));
            self::assertSame(1, $this->client->getLastAffectRows());
            $row = $read->execute([$id])[0];
            self::assertSame(strlen($second), $row['length']);
            self::assertSame(hash('sha256', $second), $row['digest']);
            $read->close();
            $update->close();
        } finally {
            try {
                $this->client->rawQuery("DROP TABLE IF EXISTS {$quotedTable}");
            } catch (\Throwable) {
                // Preserve the original protocol or assertion failure.
            }
        }
    }
}
