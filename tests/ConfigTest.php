<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testSplBeanPropertiesAndSerialization(): void
    {
        $config = new Config(['host' => 'db', 'compress' => true]);
        self::assertInstanceOf(\EasySwoole\Spl\SplBean::class, $config);
        self::assertSame('db', $config->getProperty('host'));
        self::assertSame(3.0, $config->getProperty('timeout'));
        self::assertContains('compress', $config->allProperty());
        self::assertSame($config->toArray(), $config->jsonSerialize());
        self::assertSame(json_encode($config->toArray()), (string) $config);
        self::assertArrayNotHasKey('password', $config->toArray(\EasySwoole\Spl\SplBean::FILTER_NOT_EMPTY));
        self::assertSame(['compress' => true], $config->toArray(static fn($value) => $value === true));
        self::assertSame((new Config())->toArray(), (new Config(null))->toArray());
    }

    public function testRestoreAssignsKnownPropertiesAndPreservesOtherValues(): void
    {
        $config = new Config(['maxConnectTime' => 0.75, 'port' => 3307]);
        self::assertSame($config, $config->restore(['host' => 'db', 'timeout' => 0.125, 'compress' => true, 'autoPing' => 5]));
        self::assertSame('db', $config->getHost());
        self::assertSame(0.125, $config->getTimeout());
        self::assertSame(0.75, $config->getMaxConnectTime());
        self::assertSame(3307, $config->getPort());
        self::assertTrue($config->isCompress());
        self::assertArrayNotHasKey('autoPing', $config->toArray());
        self::assertNull($config->getProperty('autoPing'));
        self::assertSame($config, $config->restore());
        self::assertSame(0.125, $config->getTimeout());
    }

    public function testTimeoutsAreIndependentAndLegacyAliasIsIgnored(): void
    {
        $config = new Config(['timeout' => 0.25, 'maxConnectTim' => 0.5]);
        self::assertSame(0.25, $config->getTimeout());
        self::assertSame(1.0, $config->getMaxConnectTime());
        self::assertArrayNotHasKey('maxConnectTim', $config->toArray());

        $config = new Config(['maxConnectTime' => 0.75]);
        self::assertSame(3.0, $config->getTimeout());
        self::assertSame(0.75, $config->getMaxConnectTime());
        $config->restore(['timeout' => 0.125, 'maxConnectTim' => 0.5]);
        self::assertSame(0.125, $config->getTimeout());
        self::assertSame(0.75, $config->getMaxConnectTime());
        $config->setMaxConnectTime(1.5);
        self::assertSame(0.125, $config->getTimeout());
        self::assertSame(1.5, $config->getMaxConnectTime());
    }

    #[DataProvider('timeoutValues')]
    public function testTimeoutValuesAreStoredWithoutValidation(float $timeout): void
    {
        $config = new Config(['timeout' => $timeout, 'maxConnectTime' => $timeout]);
        self::assertSame($timeout, $config->getTimeout());
        self::assertSame($timeout, $config->getMaxConnectTime());

        $config = new Config();
        $config->restore(['timeout' => $timeout, 'maxConnectTime' => $timeout]);
        self::assertSame($timeout, $config->getTimeout());
        self::assertSame($timeout, $config->getMaxConnectTime());

        $config = new Config();
        $config->setTimeout($timeout);
        $config->setMaxConnectTime($timeout);
        self::assertSame($timeout, $config->getTimeout());
        self::assertSame($timeout, $config->getMaxConnectTime());
    }

    public static function timeoutValues(): array
    {
        return ['zero' => [0.0], 'negative' => [-0.5], 'fractional' => [0.125]];
    }

    public function testSubclassPropertiesRemainSupported(): void
    {
        $config = new class(['host' => 'db', 'customOption' => 42]) extends Config {
            protected int $customOption = 7;
        };
        self::assertSame(42, $config->getProperty('customOption'));
        self::assertSame(42, $config->toArray()['customOption']);
        self::assertSame('db', $config->getHost());
    }

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
        self::assertSame(1.0, $config->getMaxConnectTime());
        self::assertTrue($config->isCompress());
        self::assertTrue($config->toArray()['compress']);
    }

    public function testDefaultValues(): void
    {
        self::assertSame([
            'host' => '127.0.0.1',
            'user' => '',
            'password' => '',
            'database' => '',
            'port' => 3306,
            'timeout' => 3.0,
            'charset' => 'utf8mb4',
            'maxConnectTime' => 1.0,
            'compress' => false,
        ], (new Config())->toArray());
    }

    public function testAllConfigurationGettersAndSetters(): void
    {
        $config = new Config();
        $config->setHost('db');
        $config->setUser('test');
        $config->setPassword('secret');
        $config->setDatabase('demo');
        $config->setPort(3307);
        $config->setTimeout(0.25);
        $config->setCharset('utf8');
        $config->setMaxConnectTime(0.75);
        $config->setCompress(true);

        self::assertSame('db', $config->getHost());
        self::assertSame('test', $config->getUser());
        self::assertSame('secret', $config->getPassword());
        self::assertSame('demo', $config->getDatabase());
        self::assertSame(3307, $config->getPort());
        self::assertSame(0.25, $config->getTimeout());
        self::assertSame('utf8', $config->getCharset());
        self::assertSame(0.75, $config->getMaxConnectTime());
        self::assertTrue($config->isCompress());
        $config->setCompress(false);
        self::assertFalse($config->isCompress());
    }
}
