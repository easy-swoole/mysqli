<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;
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
}
