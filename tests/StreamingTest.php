<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, QueryBuilder};
use EasySwoole\Mysqli\Exception\{Exception, TimeoutException, TransactionLostException};
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\{Channel, Socket};

final class StreamingTest extends TestCase
{
    public function testTextRowsArriveIncrementallyAndConnectionStaysExclusiveUntilEof(): void
    {
        $release = new Channel(1);
        [$client, $done] = $this->server(static function (Socket $peer) use ($release): void {
            self::assertSame("\x03SELECT id FROM records", self::receive($peer));
            self::header($peer);
            self::send($peer, "\x011", 4);
            self::assertTrue($release->pop(1.0));
            self::send($peer, "\x012", 5);
            self::send($peer, self::eof(), 6);
            self::assertSame("\x0e", self::receive($peer));
            self::send($peer, self::ok(), 1);
            self::assertSame("\x01", self::receive($peer));
        });
        $notified = [];
        $client->onQuery(static function ($result, Client $client) use (&$notified): void {
            $notified[] = [$result, $client->getLastAffectRows()];
        });
        $stream = $client->rawIterate('SELECT id FROM records');
        self::assertTrue($client->mysqlClient()->isBusy());
        self::assertNull($client->getLastAffectRows());
        $iterator = $stream->getIterator();
        $iterator->rewind();
        self::assertSame(['id' => 1], $iterator->current());
        foreach ([fn() => $client->rawQuery('SELECT 1'), fn() => $client->prepare('SELECT 1'),
            fn() => $client->close(), fn() => $client->mysqlClient()->query('SELECT 1')] as $operation) {
            try { $operation(); self::fail('An active stream must own its connection'); }
            catch (Exception $error) { self::assertStringContainsString('Concurrent', $error->getMessage()); }
        }
        self::assertSame([], $notified);
        $release->push(true);
        $iterator->next();
        self::assertSame(['id' => 2], $iterator->current());
        $iterator->next();
        self::assertFalse($iterator->valid());
        self::assertTrue($stream->isComplete());
        self::assertFalse($client->mysqlClient()->isBusy());
        self::assertSame([[true, 2]], $notified);
        self::assertSame(2, $client->getLastAffectRows());
        self::assertTrue($client->ping());
        $client->close();
        $this->completed($done);
    }

    public function testForeachBreakClosesConnectionAndPreservesTransactionLostProtection(): void
    {
        [$client, $done] = $this->server(static function (Socket $peer): void {
            self::assertSame("\x03BEGIN", self::receive($peer));
            self::send($peer, self::ok(3), 1);
            self::receive($peer);
            self::header($peer, 3);
            self::send($peer, "\x011", 4);
            self::assertSame('', $peer->recvAll(4, 1.0));
        });
        $client->rawQuery('BEGIN');
        $stream = $client->rawIterate('SELECT id FROM records');
        foreach ($stream as $row) { self::assertSame(['id' => 1], $row); break; }
        self::assertTrue($stream->isClosed());
        self::assertFalse($stream->isComplete());
        self::assertFalse($client->mysqlClient()->isConnected());
        self::assertFalse($client->mysqlClient()->isBusy());
        self::assertTrue($client->mysqlClient()->isTransactionLost());
        self::assertNull($client->getLastAffectRows());
        try { $client->rawQuery('SELECT 1'); self::fail('Lost transaction must prevent reconnect'); }
        catch (TransactionLostException) { self::addToAssertionCount(1); }
        $client->close();
        $this->completed($done);
    }

    public function testDiscardedUnusedStreamClosesItsConnection(): void
    {
        [$client, $done] = $this->server(static function (Socket $peer): void {
            self::receive($peer);
            self::header($peer);
            self::assertSame('', $peer->recvAll(4, 1.0));
        });
        $stream = $client->rawIterate('SELECT id FROM records');
        unset($stream);
        self::assertFalse($client->mysqlClient()->isBusy());
        self::assertFalse($client->mysqlClient()->isConnected());
        $client->close();
        $this->completed($done);
    }

