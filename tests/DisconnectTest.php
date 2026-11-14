<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class DisconnectTest extends TestCase
{
    private ?Client $victim = null;
    private ?Client $killer = null;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            $this->markTestSkipped('Set FAST_DB_TEST_* or MYSQLI_TEST_* to the EasySwoole FastDb test database.');
        }
        $this->victim = new Client(new Config(MYSQL_CONFIG));
        $this->killer = new Client(new Config(MYSQL_CONFIG));
        $this->victim->connect();
        $this->killer->connect();
    }

    protected function tearDown(): void
    {
        $this->victim?->close();
        $this->killer?->close();
        $this->victim = null;
        $this->killer = null;
    }

    public function testPingDetectsServerSideDisconnectAndRequiresExplicitReconnect(): void
    {
        $connection = $this->victim->mysqlClient();
        $oldStatement = $this->victim->prepare('SELECT ? AS id');
        $oldConnectionId = $this->connectionId($this->victim);
        $this->kill($oldConnectionId);

        self::assertFalse($this->victim->ping());
        self::assertFalse($this->victim->mysqlClient()?->isConnected() ?? false);

        $this->assertVictimRequiresConnect();
        $this->victim->connect();
        self::assertSame($connection, $this->victim->mysqlClient());
        $newStatement = $this->victim->prepare('SELECT ? AS id');
        try {
            $oldStatement->execute([7]);
            self::fail('The old statement must expire after reconnect');
        } catch (\LogicException $error) {
            self::assertStringContainsString('expired MySQL session', $error->getMessage());
        } finally {
            $oldStatement->close();
        }
        self::assertSame([['id' => 8]], $newStatement->execute([8]));
        $newStatement->close();
        $newConnectionId = $this->connectionId($this->victim);
        self::assertNotSame($oldConnectionId, $newConnectionId);
        self::assertTrue($this->victim->ping());
    }

    public function testQueryAfterServerSideDisconnectThrowsUntilExplicitReconnect(): void
    {
        $oldConnectionId = $this->connectionId($this->victim);
        $this->kill($oldConnectionId);

        try {
            $this->victim->rawQuery('SELECT 1 AS should_fail');
            self::fail('A query on a server-terminated connection must fail');
        } catch (Exception $error) {
            self::assertStringContainsString('MySQL query failed', $error->getMessage());
            self::assertNotSame('', $error->getMessage());
        }

        self::assertFalse($this->victim->mysqlClient()?->isConnected() ?? false);
        self::assertNull($this->victim->getLastInsertId());
        self::assertNull($this->victim->getLastAffectRows());

        $this->assertVictimRequiresConnect();
        $this->victim->connect();
        $rows = $this->victim->rawQuery('SELECT CONNECTION_ID() AS connection_id, 1 AS recovered');
        self::assertSame(1, $rows[0]['recovered']);
        self::assertNotSame($oldConnectionId, $rows[0]['connection_id']);
        self::assertTrue($this->victim->ping());
    }

    public function testDisconnectWhileQueryIsWaitingForAResult(): void
    {
        $connectionId = $this->connectionId($this->victim);
        $killResult = new Channel(1);
        Coroutine::create(function () use ($connectionId, $killResult): void {
            try {
                Coroutine::sleep(0.05);
                $killResult->push($this->killer->rawQuery("KILL CONNECTION {$connectionId}"));
            } catch (\Throwable $error) {
                $killResult->push($error);
            }
        });

        $startedAt = microtime(true);
        try {
            $this->victim->rawQuery('SELECT SLEEP(2)', 1.0);
            self::fail('Killing an in-flight query connection must fail the query');
        } catch (Exception $error) {
            self::assertStringContainsString('MySQL query failed', $error->getMessage());
            self::assertLessThan(1.0, microtime(true) - $startedAt);
        }

        $kill = $killResult->pop(1.0);
        if ($kill instanceof \Throwable) {
            throw $kill;
        }
        self::assertTrue($kill);
        self::assertFalse($this->victim->mysqlClient()?->isConnected() ?? false);
        $this->assertVictimRequiresConnect();
        $this->victim->connect();
        self::assertSame([['recovered' => 1]], $this->victim->rawQuery('SELECT 1 AS recovered'));
    }

    private function assertVictimRequiresConnect(): void
    {
        try {
            $this->victim->rawQuery('SELECT 1');
            self::fail('Expected the disconnected client to reject the query');
        } catch (Exception $error) {
            self::assertStringContainsString('call connect() first', $error->getMessage());
        }
    }

    private function connectionId(Client $client): int
    {
        return $client->rawQuery('SELECT CONNECTION_ID() AS connection_id')[0]['connection_id'];
    }

    private function kill(int $connectionId): void
    {
        self::assertTrue($this->killer->rawQuery("KILL CONNECTION {$connectionId}"));
    }
}
