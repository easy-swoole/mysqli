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

    public function testSniDoesNotTurnCaOnlyVerificationIntoIdentityVerification(): void
    {
        $config = new Config(MYSQL_CONFIG);
        if (!in_array($config->getTlsMode(), [TlsMode::VerifyCA, TlsMode::VerifyIdentity], true)) {
            self::markTestSkipped('Requires a trusted server CA configuration');
        }
        $name = 'sni-' . bin2hex(random_bytes(8)) . '.mysql-test.invalid';
        $options = array_replace(MYSQL_CONFIG, ['tlsMode' => TlsMode::VerifyCA, 'tlsServerName' => $name]);
        $caOnly = new Client(new Config($options));
        try {
            self::assertTrue($caOnly->connect());
            self::assertTrue($caOnly->mysqlClient()->isTlsEnabled());
        } finally { $caOnly->close(); }
        $options['tlsMode'] = TlsMode::VerifyIdentity;
        $identity = new Client(new Config($options));
        try {
            $identity->connect();
            self::fail('Mismatched identity must be rejected even with a trusted CA');
        } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
            self::assertStringContainsString('identity', $error->getMessage());
            self::assertNull($identity->mysqlClient());
        } finally { $identity->close(); }
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
