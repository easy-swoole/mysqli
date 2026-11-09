<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class ClientChunkTest extends TestCase
{
    private function client(array $pages): Client
    {
        return new class($pages) extends Client {
            public array $queries = [];
            public function __construct(private array $pages) { parent::__construct(new Config()); }
            public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
            {
                $this->queries[] = [$builder->getLastPrepareQuery(), $builder->getLastBindParams(), $timeout];
                if ($this->pages === []) { throw new \RuntimeException('Unexpected extra chunk query'); }
                return array_shift($this->pages);
            }
        };
    }

    public function testKeysetChunksPreserveTemplateAndGroupOrConditions(): void
    {
        $client = $this->client([[['id' => 2], ['id' => 8]], [['id' => 21]]]);
        $template = (new QueryBuilder())->where('active', 1)->orWhere('owner', 9)->orderByDesc('name')->limit(4);
        $expected = (clone $template)->get('records')->getLastPrepareQuery();
        $pages = [];
        $count = $client->chunkById($template, 'records', 'id', static function (array $rows, int $page) use (&$pages): void {
            $pages[$page] = array_column($rows, 'id');
        }, 2, 0.75);
        self::assertSame(3, $count);
        self::assertSame([1 => [2, 8], 2 => [21]], $pages);
        self::assertSame($expected, (clone $template)->get('records')->getLastPrepareQuery());
        self::assertCount(2, $client->queries);
        foreach ($client->queries as [$sql, $params, $timeout]) {
            self::assertStringNotContainsString('OFFSET', strtoupper($sql));
            self::assertStringContainsString('ORDER BY id ASC', str_replace('`', '', $sql));
            self::assertStringNotContainsString('name DESC', $sql);
            self::assertStringContainsString('LIMIT 2', $sql);
            self::assertSame(0.75, $timeout);
        }
        self::assertSame([1, 9], $client->queries[0][1]);
        self::assertSame([1, 9, 8], $client->queries[1][1]);
        self::assertMatchesRegularExpression('/WHERE\s+\(.*active.*OR.*owner.*\)\s+AND\s+`?id`?\s*>\s*\?/s', $client->queries[1][0]);
    }

    public function testCallbackCanStopAndQualifiedBigIntegerKeyRemainsExact(): void
    {
        $client = $this->client([[['id' => '18446744073709551614']], [['id' => '18446744073709551615']]]);
        $received = 0;
        self::assertSame(2, $client->chunkById(new QueryBuilder(), 'records r', 'r.id',
            static function () use (&$received): bool { return ++$received < 2; }, 1));
        self::assertCount(2, $client->queries);
        self::assertSame(['18446744073709551614'], $client->queries[1][1]);
    }

    public function testExactlyFullFinalPageNeedsEmptyFollowupAndReturnsZeroForNoRows(): void
    {
        $client = $this->client([[['id' => 1]], []]);
        self::assertSame(1, $client->chunkById(new QueryBuilder(), 'records', 'id', static fn() => null, 1));
        self::assertCount(2, $client->queries);
        $client = $this->client([[]]);
        self::assertSame(0, $client->chunkById(new QueryBuilder(), 'records', 'id', static function (): void {
            self::fail('Empty chunks must not call callback');
        }));
    }

    public function testMissingOrNullChunkKeyIsRejectedBeforeCallback(): void
    {
        foreach ([[['other' => 1]], [['id' => null]], [['id' => true]]] as $rows) {
            $client = $this->client([$rows]);
            try {
                $client->chunkById(new QueryBuilder(), 'records', 'id', static function (): void {
                    self::fail('Invalid key must be rejected before callback');
                });
                self::fail('Expected invalid chunk key');
            } catch (\UnexpectedValueException $error) { self::assertStringContainsString('chunk key', $error->getMessage()); }
        }
    }

    public function testInvalidSizeAndNonAdvancingLastKeyAreRejected(): void
    {
        $client = $this->client([[['id' => 1]], [['id' => 1]]]);
        try { $client->chunkById(new QueryBuilder(), 'records', 'id', static fn() => null, 0); self::fail(); }
        catch (\InvalidArgumentException) { self::assertSame([], $client->queries); }
        $this->expectException(\UnexpectedValueException::class);
        $client->chunkById(new QueryBuilder(), 'records', 'id', static fn() => null, 1);
    }

    public function testIntegerKeysMustAdvance(): void
    {
        $client = $this->client([[['id' => 9]], [['id' => 8]]]);
        $this->expectException(\UnexpectedValueException::class);
        $client->chunkById(new QueryBuilder(), 'records', 'id', static fn() => null, 1);
    }

    public function testNumericLookingCharacterKeysKeepDatabaseLexicalOrder(): void
    {
        $client = $this->client([[['id' => '1']], [['id' => '10']], [['id' => '2']], []]);
        self::assertSame(3, $client->chunkById(new QueryBuilder(), 'records', 'id', static fn() => null, 1));
        self::assertSame(['10'], $client->queries[2][1]);
    }

    public function testCallbackCanReleaseItsRowsWithoutEndingTheNextPage(): void
    {
        $client = $this->client([[['id' => 1]], [['id' => 2]], []]);
        self::assertSame(2, $client->chunkById(new QueryBuilder(), 'records', 'id',
            static function (array &$rows): void { $rows = []; }, 1));
        self::assertCount(3, $client->queries);
    }

    public function testOtherStringKeysUseDatabaseOrderRatherThanPhpComparison(): void
    {
        $client = $this->client([[['id' => 'a']], [['id' => 'Z']], []]);
        self::assertSame(2, $client->chunkById(new QueryBuilder(), 'records', 'id', static fn() => null, 1));
    }
}
