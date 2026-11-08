<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, QueryBuilder};
use PHPUnit\Framework\TestCase;

/** All queries use derived constants, leaving existing database contents untouched. */
final class QueryBuilderIntegrationTest extends TestCase
{
    private ?Client $client = null;

    protected function setUp(): void
    {
        if (MYSQL_CONFIG['host'] === '') { self::markTestSkipped('Set MYSQLI_TEST_* for live SQL checks'); }
        $this->client = new Client(new Config(MYSQL_CONFIG));
    }

    protected function tearDown(): void { $this->client?->close(); }

    public function testQuotedRegularExpressionIsBoundAfterWhereValues(): void
    {
        $builder = (new QueryBuilder())->where('id', 0, '>')->orderBy('name', 'DESC', "O'Reilly")
            ->get("(SELECT 1 AS id, 'O''Reilly' AS name UNION ALL SELECT 2, 'Alice') users");
        self::assertSame([0, "O'Reilly"], $builder->getLastBindParams());
        self::assertSame([1, 2], array_column($this->client->query($builder), 'id'));
    }

    public function testUnionGlobalSortLimitAndLocalBranchSortLimit(): void
    {
        $branch = (new QueryBuilder())->where('id', 0, '>')->orderByAsc('id')
            ->get('(SELECT 3 AS id UNION ALL SELECT 1) archive', 1, 'id');
        $builder = (new QueryBuilder())->where('id', 0, '>')->union($branch)->orderByDesc('id')
            ->get('(SELECT 2 AS id) orders', 1, 'id');
        self::assertSame([0, 0], $builder->getLastBindParams());
        self::assertSame([['id' => 2]], $this->client->query($builder));
    }

    public function testNestedUnionAndGlobalRegexParameters(): void
    {
        $inner = (new QueryBuilder())->union((new QueryBuilder())->get('(SELECT 3 AS id) third', null, 'id'))
            ->where('id', 0, '>')->get('(SELECT 1 AS id) first', null, 'id');
        $outer = (new QueryBuilder())->union($inner)->where('id', 0, '>')
            ->orderBy('CAST(id AS CHAR)', 'DESC', '^3$')->orderByDesc('id')
            ->get('(SELECT 2 AS id) second', null, 'id');
        self::assertSame([0, 0, '^3$'], $outer->getLastBindParams());
        self::assertSame([3, 2, 1], array_column($this->client->query($outer), 'id'));
    }

    public function testExplicitZeroLimitReturnsNoRows(): void
    {
        $builder = (new QueryBuilder())->get('(SELECT 1 AS id UNION ALL SELECT 2) orders', 0);
        self::assertSame([], $this->client->query($builder));
    }

    public function testJoinNullPredicatesWithWordOperators(): void
    {
        foreach (['IS' => 1, 'IS NOT' => 2] as $operator => $expectedId) {
            $table = '(SELECT 1 AS id, NULL AS deleted_at UNION ALL SELECT 2, 1) u';
            $builder = (new QueryBuilder())->join($table, 'u.id=o.id', 'INNER')
                ->joinWhere($table, 'u.deleted_at', null, $operator)
                ->get('(SELECT 1 AS id UNION ALL SELECT 2) o', null, 'o.id');
            self::assertSame([['id' => $expectedId]], $this->client->query($builder));
        }
    }
}
