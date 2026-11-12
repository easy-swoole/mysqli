<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, QueryBuilder};
use EasySwoole\Mysqli\QueryBuilder\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
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
        self::assertSame([7], $client->queries[0][1]);
        self::assertSame([7, 99], $client->queries[1][1]);
        self::assertStringNotContainsString('ORDER BY', $client->queries[0][0]);
        self::assertStringEndsWith('LIMIT 20, 20', $client->queries[1][0]);
        self::assertLessThanOrEqual($client->queries[0][2], $client->queries[1][2]);
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
        self::assertCount(2, $client->queries);
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
        self::assertSame([7, true], $client->queries[0][1]);
        self::assertSame([7, true], $client->queries[1][1]);
        self::assertStringNotContainsString('status', $client->queries[1][0]);
    }

    private function client(array $results, ?\Closure $afterFirstQuery = null): Client
    {
        return new class($results, $afterFirstQuery) extends Client {
            public array $queries = [];
            public function __construct(private array $results, private readonly ?\Closure $afterFirstQuery)
            {
                parent::__construct(new Config());
            }
            public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
            {
                $this->queries[] = [$builder->getLastPrepareQuery(), $builder->getLastBindParams(), $timeout];
                if (count($this->queries) === 1) {
                    $this->afterFirstQuery?->__invoke();
                }
                return array_shift($this->results);
            }
        };
    }
}
