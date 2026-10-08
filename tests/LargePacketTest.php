<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\TestCase;

final class LargePacketTest extends TestCase
{
    private const MYSQL_PACKET_MAX_PAYLOAD = 0xffffff;
    private const TEST_PAYLOAD_SIZE = self::MYSQL_PACKET_MAX_PAYLOAD + 65537;

    private ?Client $client = null;
    private string $table;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set FAST_DB_TEST_* or MYSQLI_TEST_* to the EasySwoole FastDb test database.');
        }
        $config = MYSQL_CONFIG;
        $config['timeout'] = 20.0;
        $config['maxConnectTime'] = 5.0;
        $this->client = new Client(new Config($config));

        $server = $this->client->rawQuery('SELECT @@max_allowed_packet AS max_allowed_packet')[0];
        if ($server['max_allowed_packet'] <= self::TEST_PAYLOAD_SIZE + 1024) {
            $this->markTestSkipped('The server max_allowed_packet is too small for a multi-packet test.');
        }

        $this->table = 'mysqli_large_packet_' . bin2hex(random_bytes(6));
        $this->client->rawQuery("CREATE TABLE `{$this->table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payload LONGBLOB NOT NULL
        ) ENGINE=InnoDB");
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            try {
                if (isset($this->table)) {
                    $this->client->rawQuery("DROP TABLE IF EXISTS `{$this->table}`");
                }
            } finally {
                $this->client->close();
                $this->client = null;
            }
        }
    }

    public function testMultiPacketInsertReadAndUpdate(): void
    {
        $insertPayload = $this->payload("\0\xffINSERT-协程-0123456789");
        self::assertGreaterThan(self::MYSQL_PACKET_MAX_PAYLOAD, strlen($insertPayload));

        $insert = $this->client->prepare("INSERT INTO `{$this->table}` (payload) VALUES (?)", 20.0);
        try {
            self::assertTrue($insert->execute([$insertPayload], 20.0));
        } finally {
            $insert->close();
        }
        self::assertSame(1, $this->client->getLastInsertId());
        self::assertSame(1, $this->client->getLastAffectRows());

        $textRows = $this->client->rawQuery("SELECT payload FROM `{$this->table}` WHERE id = 1", 20.0);
        self::assertSame(self::TEST_PAYLOAD_SIZE, strlen($textRows[0]['payload']));
        self::assertSame(hash('sha256', $insertPayload), hash('sha256', $textRows[0]['payload']));
        unset($textRows, $insertPayload);

        $updatePayload = $this->payload("\xff\0UPDATE-数据包-9876543210");
        $update = $this->client->prepare("UPDATE `{$this->table}` SET payload = ? WHERE id = ?", 20.0);
        try {
            self::assertTrue($update->execute([$updatePayload, 1], 20.0));
        } finally {
            $update->close();
        }
        self::assertSame(1, $this->client->getLastAffectRows());
        self::assertSame(0, $this->client->getLastInsertId());

        $select = $this->client->prepare("SELECT payload FROM `{$this->table}` WHERE id = ?", 20.0);
        try {
            $binaryRows = $select->execute([1], 20.0);
        } finally {
            $select->close();
        }
        self::assertSame(self::TEST_PAYLOAD_SIZE, strlen($binaryRows[0]['payload']));
        self::assertSame(hash('sha256', $updatePayload), hash('sha256', $binaryRows[0]['payload']));
        unset($binaryRows);

        $serverCheck = $this->client->rawQuery("SELECT OCTET_LENGTH(payload) AS bytes, SHA2(payload, 256) AS checksum FROM `{$this->table}` WHERE id = 1");
        self::assertSame(self::TEST_PAYLOAD_SIZE, $serverCheck[0]['bytes']);
        self::assertSame(hash('sha256', $updatePayload), strtolower($serverCheck[0]['checksum']));
        self::assertTrue($this->client->ping());
    }

    private function payload(string $seed): string
    {
        return substr(str_repeat($seed, (int) ceil(self::TEST_PAYLOAD_SIZE / strlen($seed))), 0, self::TEST_PAYLOAD_SIZE);
    }
}
