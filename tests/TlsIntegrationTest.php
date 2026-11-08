<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, TlsMode};
use EasySwoole\Mysqli\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;

final class TlsIntegrationTest extends TestCase
{
    private ?Client $client = null;

    protected function setUp(): void
    {
        $config = new Config(MYSQL_CONFIG);
        if (MYSQL_CONFIG['host'] === '' || $config->getTlsMode() === TlsMode::Disabled) {
            self::markTestSkipped('Set MYSQLI_TEST_* and MYSQLI_TEST_TLS_MODE to run live TLS checks');
        }
        $this->client = new Client($config);
    }

    protected function tearDown(): void { $this->client?->close(); }

    public function testNegotiatedTlsAndReconnect(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $status = $this->client->rawQuery("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher', 'Ssl_version')");
            $status = array_column($status, 'Value', 'Variable_name');
            self::assertNotEmpty($status['Ssl_cipher']);
            self::assertContains($status['Ssl_version'], ['TLSv1.2', 'TLSv1.3']);
            self::assertTrue($this->client->mysqlClient()->isTlsEnabled());
            $statement = $this->client->prepare('SELECT ? AS value');
            self::assertSame([['value' => '中文😀']], $statement->execute(['中文😀']));
            $statement->close();
            $connection = $this->client->mysqlClient();
            $this->client->close();
            self::assertFalse($connection->isTlsEnabled());
        }
    }

    public function testTimeoutReconnectKeepsTlsEnabled(): void
    {
        $this->client->connect();
        try {
            $this->client->rawQuery('SELECT SLEEP(0.3)', 0.05);
            self::fail('Expected query timeout');
        } catch (TimeoutException $error) {
            self::assertFalse($this->client->mysqlClient()->isTlsEnabled());
        }
        self::assertSame([['value' => 1]], $this->client->rawQuery('SELECT 1 AS value'));
        self::assertTrue($this->client->mysqlClient()->isTlsEnabled());
    }
}
