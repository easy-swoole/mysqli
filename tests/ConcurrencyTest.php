<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

final class ConcurrencyTest extends TestCase
{
    public function testIndependentQueriesYieldWhileWaitingForMysql(): void
    {
        if (MYSQL_CONFIG['host'] === '') {
            self::markTestSkipped('Set MYSQLI_TEST_* environment variables for integration tests');
        }

        $clients = [];
        try {
            foreach ([5, 3] as $seconds) {
                $config = MYSQL_CONFIG;
                $config['timeout'] = 8.0;
                $config['maxConnectTime'] = 3.0;
                $clients[$seconds] = new Client(new Config($config));
                $clients[$seconds]->connect();
            }
        } catch (\Throwable $error) {
            foreach ($clients as $client) { $client->close(); }
            throw $error;
        }

        // Measure query concurrency independently of remote handshake latency.
        $results = new Channel(2);
        $startedAt = microtime(true);
        foreach ($clients as $seconds => $client) {
            Coroutine::create(static function () use ($seconds, $client, $results): void {
                $queryStartedAt = microtime(true);
                try {
                    $rows = $client->rawQuery("SELECT SLEEP({$seconds}) AS slept, {$seconds} AS requested_seconds");
                    $results->push([
                        'seconds' => $seconds,
                        'elapsed' => microtime(true) - $queryStartedAt,
                        'rows' => $rows,
                    ]);
                } catch (\Throwable $error) {
                    $results->push($error);
                } finally {
                    $client->close();
                }
            });
        }

        $completed = [];
        for ($i = 0; $i < 2; $i++) {
            $result = $results->pop(10.0);
            if ($result instanceof \Throwable) {
                throw $result;
            }
            self::assertIsArray($result, 'Both coroutine queries must complete');
            $completed[$result['seconds']] = $result;
        }
        $elapsed = microtime(true) - $startedAt;

        self::assertSame([['slept' => 0, 'requested_seconds' => 3]], $completed[3]['rows']);
        self::assertSame([['slept' => 0, 'requested_seconds' => 5]], $completed[5]['rows']);
        self::assertGreaterThanOrEqual(2.8, $completed[3]['elapsed']);
        self::assertGreaterThanOrEqual(4.8, $completed[5]['elapsed']);
        self::assertGreaterThanOrEqual(4.8, $elapsed);
        self::assertLessThan(7.0, $elapsed, 'Queries ran serially instead of yielding between coroutines');
    }
}
