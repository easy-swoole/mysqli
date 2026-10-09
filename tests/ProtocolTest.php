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

    public function testStatementsExpireAcrossReconnectWithoutClosingReusedIds(): void
    {
        [$client, $completed, $config] = $this->fakeServer(static function (Socket $listener): void {
            for ($session = 0; $session < 2; $session++) {
                $peer = $listener->accept(1.0);
                self::acceptSession($peer);
                [, $prepare] = self::receivePacket($peer);
                self::assertSame("\x16SELECT 1", $prepare);
                self::sendPacket($peer, "\0" . pack('VvvCv', 7, 0, 0, 0, 0), 1);
                [, $command] = self::receivePacket($peer);
                if ($session === 0) {
                    self::assertSame("\x01", $command);
                } else {
                    // Neither execute nor close from the old Statement may reach this session.
                    self::assertSame(0x17, ord($command[0]));
                    self::sendPacket($peer, self::ok(), 1);
                    [, $command] = self::receivePacket($peer);
                    self::assertSame("\x19" . pack('V', 7), $command);
                }
                $peer->close();
            }
        });
        $connection = new \EasySwoole\Mysqli\Protocol\Connection($config);
        $old = $connection->prepare('SELECT 1');
        $connection->close();
        try {
            $old->execute();
            self::fail('Disconnected statements must expire without reconnecting');
        } catch (\LogicException $error) {
            self::assertStringContainsString('expired', $error->getMessage());
        }
        $new = $connection->prepare('SELECT 1');
        try {
            $old->execute();
            self::fail('Statements from the old generation must expire');
        } catch (\LogicException $error) {
            self::assertStringContainsString('expired', $error->getMessage());
        }
        $old->close();
        self::assertTrue($new->execute());
        $new->close();
        $this->assertServerCompleted($completed);
        $connection->close();
    }

    public function testConcurrentCloseAndConnectCannotInterruptPendingQuery(): void
    {
        $received = new Channel(1);
        $release = new Channel(1);
        [$client, $completed] = $this->fakeServer(static function (Socket $listener) use ($received, $release): void {
            $peer = $listener->accept(1.0);
            self::acceptSession($peer);
            self::receivePacket($peer);
            self::sendPacket($peer, "\0" . pack('VvvCv', 7, 0, 0, 0, 0), 1);
            [, $query] = self::receivePacket($peer);
            self::assertSame("\x03SELECT 1", $query);
            $received->push(true);
            $release->pop(1.0);
            self::sendPacket($peer, self::ok(), 1);
            [, $close] = self::receivePacket($peer);
            self::assertSame("\x19" . pack('V', 7), $close);
            $peer->close();
        });
        $statement = $client->prepare('SELECT 1');
        $result = new Channel(1);
        Coroutine::create(static function () use ($client, $result): void {
            try { $result->push($client->rawQuery('SELECT 1')); }
            catch (\Throwable $error) { $result->push($error); }
        });
        self::assertTrue($received->pop(1.0));
        $connection = $client->mysqlClient();
        foreach ([fn() => $statement->close(), fn() => $client->close(),
            fn() => $client->connect(), fn() => $connection->connect(),
            fn() => $connection->prepare('SELECT 2'), fn() => $connection->query('SELECT 2')] as $operation) {
            try { $operation(); self::fail('Concurrent command should be rejected'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) {
                self::assertStringContainsString('Concurrent', $error->getMessage());
            }
        }
        self::assertSame($connection, $client->mysqlClient());
        self::assertTrue($connection->isConnected());
        $release->push(true);
        self::assertTrue($result->pop(1.0));
        $statement->close(); // Retry after a rejected close must still send COM_STMT_CLOSE.
        $this->assertServerCompleted($completed);
        $client->close();
    }

    public function testConnectionTimeoutIsSharedAcrossHandshakeAndAuthentication(): void
    {
        $this->assertTotalTimeout('connect');
    }

    public function testRawQueryTimeoutIncludesConnectionAndAuthentication(): void
    {
        $this->assertTotalTimeout('raw');
    }

    public function testBuilderQueryTimeoutIncludesConnectionAndAuthentication(): void
    {
        $this->assertTotalTimeout('builder');
    }

    public function testPrepareTimeoutIncludesConnectionAndAuthentication(): void
    {
        $this->assertTotalTimeout('prepare');
    }

    public function testRawQuerySharesBudgetBetweenConnectionAndResult(): void
    {
        $this->assertQueryPhasesShareTimeout(false);
    }

    public function testBuilderSharesBudgetBetweenConnectionPrepareAndExecute(): void
    {
        $this->assertQueryPhasesShareTimeout(true);
    }

    private function assertQueryPhasesShareTimeout(bool $prepared): void
    {
        [$client, $completed] = $this->fakeServer(static function (Socket $listener) use ($prepared): void {
            $peer = $listener->accept(1.0);
            Coroutine::sleep(0.04);
            self::acceptSession($peer);
            self::receivePacket($peer);
            if ($prepared) {
                Coroutine::sleep(0.04);
                self::sendPacket($peer, "\0" . pack('VvvCv', 7, 0, 0, 0, 0), 1);
                self::receivePacket($peer);
            }
            Coroutine::sleep(0.09);
            $peer->close();
        });
        $started = microtime(true);
        try {
            if ($prepared) {
                $client->query((new \EasySwoole\Mysqli\QueryBuilder())->raw('SELECT 1'), 0.1);
            } else {
                $client->rawQuery('SELECT 1', 0.1);
            }
            self::fail('Every phase must consume the same query budget');
        } catch (TimeoutException $error) {
            self::assertLessThan(0.2, microtime(true) - $started);
            self::assertFalse($client->mysqlClient()?->isConnected() ?? false);
        }
        $this->assertServerCompleted($completed);
    }

    public function testConcurrentClientConnectDoesNotReplacePendingHandshake(): void
    {
        $received = new Channel(1);
        $release = new Channel(1);
        [$client, $completed] = $this->fakeServer(static function (Socket $listener) use ($received, $release): void {
            $peer = $listener->accept(1.0);
            $received->push(true);
            $release->pop(1.0);
            self::acceptSession($peer);
            [, $quit] = self::receivePacket($peer);
            self::assertSame("\x01", $quit);
            $peer->close();
        });
        $result = new Channel(1);
        Coroutine::create(static function () use ($client, $result): void {
            try { $result->push($client->connect()); }
            catch (\Throwable $error) { $result->push($error); }
        });
        self::assertTrue($received->pop(1.0));
        $connection = $client->mysqlClient();
        try { $client->connect(); self::fail('Concurrent connection must be rejected'); }
        catch (\EasySwoole\Mysqli\Exception\Exception $error) {
            self::assertStringContainsString('Concurrent', $error->getMessage());
        }
        self::assertSame($connection, $client->mysqlClient());
        $release->push(true);
        self::assertTrue($result->pop(1.0));
        $client->close();
        $this->assertServerCompleted($completed);
    }

    public function testExpiredStatementCleanupBudgetDiscardsConnection(): void
    {
        [$client, $completed] = $this->fakeServer(static function (Socket $listener): void {
            $peer = $listener->accept(1.0);
            self::acceptSession($peer);
            self::receivePacket($peer);
            self::sendPacket($peer, "\0" . pack('VvvCv', 7, 0, 0, 0, 0), 1);
            // An exhausted cleanup budget must close the stream without another command.
            self::assertSame('', $peer->recvAll(4, 1.0));
            $peer->close();
        });
        $statement = $client->prepare('SELECT 1');
        $statement->close(0.0);
        self::assertFalse($client->mysqlClient()->isConnected());
        $this->assertServerCompleted($completed);
        $client->close();
    }

    public function testExplicitConnectTimeoutSharesHandshakeAndAuthenticationBudget(): void
    {
        $this->assertTotalTimeout('connect-explicit');
    }

    public function testExplicitConnectTimeoutDoesNotChangeConfigurationAndRetryUsesDefaults(): void
    {
        [$client, $completed, $config] = $this->fakeServer(static function (Socket $listener): void {
            $peer = $listener->accept(1.0);
            self::assertSame('', $peer->recvAll(1, 1.0));
            $peer->close();
            $peer = $listener->accept(1.0);
            // Longer than the first explicit timeout, but within configured maxConnectTime.
            Coroutine::sleep(0.06);
            self::acceptSession($peer);
            [, $quit] = self::receivePacket($peer);
            self::assertSame("\x01", $quit);
            $peer->close();
        });
        try {
            $client->connect(0.03);
            self::fail('Expected the first connection to time out');
        } catch (TimeoutException $error) {
            self::assertNull($client->mysqlClient());
        }
        self::assertTrue($client->connect());
        self::assertTrue($client->mysqlClient()->isConnected());
        self::assertSame(0.5, $config->getMaxConnectTime());
        self::assertSame(0.5, $config->getTimeout());
        $connection = $client->mysqlClient();
        self::assertTrue($client->connect(0.1));
        self::assertSame($connection, $client->mysqlClient());
        $client->close();
        $this->assertServerCompleted($completed);
    }

    public function testRawQueryNamedTimeoutIncludesConnectionAndAuthentication(): void
    {
        $this->assertTotalTimeout('raw-named');
    }

    public function testBuilderQueryNamedTimeoutIncludesConnectionAndAuthentication(): void
    {
        $this->assertTotalTimeout('builder-named');
    }

    public function testAdaptedSubclassForwardsRawQueryTimeout(): void
    {
        $this->assertTotalTimeout('subclass-raw');
    }

    public function testAdaptedSubclassForwardsBuilderQueryTimeout(): void
    {
        $this->assertTotalTimeout('subclass-builder');
    }

    public function testMysqlClientReturnsProtocolConnectionOnlyWhileClientOwnsIt(): void
    {
        [$client, $completed] = $this->fakeServer(static function (Socket $listener): void {
            $peer = $listener->accept(1.0);
            self::acceptSession($peer);
            [, $quit] = self::receivePacket($peer);
            self::assertSame("\x01", $quit);
            $peer->close();
        });
        self::assertNull($client->mysqlClient());
        self::assertTrue($client->connect());
        self::assertInstanceOf(\EasySwoole\Mysqli\Protocol\Connection::class, $client->mysqlClient());
        self::assertTrue($client->close());
        self::assertNull($client->mysqlClient());
        $this->assertServerCompleted($completed);
    }

    public function testOkPacketPreservesFullUnsignedMetadataRange(): void
    {
        [$client, $completed] = $this->fakeServer(static function (Socket $listener): void {
            $peer = $listener->accept(1.0);
            self::acceptSession($peer);
            [, $query] = self::receivePacket($peer);
            self::assertSame("\x03UPDATE huge_table SET value = 1", $query);
            $maximum = "\xfe" . str_repeat("\xff", 8);
            self::sendPacket($peer, "\0" . $maximum . $maximum . "\x02\0\0\0", 1);
            [, $quit] = self::receivePacket($peer);
            self::assertSame("\x01", $quit);
            $peer->close();
        });
        self::assertTrue($client->rawQuery('UPDATE huge_table SET value = 1'));
        self::assertSame('18446744073709551615', $client->getLastInsertId());
        self::assertSame('18446744073709551615', $client->getLastAffectRows());
        self::assertSame('18446744073709551615', $client->mysqlClient()->insert_id);
        self::assertSame('18446744073709551615', $client->mysqlClient()->affected_rows);
        $client->close();
        $this->assertServerCompleted($completed);
    }

    private function assertTotalTimeout(string $operation): void
    {
        [$client, $completed, $config] = $this->fakeServer(static function (Socket $listener): void {
            $peer = $listener->accept(1.0);
            Coroutine::sleep(0.06);
            self::sendPacket($peer, self::handshake(), 0);
            self::receivePacket($peer);
            // Each individual wait fits 0.1s, but their combined duration does not.
            Coroutine::sleep(0.07);
            $peer->close();
        }, $operation === 'connect' ? 0.1 : 0.5);
        if (str_starts_with($operation, 'subclass-')) {
            $client = new \EasySwoole\Mysqli\Tests\Support\FastDbStyleConnection($config);
        }
        $started = microtime(true);
        try {
            if ($operation === 'connect') { $client->connect(); }
            elseif ($operation === 'connect-explicit') { $client->connect(0.1); }
            elseif (in_array($operation, ['raw-named', 'subclass-raw'], true)) {
                $client->rawQuery(query: 'SELECT 1', timeout: 0.1);
            }
            elseif (in_array($operation, ['builder-named', 'subclass-builder'], true)) {
                $client->query(builder: (new \EasySwoole\Mysqli\QueryBuilder())->raw('SELECT 1'), timeout: 0.1);
            }
            elseif ($operation === 'raw') { $client->rawQuery('SELECT 1', 0.1); }
            elseif ($operation === 'prepare') { $client->prepare('SELECT 1', 0.1); }
            else { $client->query((new \EasySwoole\Mysqli\QueryBuilder())->raw('SELECT 1'), 0.1); }
            self::fail('Expected a total operation timeout');
        } catch (TimeoutException $error) {
            self::assertGreaterThan(0.08, microtime(true) - $started);
            self::assertLessThan(0.2, microtime(true) - $started);
            self::assertNull($client->mysqlClient());
        }
        $this->assertServerCompleted($completed);
    }

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

    private static function acceptSession(Socket $peer): void
    {
        self::sendPacket($peer, self::handshake(), 0);
        self::receivePacket($peer);
        self::sendPacket($peer, self::ok(), 2);
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
