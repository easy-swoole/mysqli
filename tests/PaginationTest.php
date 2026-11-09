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
        self::assertSame(2, Pagination::result([], '00040', 20, 1)['lastPage']);
    }

    public function testInvalidPaginationArgumentsAndTotalsAreRejected(): void
    {
        foreach ([[0, 1], [20, 0], [-1, 2], [PHP_INT_MAX, 3]] as [$size, $page]) {
            try { Pagination::offset($size, $page); self::fail('Invalid pagination arguments'); }
            catch (\InvalidArgumentException $error) { self::assertNotEmpty($error->getMessage()); }
        }
        foreach ([-1, '-1', 1.5, '1e2', null, []] as $total) {
            try { Pagination::result([], $total, 20, 1); self::fail('Invalid COUNT result'); }
            catch (\UnexpectedValueException $error) { self::assertNotEmpty($error->getMessage()); }
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

    public function testClientRejectsInvalidCountsBeforeQueryingData(): void
    {
        $client = $this->client([[['__mysqli_total' => 'bad']]]);
        try {
            $client->paginate(new QueryBuilder(), 'orders');
            self::fail('Bad count must fail');
        } catch (\UnexpectedValueException $error) {
            self::assertCount(1, $client->queries);
        }
    }

    private function client(array $results): Client
    {
        return new class($results) extends Client {
            public array $queries = [];
            public function __construct(private array $results) { parent::__construct(new Config()); }
            public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
            {
                $this->queries[] = [$builder->getLastPrepareQuery(), $builder->getLastBindParams(), $timeout];
                return array_shift($this->results);
            }
        };
    }
}
