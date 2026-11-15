<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection;
use PHPUnit\Framework\TestCase;

final class FastDbApiCompatibilityTest extends TestCase
{
    public function testMysqlClientReturnTypeIsNullableProtocolConnection(): void
    {
        $type = (new \ReflectionMethod(Client::class, 'mysqlClient'))->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(Connection::class, $type->getName());
        self::assertTrue($type->allowsNull());
        self::assertNull((new Client(new Config()))->mysqlClient());
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
        self::assertSame(1.0, $config->getMaxConnectTime());
        self::assertArrayNotHasKey('autoPing', $config->toArray());
        self::assertArrayNotHasKey('useMysqli', $config->toArray());
        self::assertArrayNotHasKey('maxObjectNum', $config->toArray());
    }
}
