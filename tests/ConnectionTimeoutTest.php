<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class ConnectionTimeoutTest extends TestCase
{
    public function testHandshakeTimeoutIsReportedAndBounded(): void
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $port = $listener->getsockname()['port'];

        Coroutine::create(static function () use ($listener): void {
            $peer = $listener->accept(1.0);
            if ($peer instanceof Socket) {
                Coroutine::sleep(0.3);
                $peer->close();
            }
            $listener->close();
        });

        $client = new Client(new Config([
            'host' => '127.0.0.1',
            'port' => $port,
            'maxConnectTime' => 0.05,
            'timeout' => 0.05,
        ]));
        $startedAt = microtime(true);
        try {
            $client->connect();
            self::fail('Expected the stalled MySQL handshake to time out');
        } catch (TimeoutException $error) {
            self::assertStringContainsString('handshake timed out', $error->getMessage());
            self::assertLessThan(0.25, microtime(true) - $startedAt);
            self::assertNull($client->mysqlClient());
        }
    }

    #[DataProvider('connectionTimeoutBudgets')]
    public function testExplicitConnectionTimeoutHonorsBothBudgets(?float $timeout, float $maxConnectTime): void
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $completed = new Channel(1);
        Coroutine::create(static function () use ($listener, $completed): void {
            try {
                $peer = $listener->accept(1.0);
                if (!$peer instanceof Socket) {
                    throw new \RuntimeException('Fake server did not accept the connection');
                }
                // No handshake: the client must disconnect when its budget expires.
                $completed->push($peer->recvAll(1, 1.0));
                $peer->close();
            } finally {
                $listener->close();
            }
        });
        $config = new Config(['host' => '127.0.0.1', 'port' => $listener->getsockname()['port'],
            'maxConnectTime' => $maxConnectTime, 'timeout' => 0.8]);
        $client = new Client($config);
        $started = microtime(true);
        try {
            $client->connect($timeout);
            self::fail('Expected connection timeout');
        } catch (TimeoutException $error) {
            self::assertStringContainsString('handshake timed out', $error->getMessage());
            self::assertGreaterThanOrEqual(0.02, microtime(true) - $started);
            self::assertLessThan(0.2, microtime(true) - $started);
            self::assertNull($client->mysqlClient());
        }
        self::assertSame('', $completed->pop(1.0));
        self::assertSame($maxConnectTime, $config->getMaxConnectTime());
        self::assertSame(0.8, $config->getTimeout());
    }

    public static function connectionTimeoutBudgets(): array
    {
        return [
            'explicit timeout is shorter' => [0.03, 0.5],
            'configuration caps longer explicit timeout' => [0.5, 0.03],
            'null uses configuration' => [null, 0.03],
        ];
    }

    #[DataProvider('invalidConnectionTimeouts')]
    public function testInvalidExplicitConnectionTimeoutDoesNotOpenConnection(float $timeout): void
    {
        $client = new Client(new Config());
        try {
            $client->connect($timeout);
            self::fail('Expected invalid timeout to be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('greater than zero', $error->getMessage());
            self::assertNull($client->mysqlClient());
        }
    }

    public static function invalidConnectionTimeouts(): array
    {
        return ['zero' => [0.0], 'negative' => [-0.1]];
    }

}