    public function testConsumerTimeIsIncludedInStreamDeadline(): void
    {
        [$client, $done] = $this->server(static function (Socket $peer): void {
            self::receive($peer);
            self::header($peer);
            self::send($peer, "\x011", 4);
            self::send($peer, "\x012", 5);
            self::send($peer, self::eof(), 6);
            self::assertSame('', $peer->recvAll(4, 1.0));
        });
        $stream = $client->rawIterate('SELECT id FROM records', 0.03);
        try {
            foreach ($stream as $row) { Coroutine::sleep(0.06); }
            self::fail('Expected shared stream deadline to expire');
        } catch (TimeoutException) { self::addToAssertionCount(1); }
        self::assertTrue($stream->isClosed());
        self::assertFalse($stream->isComplete());
        self::assertFalse($client->mysqlClient()->isConnected());
        self::assertFalse($client->mysqlClient()->isBusy());
        self::assertNull($client->getLastAffectRows());
        $client->close();
        $this->completed($done);
    }

    public function testBinaryStreamKeepsManualStatementReusable(): void
    {
        [$client, $done] = $this->server(static function (Socket $peer): void {
            self::prepare($peer);
            self::assertSame(0x17, ord(self::receive($peer)[0]));
            self::binaryRows($peer, [4, 8]);
            self::assertSame(0x17, ord(self::receive($peer)[0]));
            self::binaryRows($peer, [12]);
            self::assertSame("\x19" . pack('V', 7), self::receive($peer));
            self::assertSame("\x01", self::receive($peer));
        });
        $statement = $client->prepare('SELECT ? AS id');
        $stream = $statement->iterate([4]);
        try { $statement->close(); self::fail('Cannot close a statement while its stream is active'); }
        catch (Exception $error) { self::assertStringContainsString('Concurrent', $error->getMessage()); }
        self::assertSame([['id' => 4], ['id' => 8]], iterator_to_array($stream));
        self::assertSame(2, $client->getLastAffectRows());
        self::assertSame([['id' => 12]], $statement->execute([12]));
        $statement->close();
        try { $statement->iterate([1]); self::fail('Closed statement must reject iterate'); }
        catch (\LogicException) { self::addToAssertionCount(1); }
        $client->close();
        $this->completed($done);
    }

    public function testBuilderStreamOwnsAndClosesStatementAfterEof(): void
    {
        [$client, $done] = $this->server(static function (Socket $peer): void {
            self::prepare($peer);
            self::assertSame(0x17, ord(self::receive($peer)[0]));
            self::binaryRows($peer, [7, 9]);
            self::assertSame("\x19" . pack('V', 7), self::receive($peer));
            self::assertSame("\x0e", self::receive($peer));
            self::send($peer, self::ok(), 1);
            self::assertSame("\x01", self::receive($peer));
        });
        $calls = 0;
        $client->onQuery(static function ($result, Client $client) use (&$calls): void {
            self::assertTrue($result);
            self::assertSame(2, $client->getLastAffectRows());
            $calls++;
        });
        $stream = $client->iterate((new QueryBuilder())->raw('SELECT ? AS id', [7]));
        self::assertSame([['id' => 7], ['id' => 9]], iterator_to_array($stream));
        self::assertSame(1, $calls);
        self::assertTrue($client->ping());
        $client->close();
        $this->completed($done);
    }

    public function testStreamErrorAfterSomeRowsClosesConnectionAndPreservesServerCode(): void
    {
        [$client, $done] = $this->server(static function (Socket $peer): void {
            self::receive($peer);
            self::header($peer);
            self::send($peer, "\x011", 4);
            self::send($peer, "\xff" . pack('v', 1064) . '#42000broken query', 5);
            self::assertSame('', $peer->recvAll(4, 1.0));
        });
        $stream = $client->rawIterate('SELECT id FROM records');
        try { iterator_to_array($stream); self::fail('Expected server failure'); }
        catch (Exception $error) { self::assertSame(1064, $error->getCode()); }
        self::assertSame(1, $stream->getRowsRead());
        self::assertFalse($client->mysqlClient()->isBusy());
        self::assertFalse($client->mysqlClient()->isConnected());
        self::assertNull($client->getLastAffectRows());
        $client->close();
        $this->completed($done);
    }

