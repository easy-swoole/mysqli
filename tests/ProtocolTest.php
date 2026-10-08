<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\TimeoutException;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

final class ProtocolTest extends TestCase
{
    public function testPerQueryTimeoutClosesProtocolStream(): void
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $port = $listener->getsockname()['port'];

        Coroutine::create(static function () use ($listener): void {
            $peer = $listener->accept(1.0);
            if (!$peer instanceof Socket) {
                $listener->close();
                return;
            }
            self::sendPacket($peer, self::handshake(), 0);
            self::receivePacket($peer);
            self::sendPacket($peer, self::ok(), 2);
            self::receivePacket($peer); // SET NAMES
            self::sendPacket($peer, self::ok(), 1);
            self::receivePacket($peer); // query that deliberately receives no response
            Coroutine::sleep(0.15);
            $peer->close();
            $listener->close();
        });

        $client = new Client(new Config([
            'host' => '127.0.0.1',
            'port' => $port,
            'user' => 'test',
            'timeout' => 0.5,
            'maxConnectTime' => 0.5,
        ]));
        $startedAt = microtime(true);
        try {
            $client->rawQuery('SELECT SLEEP(1)', 0.03);
            self::fail('Expected query timeout');
        } catch (TimeoutException $error) {
            self::assertStringContainsString('query timed out', $error->getMessage());
            self::assertLessThan(0.15, microtime(true) - $startedAt);
            self::assertFalse($client->mysqlClient()?->isConnected() ?? false);
        }
    }

    public function testTextAndPreparedBinaryProtocol(): void
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $port = $listener->getsockname()['port'];
        $completed = new Channel(1);

        Coroutine::create(static function () use ($listener, $completed): void {
            try {
                $peer = $listener->accept(1.0);
                if (!$peer instanceof Socket) {
                    throw new \RuntimeException('Fake MySQL server did not accept a client');
                }

                self::sendPacket($peer, self::handshake(), 0);
                self::receivePacket($peer);
                self::sendPacket($peer, self::ok(), 2);

                [$sequence, $setNames] = self::receivePacket($peer);
                self::assertSame(0, $sequence);
                self::assertSame("\x03SET NAMES utf8mb4", $setNames);
                self::sendPacket($peer, self::ok(), 1);

                [, $query] = self::receivePacket($peer);
                self::assertSame("\x03SELECT 42 AS answer", $query);
                self::sendResultHeader($peer, 'answer', 3, false);
                self::sendPacket($peer, "\x02" . '42', 4);
                self::sendPacket($peer, self::eof(), 5);

                [, $prepare] = self::receivePacket($peer);
                self::assertSame("\x16SELECT ? AS answer", $prepare);
                self::sendPacket($peer, "\0" . pack('VvvCv', 7, 1, 1, 0, 0), 1);
                self::sendPacket($peer, self::column('parameter', 3), 2);
                self::sendPacket($peer, self::eof(), 3);
                self::sendPacket($peer, self::column('answer', 8), 4);
                self::sendPacket($peer, self::eof(), 5);

                [, $execute] = self::receivePacket($peer);
                self::assertSame(0x17, ord($execute[0]));
                self::assertSame(7, unpack('V', substr($execute, 1, 4))[1]);
                self::sendResultHeader($peer, 'answer', 8, true);
                self::sendPacket($peer, "\0\0" . pack('P', 42), 4);
                self::sendPacket($peer, self::eof(), 5);

                [, $close] = self::receivePacket($peer);
                self::assertSame(0x19, ord($close[0]));
                $peer->close();
                $listener->close();
                $completed->push(true);
            } catch (\Throwable $error) {
                $listener->close();
                $completed->push($error);
            }
        });

        $client = new Client(new Config([
            'host' => '127.0.0.1',
            'port' => $port,
            'user' => 'test',
            'charset' => 'utf8mb4',
            'timeout' => 0.5,
            'maxConnectTime' => 0.5,
        ]));
        self::assertSame([['answer' => 42]], $client->rawQuery('SELECT 42 AS answer'));
        $statement = $client->prepare('SELECT ? AS answer');
        self::assertSame([['answer' => 42]], $statement->execute([42]));
        $statement->close();

        $serverResult = $completed->pop(1.0);
        if ($serverResult instanceof \Throwable) {
            throw $serverResult;
        }
        self::assertTrue($serverResult);
        $client->close();
    }

    public function testCompressedProtocolNegotiationAndBundledResponse(): void
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $port = $listener->getsockname()['port'];
        $completed = new Channel(1);

        Coroutine::create(static function () use ($listener, $completed): void {
            try {
                $peer = $listener->accept(1.0);
                if (!$peer instanceof Socket) {
                    throw new \RuntimeException('Fake MySQL server did not accept a client');
                }
                self::sendPacket($peer, self::handshake(true), 0);
                [, $authentication] = self::receivePacket($peer);
                self::assertNotSame(0, unpack('V', substr($authentication, 0, 4))[1] & 0x20);
                self::sendPacket($peer, self::ok(), 2);

                [$compressedSequence, $setNames] = self::receiveCompressed($peer);
                self::assertSame(0, $compressedSequence);
                self::assertSame("\x03SET NAMES utf8mb4", self::classicPayload($setNames, 0));
                self::sendCompressed($peer, self::classicPacket(self::ok(), 1), 1);

                [$compressedSequence, $query] = self::receiveCompressed($peer);
                self::assertSame(0, $compressedSequence);
                self::assertSame("\x03SELECT REPEAT('z', 4096) AS payload", self::classicPayload($query, 0));
                $response = self::classicPacket("\x01", 1)
                    . self::classicPacket(self::column('payload', 253), 2)
                    . self::classicPacket(self::eof(), 3)
                    . self::classicPacket("\xfc\x00\x10" . str_repeat('z', 4096), 4)
                    . self::classicPacket(self::eof(), 5);
                self::sendCompressed($peer, $response, 1);
                $completed->push(true);
                $peer->close();
                $listener->close();
            } catch (\Throwable $error) {
                $completed->push($error);
                $listener->close();
            }
        });

        $client = new Client(new Config([
            'host' => '127.0.0.1',
            'port' => $port,
            'user' => 'test',
            'compress' => true,
            'timeout' => 0.5,
            'maxConnectTime' => 0.5,
        ]));
        $rows = $client->rawQuery("SELECT REPEAT('z', 4096) AS payload");
        self::assertTrue($client->mysqlClient()?->isCompressionEnabled());
        self::assertSame(str_repeat('z', 4096), $rows[0]['payload']);
        $serverResult = $completed->pop(1.0);
        if ($serverResult instanceof \Throwable) {
            throw $serverResult;
        }
        self::assertTrue($serverResult);
    }

    private static function handshake(bool $compress = false): string
    {
        $capabilities = 0x00000001 | 0x00000004 | 0x00000200 | 0x00002000 | 0x00008000 | 0x00080000;
        if ($compress) {
            $capabilities |= 0x20;
        }
        return "\x0a8.0.0-test\0"
            . pack('V', 1)
            . '12345678' . "\0"
            . pack('v', $capabilities & 0xffff)
            . "\x2d"
            . pack('v', 2)
            . pack('v', $capabilities >> 16)
            . "\x15"
            . str_repeat("\0", 10)
            . 'abcdefghijkl' . "\0"
            . "mysql_native_password\0";
    }

    private static function ok(): string
    {
        return "\0\0\0\x02\0\0\0";
    }

    private static function eof(): string
    {
        return "\xfe\0\0\x02\0";
    }

    private static function column(string $name, int $type): string
    {
        $strings = ['def', '', '', '', $name, $name];
        $packet = '';
        foreach ($strings as $value) {
            $packet .= chr(strlen($value)) . $value;
        }
        return $packet . "\x0c" . pack('vV', 33, 255) . chr($type) . pack('vC', 0, 0) . "\0\0";
    }

    private static function sendResultHeader(Socket $peer, string $name, int $type, bool $binary): void
    {
        self::sendPacket($peer, "\x01", 1);
        self::sendPacket($peer, self::column($name, $type), 2);
        self::sendPacket($peer, self::eof(), 3);
    }

    private static function sendPacket(Socket $peer, string $payload, int $sequence): void
    {
        $header = substr(pack('V', strlen($payload)), 0, 3) . chr($sequence);
        $sent = $peer->sendAll($header . $payload, 1.0);
        if ($sent !== strlen($header . $payload)) {
            throw new \RuntimeException('Fake MySQL server could not send a packet');
        }
    }

    private static function receivePacket(Socket $peer): array
    {
        $header = $peer->recvAll(4, 1.0);
        if (!is_string($header) || strlen($header) !== 4) {
            throw new \RuntimeException('Fake MySQL server could not read a packet header');
        }
        $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $payload = $peer->recvAll($length, 1.0);
        if (!is_string($payload) || strlen($payload) !== $length) {
            throw new \RuntimeException('Fake MySQL server could not read a packet payload');
        }
        return [ord($header[3]), $payload];
    }

    private static function classicPacket(string $payload, int $sequence): string
    {
        return substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;
    }

    private static function classicPayload(string $packet, int $sequence): string
    {
        self::assertSame($sequence, ord($packet[3]));
        $length = ord($packet[0]) | (ord($packet[1]) << 8) | (ord($packet[2]) << 16);
        return substr($packet, 4, $length);
    }

    private static function receiveCompressed(Socket $peer): array
    {
        $header = $peer->recvAll(7, 1.0);
        if (!is_string($header) || strlen($header) !== 7) {
            throw new \RuntimeException('Fake MySQL server could not read a compressed header');
        }
        $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $uncompressedLength = ord($header[4]) | (ord($header[5]) << 8) | (ord($header[6]) << 16);
        $payload = $peer->recvAll($length, 1.0);
        if (!is_string($payload) || strlen($payload) !== $length) {
            throw new \RuntimeException('Fake MySQL server could not read a compressed payload');
        }
        if ($uncompressedLength !== 0) {
            $payload = gzuncompress($payload);
            if (!is_string($payload) || strlen($payload) !== $uncompressedLength) {
                throw new \RuntimeException('Fake MySQL server received invalid compressed data');
            }
        }
        return [ord($header[3]), $payload];
    }

    private static function sendCompressed(Socket $peer, string $data, int $sequence): void
    {
        $compressed = gzcompress($data);
        if (!is_string($compressed)) {
            throw new \RuntimeException('Could not compress fake server packet');
        }
        $header = substr(pack('V', strlen($compressed)), 0, 3)
            . chr($sequence)
            . substr(pack('V', strlen($data)), 0, 3);
        $sent = $peer->sendAll($header . $compressed, 1.0);
        if ($sent !== strlen($header . $compressed)) {
            throw new \RuntimeException('Fake MySQL server could not send compressed data');
        }
    }
}
