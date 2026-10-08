<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection;
use EasySwoole\Mysqli\Tests\Support\FastDbStyleConnection;
use PHPUnit\Framework\TestCase;

final class FastDbApiCompatibilityTest extends TestCase
{
    public function testFastDbStyleConnectionCanExtendClient(): void
    {
        $connection = new FastDbStyleConnection(new Config());
        self::assertInstanceOf(Client::class, $connection);

        $query = new \ReflectionMethod($connection, 'query');
        $rawQuery = new \ReflectionMethod($connection, 'rawQuery');
        self::assertSame(1, $query->getNumberOfParameters());
        self::assertSame(1, $rawQuery->getNumberOfParameters());
        self::assertFalse($query->hasReturnType());
        self::assertFalse($rawQuery->hasReturnType());
    }

    public function testMysqlClientReturnTypeRetainsMysqliMockCompatibility(): void
    {
        $type = (string) (new \ReflectionMethod(Client::class, 'mysqlClient'))->getReturnType();
        self::assertStringContainsString(Connection::class, $type);
        self::assertStringContainsString('mysqli', $type);
        self::assertStringContainsString('null', $type);
    }

    public function testFastDbConfigurationExtrasRemainIgnored(): void
    {
        $config = new Config([
            'host' => '127.0.0.1',
            'timeout' => 2,
            'autoPing' => 5,
            'useMysqli' => false,
            'maxObjectNum' => 20,
        ]);
        self::assertSame('127.0.0.1', $config->getHost());
        self::assertSame(2.0, $config->getTimeout());
        self::assertSame(3.0, $config->getMaxConnectTime());
        self::assertArrayNotHasKey('autoPing', $config->toArray());
        self::assertArrayNotHasKey('useMysqli', $config->toArray());
        self::assertArrayNotHasKey('maxObjectNum', $config->toArray());
    }
}