    private function server(callable $serve): array
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $done = new Channel(1);
        Coroutine::create(static function () use ($listener, $serve, $done): void {
            $peer = null;
            try {
                $peer = $listener->accept(1.0);
                self::send($peer, self::handshake(), 0);
                self::receive($peer);
                self::send($peer, self::ok(), 2);
                self::assertSame("\x03SET NAMES utf8mb4", self::receive($peer));
                self::send($peer, self::ok(), 1);
                $serve($peer);
                $done->push(true);
            } catch (\Throwable $error) { $done->push($error); }
            finally { $peer?->close(); $listener->close(); }
        });
        return [new Client(new Config(['host' => '127.0.0.1', 'port' => $listener->getsockname()['port'],
            'user' => 'test', 'timeout' => 0.5, 'maxConnectTime' => 0.5])), $done];
    }

    private function completed(Channel $done): void
    {
        $result = $done->pop(1.0);
        if ($result instanceof \Throwable) { throw $result; }
        self::assertTrue($result);
    }

    private static function prepare(Socket $peer): void
    {
        self::assertSame("\x16SELECT ? AS id", self::receive($peer));
        self::send($peer, "\0" . pack('VvvCv', 7, 1, 1, 0, 0), 1);
        self::send($peer, self::column(), 2);
        self::send($peer, self::eof(), 3);
        self::send($peer, self::column(), 4);
        self::send($peer, self::eof(), 5);
    }

    private static function header(Socket $peer, int $status = 2): void
    {
        self::send($peer, "\x01", 1);
        self::send($peer, self::column(), 2);
        self::send($peer, self::eof($status), 3);
    }

    private static function binaryRows(Socket $peer, array $ids): void
    {
        self::header($peer);
        $sequence = 4;
        foreach ($ids as $id) { self::send($peer, "\0\0" . pack('P', $id), $sequence++); }
        self::send($peer, self::eof(), $sequence);
    }

    private static function handshake(): string
    {
        $capabilities = 1 | 4 | 0x200 | 0x2000 | 0x8000 | 0x80000;
        return "\x0a8.4.0-stream-test\0" . pack('V', 1) . "12345678\0"
            . pack('v', $capabilities & 0xffff) . "\x2d" . pack('vv', 2, $capabilities >> 16)
            . "\x15" . str_repeat("\0", 10) . "abcdefghijkl\0mysql_native_password\0";
    }

    private static function column(): string
    {
        $packet = '';
        foreach (['def', '', '', '', 'id', 'id'] as $value) { $packet .= chr(strlen($value)) . $value; }
        return $packet . "\x0c" . pack('vV', 33, 255) . "\x08" . pack('vC', 0, 0) . "\0\0";
    }

    private static function ok(int $status = 2): string { return "\0\0\0" . pack('v', $status) . "\0\0"; }
    private static function eof(int $status = 2): string { return "\xfe\0\0" . pack('v', $status); }

    private static function send(Socket $peer, string $payload, int $sequence): void
    {
        $packet = substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;
        if ($peer->sendAll($packet, 1.0) !== strlen($packet)) { throw new \RuntimeException('Fake server send failed'); }
    }

    private static function receive(Socket $peer): string
    {
        $header = $peer->recvAll(4, 1.0);
        if (!is_string($header) || strlen($header) !== 4) { throw new \RuntimeException('Fake server read header failed'); }
        $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $payload = $peer->recvAll($length, 1.0);
        if (!is_string($payload) || strlen($payload) !== $length) { throw new \RuntimeException('Fake server read payload failed'); }
        return $payload;
    }
}
