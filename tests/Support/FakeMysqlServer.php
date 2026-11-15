<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests\Support;

use EasySwoole\Mysqli\{Client, Config};
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

trait FakeMysqlServer
{
    private function fakeServer(callable $serve, float $connectTimeout = 0.5): array
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $config = new Config(['host' => '127.0.0.1', 'port' => $listener->getsockname()['port'],
            'user' => 'test', 'timeout' => 0.5, 'maxConnectTime' => $connectTimeout]);
        $completed = new Channel(1);
        Coroutine::create(static function () use ($listener, $serve, $completed): void {
            try { $serve($listener); $completed->push(true); }
            catch (\Throwable $error) { $completed->push($error); }
            finally { $listener->close(); }
        });
        return [new Client($config), $completed, $config];
    }

    private static function acceptSession(Socket $peer, bool $compress = false): void
    {
        self::sendPacket($peer, self::handshake($compress), 0);
        self::receivePacket($peer);
        self::sendPacket($peer, self::ok(), 2);
        if ($compress) {
            self::receiveCompressed($peer);
            self::sendCompressed($peer, self::classicPacket(self::ok(), 1), 1);
            return;
        }
        self::receivePacket($peer);
        self::sendPacket($peer, self::ok(), 1);
    }

    private function assertServerCompleted(Channel $completed): void
    {
        $result = $completed->pop(1.0);
        if ($result instanceof \Throwable) { throw $result; }
        self::assertTrue($result);
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

    private static function ok(int $status = 2): string
    {
        return "\0\0\0" . pack('v', $status) . "\0\0";
    }

    private static function eof(int $status = 2): string
    {
        return "\xfe\0\0" . pack('v', $status);
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

    private static function sendPreparedMetadata(Socket $peer, int $id, int $parameters, string $column, int $type): void
    {
        self::sendPacket($peer, "\0" . pack('VvvCv', $id, 1, $parameters, 0, 0), 1);
        $sequence = 2;
        for ($i = 0; $i < $parameters; $i++) {
            self::sendPacket($peer, self::column('parameter', 8), $sequence++);
        }
        if ($parameters > 0) {
            self::sendPacket($peer, self::eof(), $sequence++);
        }
        self::sendPacket($peer, self::column($column, $type), $sequence++);
        self::sendPacket($peer, self::eof(), $sequence);
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
