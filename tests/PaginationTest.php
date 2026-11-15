<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, QueryBuilder};
use EasySwoole\Mysqli\Exception\TimeoutException;
use EasySwoole\Mysqli\QueryBuilder\Pagination;
use EasySwoole\Mysqli\Tests\Support\FakeMysqlServer;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    use FakeMysqlServer;

    private ?Client $client = null;
    private ?Channel $completed = null;
    private array $queries = [];

    protected function tearDown(): void
    {
        $this->client?->close();
        if ($this->completed !== null) {
            $this->assertServerCompleted($this->completed);
        }
    }

    public function testUnsignedCountsAndLastPageNeverLosePrecision(): void
    {
        $large = '18446744073709551615';
        $result = Pagination::result([], $large, 20, 1);
        self::assertSame($large, $result['total']);
        self::assertSame(922337203685477581, $result['lastPage']);
        self::assertSame($large, Pagination::result([], $large, 1, 1)['lastPage']);
        self::assertSame(3, Pagination::result([], $large, PHP_INT_MAX, 1)['lastPage']);
        self::assertSame(1, Pagination::result([], 0, 20, 1)['lastPage']);
        self::assertSame(3, Pagination::result([], 41, 20, 1)['lastPage']);
        self::assertSame(PHP_INT_MAX, Pagination::result([], PHP_INT_MAX, 1, 1)['lastPage']);
        self::assertSame('9223372036854775808', Pagination::result([], '9223372036854775808', 1, 1)['lastPage']);
    }

    public function testInvalidPaginationArgumentsAreRejected(): void
    {
        foreach ([[0, 1], [20, 0], [-1, 2], [PHP_INT_MAX, 3]] as [$size, $page]) {
            try { Pagination::offset($size, $page); self::fail('Invalid pagination arguments'); }
            catch (\InvalidArgumentException $error) { self::assertNotEmpty($error->getMessage()); }
        }
    }

    public function testClientPaginationClonesTemplateAndSeparatesCountFromDataParameters(): void
    {
        $client = $this->client([[['__mysqli_total' => 45]], [['id' => 3], ['id' => 4]]]);
        $template = (new QueryBuilder())->where('tenantId', 7)->orderByRaw('FIELD(id, ?) DESC', [99]);
        $page = $client->paginate($template, 'orders', 20, 2);
        self::assertSame(['items' => [['id' => 3], ['id' => 4]], 'total' => 45,
            'page' => 2, 'perPage' => 20, 'lastPage' => 3], $page);
        self::assertSame([7], $this->queries[0][1]);
        self::assertSame([7, 99], $this->queries[1][1]);
        self::assertStringNotContainsString('ORDER BY', $this->queries[0][0]);
        self::assertStringEndsWith('LIMIT 20, 20', $this->queries[1][0]);
        self::assertNull($template->getLastPrepareQuery());
        $template->get('orders');
        self::assertSame([7, 99], $template->getLastBindParams());
        self::assertStringNotContainsString('LIMIT', $template->getLastPrepareQuery());
    }

    public function testClientPreservesUnsignedCountPrecision(): void
    {
        $total = '18446744073709551615';
        $client = $this->client([[['__mysqli_total' => $total]], [['id' => 7]]]);
        self::assertSame(['items' => [['id' => 7]], 'total' => $total, 'page' => 1,
            'perPage' => 1, 'lastPage' => $total], $client->paginate(new QueryBuilder(), 'orders', 1));
        self::assertCount(2, $this->queries);
    }

    public function testPaginationUsesTheSameGetScopeForCountAndData(): void
    {
        $template = new class extends QueryBuilder {
            public static array $calls = [];

            public function __clone(): void
            {
                self::$calls[] = 'clone';
                $this->where('clone_marker', 7);
            }

            public function count(mixed $tableName, string $alias = 'aggregate'): QueryBuilder
            {
                self::$calls[] = 'count';
                return parent::count($tableName, $alias);
            }

            public function get(mixed $tableName, mixed $numRows = null, mixed $columns = null): QueryBuilder
            {
                self::$calls[] = 'get';
                $this->where('tenantId', 9);
                return parent::get($tableName, $numRows, $columns);
            }

            public function raw(mixed $sql, mixed $param = []): QueryBuilder
            {
                self::$calls[] = 'raw';
                return parent::raw($sql, $param);
            }
        };
        $client = $this->client([[['__mysqli_total' => 1]], [['id' => 3]]]);
        self::assertSame(1, $client->paginate($template, 'orders')['total']);
        self::assertSame(['clone', 'get'], $template::$calls);
        foreach ($this->queries as [$sql, $parameters]) {
            self::assertStringContainsString('`tenantId` = ?', $sql);
            self::assertSame([7, 9], $parameters);
        }
        self::assertFalse($template->hasWhere());
        self::assertNull($template->getLastCompiledQuery());
        $template->count('orders');
        self::assertSame(['clone', 'get', 'count'], $template::$calls);
    }

    public function testPaginationBuildsBothQueriesBeforeTheFirstCanChangeTheTemplate(): void
    {
        $subquery = QueryBuilder::subQuery()->where('active', true)->get('users', null, 'id');
        $template = (new QueryBuilder())->where('tenantId', 7)->whereIn('userId', $subquery);
        $client = $this->client([[['__mysqli_total' => 1]], [['id' => 3]]], static function () use ($template, $subquery): void {
            $template->where('status', 'paid');
            $subquery->where('active', false)->get('users', null, 'id');
        });
        self::assertSame(1, $client->paginate($template, 'orders')['total']);
        self::assertSame([7, true], $this->queries[0][1]);
        self::assertSame([7, true], $this->queries[1][1]);
        self::assertStringNotContainsString('status', $this->queries[1][0]);
    }

    public function testPaginationUsesOriginalDeadlineAndSkipsThePublicQueryOverride(): void
    {
        $client = $this->client([[['__mysqli_total' => 1]]]);
        $query = (new QueryBuilder())->raw('SELECT 7 AS id');
        self::assertSame([['id' => 7]], $client->query($query, 0.1));
        self::assertSame([[$query, 0.1]], $client->queryCalls);
        $client->onQuery(static function (): void {
            // COUNT 已读完并清理语句，回调耗尽原有预算。
            Coroutine::sleep(0.25);
        });

        try {
            $client->paginate(new QueryBuilder(), 'orders', timeout: 0.2);
            self::fail('Expected the shared deadline to expire before preparing the data query');
        } catch (TimeoutException) {
            self::assertCount(1, $this->queries);
            self::assertCount(1, $client->queryCalls);
            self::assertTrue($client->mysqlClient()->isConnected());
        }
    }

    private function client(array $results, ?\Closure $afterFirstQuery = null): Client
    {
        [, $this->completed, $config] = $this->fakeServer(function (Socket $listener) use ($results): void {
            $peer = $listener->accept(1.0);
            self::acceptSession($peer);
            foreach ($results as $index => $rows) {
                $id = $index + 1;
                [, $prepare] = self::receivePacket($peer);
                self::assertSame("\x16", $prepare[0]);
                $sql = substr($prepare, 1);
                $column = array_key_first($rows[0]);
                $type = is_int($rows[0][$column]) ? 8 : 253;
                $count = substr_count($sql, '?');
                self::sendPreparedMetadata($peer, $id, $count, $column, $type);

                [, $execute] = self::receivePacket($peer);
                self::assertSame("\x17" . pack('V', $id), substr($execute, 0, 5));
                $params = [];
                if ($count > 0) {
                    $typesOffset = 11 + intdiv($count + 7, 8);
                    $valueOffset = $typesOffset + $count * 2;
                    for ($i = 0; $i < $count; $i++) {
                        $boolean = ord($execute[$typesOffset + $i * 2]) === 1;
                        $params[] = $boolean ? (bool) ord($execute[$valueOffset]) : unpack('P', $execute, $valueOffset)[1];
                        $valueOffset += $boolean ? 1 : 8;
                    }
                }
                $this->queries[] = [$sql, $params];
                self::sendResultHeader($peer, $column, $type, true);
                foreach ($rows as $number => $row) {
                    $value = $row[$column];
                    $payload = is_int($value) ? pack('P', $value) : chr(strlen($value)) . $value;
                    self::sendPacket($peer, "\0\0" . $payload, 4 + $number);
                }
                self::sendPacket($peer, self::eof(), 4 + count($rows));
                [, $close] = self::receivePacket($peer);
                self::assertSame("\x19" . pack('V', $id), $close);
            }
            [, $quit] = self::receivePacket($peer);
            self::assertSame("\x01", $quit);
            $peer->close();
        });
        $this->client = new class($config) extends Client {
            public array $queryCalls = [];

            public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
            {
                $this->queryCalls[] = [$builder, $timeout];
                return [['id' => 7]];
            }
        };
        $this->client->connect();
        if ($afterFirstQuery !== null) {
            $this->client->onQuery(function () use ($afterFirstQuery): void {
                if (count($this->queries) === 1) {
                    $afterFirstQuery();
                }
            });
        }
        return $this->client;
    }
}
