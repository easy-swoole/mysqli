<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, QueryBuilder};
use EasySwoole\Mysqli\Client\ExecutionContext;
use EasySwoole\Mysqli\Exception\{Exception, TimeoutException};
use EasySwoole\Mysqli\Protocol\{Connection, PacketStream, ResultStream, Statement};
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Socket;

final class ClientExecutionContextTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $state = (new \ReflectionProperty(Connection::class, 'state'))->getValue($connection);
            $state->disconnected(true);
            (new \ReflectionProperty(Connection::class, 'busy'))->setValue($connection, false);
            $connection->close();
        }
    }

    /** 并发保护与调用分派的测试夹具：仅创建本地套接字描述符，不绑定端口或进行网络读写。 */
    private function connected(bool $busy = false): Connection
    {
        $connection = new Connection(new Config());
        $stream = (new \ReflectionProperty(Connection::class, 'stream'))->getValue($connection);
        (new \ReflectionProperty(PacketStream::class, 'socket'))->setValue($stream, new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP));
        $state = (new \ReflectionProperty(Connection::class, 'state'))->getValue($connection);
        $state->established();
        (new \ReflectionProperty(Connection::class, 'busy'))->setValue($connection, $busy);
        $this->connections[] = $connection;
        return $connection;
    }

    private function statement(?Connection $connection = null, int $parameters = 0): Statement
    {
        return new Statement($connection ?? new Connection(new Config()), 7, 'SELECT ? AS id', $parameters, 1, 0, $connection === null ? 0 : 1);
    }

    private function assertClosed(Statement $statement): void
    {
        try { $statement->execute(); self::fail('Owned statement must already be closed'); }
        catch (\LogicException $error) { self::assertSame('The prepared statement is closed', $error->getMessage()); }
    }

    public function testBudgetShrinksAndSuccessRetainsTheOriginalTimestamp(): void
    {
        $notifications = [];
        $before = microtime(true);
        $context = new ExecutionContext(1.0, static function (bool|array $result, float $startedAt) use (&$notifications): void {
            $notifications[] = [$result, $startedAt];
        });
        $first = $context->remaining();
        usleep(2000);
        self::assertLessThan($first, $context->remaining());
        self::assertSame([['id' => 1]], $context->buffered(static fn(): array => [['id' => 1]]));
        self::assertSame([['id' => 1]], $notifications[0][0]);
        self::assertGreaterThanOrEqual($before, $notifications[0][1]);
        self::assertLessThanOrEqual(microtime(true), $notifications[0][1]);
    }

    public function testBufferedExecutionClosesItsStatementBeforeNotifying(): void
    {
        $statement = $this->statement();
        $calls = 0;
        $context = new ExecutionContext(1.0, function (bool|array $result) use ($statement, &$calls): void {
            $this->assertClosed($statement);
            self::assertTrue($result);
            $calls++;
        });
        self::assertSame($statement, $context->ownStatement($statement));
        self::assertTrue($context->buffered(static fn(): bool => true));
        self::assertSame(1, $calls);
        $context->closeOwnedStatement();
        self::assertSame(1, $calls);
    }

    public function testBufferedErrorsPropagateUnchangedAfterClosingTheStatement(): void
    {
        try { strlen([]); } catch (\TypeError $nativeError) {}
        foreach ([new Exception('server failed', 1064), new \RuntimeException('local failed', 77), $nativeError] as $original) {
            $statement = $this->statement();
            $context = new ExecutionContext(1.0, static function (): void { self::fail('Failure must not notify success'); });
            $context->ownStatement($statement);
            try {
                $context->buffered(static function () use ($original): array { throw $original; });
                self::fail('Expected the execution failure');
            } catch (\Throwable $error) {
                self::assertSame($original, $error);
            }
            $this->assertClosed($statement);
        }
    }

    public function testBufferedCleanupFailureDoesNotOverrideTheExecutionFailure(): void
    {
        $statement = $this->statement($this->connected(true));
        $context = new ExecutionContext(1.0, static function (): void { self::fail('Failed cleanup must not notify'); });
        $context->ownStatement($statement);
        $original = new \RuntimeException('execute failed');
        try {
            $context->buffered(static function () use ($original): array { throw $original; });
            self::fail('Expected execution failure');
        } catch (\RuntimeException $error) {
            self::assertSame($original, $error);
        }
    }

    public function testObserverFailureIsNotWrappedAndTheStatementIsAlreadyClosed(): void
    {
        $statement = $this->statement();
        $original = new \RuntimeException('observer failed');
        $context = new ExecutionContext(1.0, static function () use ($original): void { throw $original; });
        $context->ownStatement($statement);
        try { $context->buffered(static fn(): array => []); self::fail('Expected observer error'); }
        catch (\RuntimeException $error) { self::assertSame($original, $error); }
        $this->assertClosed($statement);
    }

    public function testOwnedStreamWaitsForEofThenClosesBeforeSuccess(): void
    {
        $statement = $this->statement();
        $events = [];
        $context = new ExecutionContext(1.0, function (bool|array $result) use ($statement, &$events): void {
            $this->assertClosed($statement);
            self::assertTrue($result);
            $events[] = 'notify';
        });
        $context->ownStatement($statement);
        $rows = [['id' => 1], null];
        $stream = new ResultStream(static function () use (&$rows): ?array { return array_shift($rows); },
            static function (bool $complete) use (&$events): void { self::assertTrue($complete); $events[] = 'protocol'; });
        $stream->onComplete(static function () use (&$events): void { $events[] = 'metadata'; });
        $stream->onClose([$context, 'finishStream']);
        self::assertSame([], $events);
        $iterator = $stream->getIterator();
        $iterator->rewind();
        self::assertSame(['id' => 1], $iterator->current());
        self::assertSame([], $events);
        $iterator->next();
        self::assertSame(['protocol', 'metadata', 'notify'], $events);
        self::assertTrue($stream->isComplete());
    }

    public function testAnEarlyStopClosesTheOwnedStatementWithoutNotifying(): void
    {
        $statement = $this->statement();
        $context = new ExecutionContext(1.0, static function (): void { self::fail('Early stop must not notify'); });
        $context->ownStatement($statement);
        $stream = (new ResultStream(static fn(): array => ['id' => 1], static function (bool $complete): void { self::assertFalse($complete); }))
            ->onClose([$context, 'finishStream']);
        foreach ($stream as $row) { break; }
        self::assertFalse($stream->isComplete());
        $this->assertClosed($statement);
    }

    public function testStreamReadFailureTakesPriorityOverStatementCleanupFailure(): void
    {
        foreach ([false, true] as $failCleanup) {
            $statement = $this->statement($failCleanup ? $this->connected(true) : null);
            $context = new ExecutionContext(1.0, static function (): void { self::fail('Failed read must not notify'); });
            $context->ownStatement($statement);
            $original = new \RuntimeException('read failed');
            $stream = (new ResultStream(static function () use ($original): array { throw $original; }, static function (): void {}))
                ->onClose([$context, 'finishStream']);
            try { iterator_to_array($stream); self::fail('Expected read/cleanup error'); }
            catch (\Throwable $error) {
                self::assertSame($original, $error);
                if (!$failCleanup) { $this->assertClosed($statement); }
            }
        }
    }

    public function testExpiredBudgetStillClosesOwnedStatementWithoutGrantingMoreTime(): void
    {
        $connection = $this->connected();
        $statement = $this->statement($connection);
        $context = new ExecutionContext(0.001, static function (): void { self::fail('Cleanup alone must not notify'); });
        $context->ownStatement($statement);
        usleep(3000);
        try { $context->remaining(); self::fail('Expected deadline expiry'); }
        catch (TimeoutException) { self::assertTrue($connection->isConnected()); }
        $context->closeOwnedStatement();
        self::assertFalse($connection->isConnected());
        $this->assertClosed($statement);
    }

    private function overrideClient(Statement $statement, ?Connection $connection = null, ?\Closure $duringPrepare = null): Client
    {
        return new class($statement, $connection, $duringPrepare) extends Client {
            /** @var list<array{string, ?float}> */
            public array $prepareCalls = [];
            public function __construct(
                private readonly Statement $statement,
                ?Connection $connection,
                private readonly ?\Closure $duringPrepare
            ) {
                parent::__construct(new Config());
                $this->mysqlClient = $connection;
                $this->lastInsertId = 9;
                $this->lastAffectRows = 8;
            }
            public function prepare(string $sql, ?float $timeout = null): Statement
            {
                $this->prepareCalls[] = [$sql, $timeout];
                $this->duringPrepare?->__invoke();
                return $this->statement;
            }
        };
    }

    public function testQueryStillDispatchesToSubclassPrepareAndPropagatesItsExecutionFailure(): void
    {
        $statement = $this->statement(parameters: 1);
        $client = $this->overrideClient($statement, $this->connected());
        $client->onQuery(static function (): void { self::fail('Failure must not notify'); });
        try { $client->query((new QueryBuilder())->raw('SELECT ? AS id'), 0.5); self::fail('Expected invalid parameter count'); }
        catch (\InvalidArgumentException $error) { self::assertSame('Statement expects 1 parameters, 0 given', $error->getMessage()); }
        self::assertSame('SELECT ? AS id', $client->prepareCalls[0][0]);
        self::assertGreaterThan(0.0, $client->prepareCalls[0][1]);
        self::assertLessThanOrEqual(0.5, $client->prepareCalls[0][1]);
        self::assertNull($client->getLastInsertId());
        self::assertNull($client->getLastAffectRows());
        $this->assertClosed($statement);
    }

    public function testQueryPropagatesNativePrepareTypeErrorWithoutNotifying(): void
    {
        $client = $this->overrideClient($this->statement(), $this->connected());
        $client->onQuery(static function (): void { self::fail('Failure must not notify'); });
        try {
            $client->query(new QueryBuilder(), 0.5);
            self::fail('An unbuilt query must reach the native prepare argument check');
        } catch (\TypeError $error) {
            self::assertNull($error->getPrevious());
        }
        self::assertSame([], $client->prepareCalls);
        self::assertNull($client->getLastInsertId());
        self::assertNull($client->getLastAffectRows());
    }

    public function testIterateStillDispatchesToSubclassPrepareAndDoesNotWrapStartupErrors(): void
    {
        foreach ([false, true] as $failCleanup) {
            $statement = $this->statement($failCleanup ? $this->connected(true) : null, 1);
            $client = $this->overrideClient($statement);
            $client->onQuery(static function (): void { self::fail('Failure must not notify'); });
            try { $client->iterate((new QueryBuilder())->raw('SELECT ? AS id'), 0.5); self::fail('Expected parameter rejection'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString('expects 1 parameters, 0 given', $error->getMessage()); }
            self::assertCount(1, $client->prepareCalls);
            self::assertGreaterThan(0.0, $client->prepareCalls[0][1]);
            self::assertLessThanOrEqual(0.5, $client->prepareCalls[0][1]);
            self::assertNull($client->getLastAffectRows());
            if (!$failCleanup) { $this->assertClosed($statement); }
        }
    }

    public function testIterateKeepsTheParametersReadBeforePrepare(): void
    {
        $builder = (new QueryBuilder())->raw('SELECT ? AS id', [1]);
        $statement = $this->statement(parameters: 1);
        $client = $this->overrideClient($statement, duringPrepare: static function () use ($builder): void {
            $builder->raw('SELECT ? AS id, ? AS other', [2, 3]);
        });
        try {
            $client->iterate($builder);
            self::fail('Expected the disconnected fixture to reject execution');
        } catch (\LogicException $error) {
            // 参数数量校验应先通过，之后才到达断开连接的检查。
            self::assertStringContainsString('expired MySQL session', $error->getMessage());
        }
        self::assertSame('SELECT ? AS id', $client->prepareCalls[0][0]);
        $this->assertClosed($statement);
    }

    public function testConnectionRejectsAStatementOwnedByAnotherConnection(): void
    {
        $owner = new Connection(new Config());
        $other = new Connection(new Config());
        $statement = new Statement($owner, 1, 'SELECT 1', 0, 1, 0);
        foreach (['executeStatement', 'iterateStatement'] as $execute) {
            try {
                $other->{$execute}($statement, [], null);
                self::fail('Expected the other connection to reject the statement');
            } catch (\LogicException $error) {
                self::assertStringContainsString('different MySQL connection', $error->getMessage());
            }
            self::assertFalse($other->isBusy());
        }
    }

    public function testInvalidTimeoutDoesNotClearEarlierMetadataOrCallPrepare(): void
    {
        $client = $this->overrideClient($this->statement());
        foreach (['query', 'iterate'] as $method) {
            try { $client->{$method}((new QueryBuilder())->raw('SELECT 1'), 0.0); self::fail('Expected timeout validation'); }
            catch (\InvalidArgumentException) { self::assertSame([], $client->prepareCalls); }
            self::assertSame(9, $client->getLastInsertId());
            self::assertSame(8, $client->getLastAffectRows());
        }
    }

    public function testPaginationKeepsSubclassQueryDispatch(): void
    {
        $client = new class extends Client {
            /** @var list<array{?string, float|null}> */
            public array $queries = [];
            /** @var list<array<array-key, mixed>> */
            public array $responses = [[['__mysqli_total' => 1]], [['id' => 7]]];
            public function __construct() { parent::__construct(new Config()); }
            public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
            {
                $this->queries[] = [$builder->getLastPrepareQuery(), $timeout];
                return array_shift($this->responses);
            }
        };
        self::assertSame([['id' => 7]], $client->paginate(new QueryBuilder(), 'records')['items']);
        self::assertCount(2, $client->queries);
    }

    public function testRawQueryReturnTypeAndProtectedCallbackRemainSubclassCompatible(): void
    {
        $client = new class(new Config()) extends Client {
            public function rawQuery(string $query, ?float $timeout = null): bool|array { return [['sql' => $query]]; }
            public function installCallback(callable $callback): void { $this->onQuery = $callback; }
        };
        $client->installCallback(static fn(): bool => true);
        self::assertSame([['sql' => 'SELECT 1']], $client->rawQuery(query: 'SELECT 1', timeout: 0.5));
        $property = new \ReflectionProperty(Client::class, 'onQuery');
        self::assertTrue($property->isProtected());
        self::assertSame('mixed', (string) $property->getType());
        $type = (new \ReflectionMethod(Client::class, 'rawQuery'))->getReturnType();
        self::assertInstanceOf(\ReflectionUnionType::class, $type);
        $names = array_map(static fn(\ReflectionNamedType $item): string => $item->getName(), $type->getTypes());
        sort($names);
        self::assertSame(['array', 'bool'], $names);
    }
}
