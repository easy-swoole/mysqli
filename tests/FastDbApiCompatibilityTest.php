<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Protocol\Connection;
use EasySwoole\Mysqli\Tests\Support\FastDbStyleConnection;
use PHPUnit\Framework\TestCase;

final class FastDbApiCompatibilityTest extends TestCase
{
    public function testFastDbStyleConnectionCanExtendClient(): void
    {
        $connection = new FastDbStyleConnection(new Config());
        self::assertInstanceOf(Client::class, $connection);

        $connect = new \ReflectionMethod($connection, 'connect');
        self::assertSame(1, $connect->getNumberOfParameters());
        self::assertSame(0, $connect->getNumberOfRequiredParameters());
        $timeout = $connect->getParameters()[0];
        self::assertSame('timeout', $timeout->getName());
        self::assertSame('?float', (string) $timeout->getType());
        self::assertNull($timeout->getDefaultValue());
        self::assertSame('bool', (string) $connect->getReturnType());

        foreach ([Client::class, FastDbStyleConnection::class] as $class) {
            foreach (['query', 'rawQuery'] as $method) {
                $reflection = new \ReflectionMethod($class, $method);
                self::assertSame(2, $reflection->getNumberOfParameters());
                self::assertSame(1, $reflection->getNumberOfRequiredParameters());
                $parameters = $reflection->getParameters();
                self::assertSame($method === 'query' ? 'builder' : 'query', $parameters[0]->getName());
                self::assertSame($method === 'query' ? QueryBuilder::class : 'string', (string) $parameters[0]->getType());
                self::assertSame('timeout', $parameters[1]->getName());
                self::assertSame('?float', (string) $parameters[1]->getType());
                self::assertNull($parameters[1]->getDefaultValue());
                if ($method === 'query') {
                    $returnType = $reflection->getReturnType();
                    self::assertInstanceOf(\ReflectionUnionType::class, $returnType);
                    $types = array_map(static fn($type): string => $type->getName(), $returnType->getTypes());
                    sort($types);
                    self::assertSame(['array', 'bool'], $types);
                } else {
                    self::assertFalse($reflection->hasReturnType());
                }
            }
        }
    }

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
