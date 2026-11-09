<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Protocol\ResultStream;
use EasySwoole\Mysqli\Protocol\{Connection, Statement};
use EasySwoole\Mysqli\Config;
use PHPUnit\Framework\TestCase;

final class ResultStreamTest extends TestCase
{
    public function testRowsAreFetchedOnlyAsConsumerAdvances(): void
    {
        $calls = 0;
        $finished = [];
        $stream = new ResultStream(static function () use (&$calls): ?array {
            $calls++;
            return $calls <= 3 ? ['id' => $calls] : null;
        }, static function (bool $complete, int $rows) use (&$finished): void { $finished[] = [$complete, $rows]; });
        self::assertSame(0, $calls);
        $received = [];
        foreach ($stream as $row) {
            $received[] = $row;
            self::assertSame(count($received), $calls);
        }
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $received);
        self::assertSame([[true, 3]], $finished);
        self::assertTrue($stream->isComplete());
        self::assertTrue($stream->isClosed());
        self::assertSame(3, $stream->getRowsRead());
        $stream->close();
        self::assertCount(1, $finished);
    }

    public function testForeachBreakClosesEvenWhenStreamRemainsReferenced(): void
    {
        $finished = [];
        $success = false;
        $stream = new ResultStream(static fn(): array => ['id' => 1],
            static function (bool $complete, int $rows) use (&$finished): void { $finished[] = [$complete, $rows]; });
        $stream->onComplete(static function () use (&$success): void { $success = true; });
        foreach ($stream as $row) { break; }
        self::assertTrue($stream->isClosed());
        self::assertFalse($stream->isComplete());
        self::assertFalse($success);
        self::assertSame([[false, 1]], $finished);
    }

    public function testConsumerExceptionStillClosesStream(): void
    {
        $closed = false;
        $stream = new ResultStream(static fn(): array => ['id' => 1],
            static function () use (&$closed): void { $closed = true; });
        try {
            foreach ($stream as $row) { throw new \RuntimeException('consumer failed'); }
            self::fail('Expected consumer exception');
        } catch (\RuntimeException $error) {
            self::assertSame('consumer failed', $error->getMessage());
        }
        self::assertTrue($closed);
        self::assertTrue($stream->isClosed());
    }

    public function testExplicitIteratorCanBeClosedAndUnusedStreamIsDestroyed(): void
    {
        $closed = 0;
        $make = static function () use (&$closed): ResultStream {
            return new ResultStream(static fn(): array => ['id' => 1],
                static function () use (&$closed): void { $closed++; });
        };
        $stream = $make();
        $iterator = $stream->getIterator();
        $iterator->rewind();
        self::assertSame(['id' => 1], $iterator->current());
        $stream->close();
        self::assertSame(1, $closed);
        unset($iterator, $stream);
        self::assertSame(1, $closed);
        $unused = $make();
        unset($unused);
        self::assertSame(2, $closed);
    }

    public function testReadFailureClosesAndDoesNotFireCompletionCallback(): void
    {
        $closed = [];
        $complete = false;
        $stream = new ResultStream(static function (): array { throw new \RuntimeException('read failed'); },
            static function (bool $success, int $rows) use (&$closed): void { $closed[] = [$success, $rows]; });
        $stream->onComplete(static function () use (&$complete): void { $complete = true; });
        try { iterator_to_array($stream); self::fail('Expected read failure'); }
        catch (\RuntimeException $error) { self::assertSame('read failed', $error->getMessage()); }
        self::assertSame([[false, 0]], $closed);
        self::assertFalse($complete);
    }

    public function testCompletionCallbackFailureStillRunsCleanup(): void
    {
        $cleaned = false;
        $stream = new ResultStream(static fn(): ?array => null, static function (): void {});
        $stream->onComplete(static function (): void { throw new \RuntimeException('callback failed'); });
        $stream->onClose(static function (bool $complete) use (&$cleaned): void { $cleaned = $complete; });
        try { iterator_to_array($stream); self::fail('Expected callback failure'); }
        catch (\RuntimeException $error) { self::assertSame('callback failed', $error->getMessage()); }
        self::assertTrue($cleaned);
        self::assertTrue($stream->isComplete());
    }

    public function testResultCannotBeRewoundOrReadAfterClose(): void
    {
        $stream = new ResultStream(static fn(): ?array => null, static function (): void {});
        self::assertSame([], iterator_to_array($stream));
        $this->expectException(\LogicException::class);
        iterator_to_array($stream);
    }

    public function testStatementStreamingRejectsWrongParametersAndExpiredSessionBeforeIo(): void
    {
        $connection = new Connection(new Config());
        $statement = new Statement($connection, 7, 'SELECT ? AS id', 1, 1, 0);
        try { $statement->iterate([]); self::fail('Expected parameter count rejection'); }
        catch (\InvalidArgumentException) { self::assertFalse($connection->isBusy()); }
        try { $statement->iterate([1]); self::fail('Expired statement must not reconnect'); }
        catch (\LogicException $error) { self::assertStringContainsString('expired', $error->getMessage()); }
        self::assertFalse($connection->isBusy());
        self::assertFalse($connection->isConnected());
        $statement->close();
        $this->expectException(\LogicException::class);
        $statement->iterate([1]);
    }

    public function testConcurrentCloseCannotReleaseResourcesDuringBlockedFetch(): void
    {
        $reading = new \Swoole\Coroutine\Channel(1);
        $release = new \Swoole\Coroutine\Channel(1);
        $done = new \Swoole\Coroutine\Channel(1);
        $closed = [];
        $calls = 0;
        $stream = new ResultStream(static function () use ($reading, $release, &$calls): ?array {
            if ($calls++ > 0) { return null; }
            $reading->push(true);
            $release->pop(1.0);
            return ['id' => 1];
        }, static function (bool $complete, int $rows) use (&$closed): void { $closed[] = [$complete, $rows]; });
        \Swoole\Coroutine::create(static function () use ($stream, $done): void {
            try { $done->push(iterator_to_array($stream)); }
            catch (\Throwable $error) { $done->push($error); }
        });
        self::assertTrue($reading->pop(1.0));
        try { $stream->close(); self::fail('Concurrent close must be rejected'); }
        catch (\EasySwoole\Mysqli\Exception\Exception $error) { self::assertStringContainsString('Concurrent', $error->getMessage()); }
        self::assertSame([], $closed);
        self::assertFalse($stream->isClosed());
        $release->push(true);
        self::assertSame([['id' => 1]], $done->pop(1.0));
        self::assertSame([[true, 1]], $closed);
    }
}
