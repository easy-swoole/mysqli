<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testFastDbStyleConfigurationIsAccepted(): void
    {
        $config = new Config([
            'host' => 'db',
            'port' => 3307,
            'user' => 'test',
            'password' => 'secret',
            'database' => 'test',
            'timeout' => 0.25,
            'charset' => 'utf8mb4',
            'autoPing' => 5,
            'useMysqli' => false,
            'compress' => true,
        ]);

        self::assertSame('db', $config->getHost());
        self::assertSame(3307, $config->getPort());
        self::assertSame(0.25, $config->getTimeout());
        self::assertSame(0.25, $config->getMaxConnectTime());
        self::assertTrue($config->isCompress());
        self::assertTrue($config->toArray()['compress']);
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(['timeout' => 0]);
    }
}
