<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{ConditionValue, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class QueryBuilderSafetyTest extends TestCase
{
    public function testFieldOrderingBindsOriginalValuesAtTheirSqlPosition(): void
    {
        $values = ['paid+', 'a/b', "O'Reilly", '中文', '', null, false, 0, 1.5];
        $branch = (new QueryBuilder())->selectRaw('? AS id', [8])->get('(SELECT 1) seed');
        $q = (new QueryBuilder())->orderBy('id', 'ASC', $values)->where('tenantId', 7)
            ->union($branch)->selectRaw('? AS id', [9])->get('orders');
        self::assertSame([9, 7, 8, ...$values], $q->getLastBindParams());
        self::assertStringEndsWith('ORDER BY FIELD (id, ?, ?, ?, ?, ?, ?, ?, ?, ?) ASC ', $q->getLastPrepareQuery());
        self::assertStringNotContainsString('paid+', $q->getLastPrepareQuery());
    }

    public function testCustomSortKeysIncludeValuesAndPreserveDirectionReplacement(): void
    {
        $q = (new QueryBuilder())->orderBy('status', 'ASC', ['a/b'])
            ->orderBy('status', 'DESC', ['a+b'])->orderBy('status', 'DESC', ['a/b'])->get('orders');
        self::assertSame(['a/b', 'a+b'], $q->getLastBindParams());
        self::assertSame(2, substr_count($q->getLastPrepareQuery(), 'FIELD (status, ?) DESC'));
    }

    public function testInvalidSortDoesNotOverwritePreviousSort(): void
    {
        foreach ([[], [new \stdClass()], [[1, 2]]] as $values) {
            $q = (new QueryBuilder())->orderByAsc('id');
            try {
                $q->orderBy('status', 'DESC', $values);
                self::fail('Invalid custom sort should be rejected');
            } catch (\InvalidArgumentException $error) {
                $q->get('orders');
                self::assertStringEndsWith('ORDER BY id ASC ', $q->getLastPrepareQuery());
                self::assertSame([], $q->getLastBindParams());
            }
        }
    }

    public function testQueryOptionsAreAppliedOnlyWhenEntireBatchIsValid(): void
    {
        foreach ([['DISTINCT', 'FOR UPDATE', 'INVALID'], ['DISTINCT', []]] as $options) {
            $q = (new QueryBuilder())->setQueryOption('ALL')->where('tenantId', 7);
            $caught = null;
            try { $q->setQueryOption($options); }
            catch (\Throwable $error) { $caught = $error; }
            self::assertNotNull($caught, 'Invalid query option should be rejected');
            $q->get('orders');
            self::assertSame('SELECT ALL * FROM `orders` WHERE  `tenantId` = ? ', $q->getLastPrepareQuery());
            self::assertSame(['ALL'], $q->getLastQueryOptions());
            self::assertSame([7], $q->getLastBindParams());
            self::assertNull($q->getLastTransactionOp());
        }
    }

    public function testFailedCompilationDoesNotPublishPartialSqlOrLeakIntoRaw(): void
    {
        $q = (new QueryBuilder())->where('id', 1)->get('orders');
        $sql = $q->getLastPrepareQuery();
        $debug = $q->getLastQuery();
        try {
            $q->selectRaw('? AS marker', [9])->where('tenantId', 7)->where('id', [1, 2])->get('orders');
            self::fail('Expected invalid scalar comparison');
        } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
            self::assertSame($sql, $q->getLastPrepareQuery());
            self::assertSame($debug, $q->getLastQuery());
            self::assertSame([1], $q->getLastBindParams());
        }
        $q->reset();
        self::assertSame($sql, $q->getLastPrepareQuery());
        self::assertSame([1], $q->getLastBindParams());
        $q->raw('SELECT ? AS value', [99]);
        self::assertSame('SELECT ? AS value', $q->getLastPrepareQuery());
        self::assertSame([99], $q->getLastBindParams());
        $q->where('id', 2)->get('orders');
        self::assertSame([2], $q->getLastBindParams());
    }

    public function testRawImmediatelyAfterFailureReplacesBindingsAndDiscardsPendingClauses(): void
    {
        $q = (new QueryBuilder())->selectRaw('? AS marker', [9])->where('tenantId', 7)->where('id', [1]);
        try { $q->get('orders'); } catch (\EasySwoole\Mysqli\Exception\Exception $error) {}
        $q->raw('SELECT ? AS value', [99]);
        self::assertSame([99], $q->getLastBindParams());
        $q->get('orders');
        self::assertSame('SELECT  * FROM `orders`', $q->getLastPrepareQuery());
        self::assertSame([], $q->getLastBindParams());
    }

    public function testKeysetPageClonesTemplateAndGroupsOriginalOrPredicates(): void
    {
        $template = (new QueryBuilder())->setPrefix('app_')->where('active', true)->orWhere('ownerId', 7)
            ->orderByRaw('FIELD(id, ?) DESC', [99])->limit(100);
        $page = $template->forPageAfterId('o.id', '18446744073709551600', 10)->get('orders o');
        self::assertSame([true, 7, '18446744073709551600'], $page->getLastBindParams());
        self::assertMatchesRegularExpression('/WHERE\s+\(.*active.*OR.*ownerId.*\)\s+AND\s+`o`\.`id` > \?/s', $page->getLastPrepareQuery());
        self::assertStringEndsWith('ORDER BY `o`.`id` ASC  LIMIT 10', $page->getLastPrepareQuery());
        $template->get('orders o');
        self::assertSame([true, 7, 99], $template->getLastBindParams());
        self::assertStringEndsWith('ORDER BY FIELD(id, ?) DESC  LIMIT 100', $template->getLastPrepareQuery());
    }

    public function testKeysetFirstPageHasNoCursorPredicateAndRejectsComplexTemplates(): void
    {
        $page = (new QueryBuilder())->whereNull('deleted_at')->forPageAfterId('id', null, 2)->get('users');
        self::assertSame([], $page->getLastBindParams());
        self::assertStringNotContainsString(' > ', $page->getLastPrepareQuery());
        foreach ([
            (new QueryBuilder())->groupBy('tenantId'),
            (new QueryBuilder())->havingRaw('COUNT(*) > 1'),
            (new QueryBuilder())->distinct(),
            (new QueryBuilder())->forUpdate(),
            (new QueryBuilder())->union((new QueryBuilder())->get('archive')),
        ] as $template) {
            try { $template->forPageAfterId('id', null, 2); self::fail('Complex keyset template should fail'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString('plain SELECT', $error->getMessage()); }
        }
    }
}
