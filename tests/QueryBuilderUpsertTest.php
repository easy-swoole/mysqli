<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Expression;
use EasySwoole\Mysqli\QueryBuilder\WriteCompiler;
use PHPUnit\Framework\TestCase;

final class QueryBuilderUpsertTest extends TestCase
{
    public function testBatchImplicitUpdatesReferenceEachIncomingRow(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name'])->insertAll('users', [
            ['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'],
        ]);
        self::assertSame('INSERT  INTO `users` (`id`, `name`)  VALUES (?, ?),(?, ?) AS `__mysqli_new` ON DUPLICATE KEY UPDATE `name` = `__mysqli_new`.`name`', $q->getLastPrepareQuery());
        self::assertSame([1, 'a', 2, 'b'], $q->getLastBindParams());
        $q->get('users');
        self::assertSame('SELECT  * FROM `users`', $q->getLastPrepareQuery());
        self::assertSame([], $q->getLastBindParams());
    }

    public function testBatchColumnOrderCanDifferAndNullIsAnExplicitValue(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name'])->insertAll('users', [
            ['id' => 1, 'name' => 'a'], ['name' => null, 'id' => 2],
        ]);
        self::assertSame([1, 'a', 2, null], $q->getLastBindParams());
        self::assertStringEndsWith('`name` = `__mysqli_new`.`name`', $q->getLastPrepareQuery());
    }

    public function testExplicitBatchUpdatesRemainOneBoundFixedValue(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name' => 'fixed'])->insertAll('users', [
            ['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'],
        ]);
        self::assertSame('INSERT  INTO `users` (`id`, `name`)  VALUES (?, ?),(?, ?) ON DUPLICATE KEY UPDATE `name` = ?', $q->getLastPrepareQuery());
        self::assertSame([1, 'a', 2, 'b', 'fixed'], $q->getLastBindParams());
    }

    public function testMixedImplicitAndExplicitUpdatesPreserveBindingOrder(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name', 'status' => true])->insertAll('users', [
            ['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'],
        ]);
        self::assertSame([1, 'a', 2, 'b', true], $q->getLastBindParams());
        self::assertStringEndsWith('ON DUPLICATE KEY UPDATE `status` = ?, `name` = `__mysqli_new`.`name`', $q->getLastPrepareQuery());
    }

    public function testSingleRowImplicitUpdateKeepsExistingSqlAndBindings(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name'])->insert('users', ['id' => 1, 'name' => 'a']);
        self::assertSame('INSERT  INTO `users` (`id`, `name`)  VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = ?', $q->getLastPrepareQuery());
        self::assertSame([1, 'a', 'a'], $q->getLastBindParams());
    }

    public function testIncomingRowAliasCannotCollideWithTargetTable(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name'])->insertAll('__mysqli_new', [['name' => 'a'], ['name' => 'b']]);
        self::assertStringContainsString(' AS `__mysqli_new_row` ON DUPLICATE KEY UPDATE `name` = `__mysqli_new_row`.`name`', $q->getLastPrepareQuery());
        $q->onDuplicate(['name'])->insertAll('test.__mysqli_new', [['name' => 'a']]);
        self::assertStringContainsString(' AS `__mysqli_new_row`', $q->getLastPrepareQuery());
        $q->onDuplicate(['name'])->insertAll(' `__mysqli_new` ', [['name' => 'a']]);
        self::assertStringContainsString(' AS `__mysqli_new_row`', $q->getLastPrepareQuery());
    }

    public function testLegacyPerRowExpressionsKeepTheirOwnParameters(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name'])->insertAll('users', [
            ['id' => 1, 'name' => QueryBuilder::func('UPPER(?)', ['a'])],
            ['id' => 2, 'name' => QueryBuilder::func('LOWER(?)', ['B'])],
        ]);
        self::assertStringContainsString('VALUES (?, UPPER(?)),(?, LOWER(?)) AS', $q->getLastPrepareQuery());
        self::assertSame([1, 'a', 2, 'B'], $q->getLastBindParams());
    }

    public function testTypedExpressionsAreBoundInRowAndExplicitUpdateOrder(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name' => new Expression('UPPER(?)', ['fixed'])])->insertAll('users', [
            ['id' => 1, 'name' => new Expression('CONCAT(?, ?)', ['a', 'b'])],
            ['id' => 2, 'name' => new Expression('LOWER(?)', ['C'])],
        ]);
        self::assertStringContainsString('VALUES (?, CONCAT(?, ?)),(?, LOWER(?)) ON DUPLICATE KEY UPDATE `name` = UPPER(?)', $q->getLastPrepareQuery());
        self::assertSame([1, 'a', 'b', 2, 'C', 'fixed'], $q->getLastBindParams());
        self::assertStringNotContainsString(' AS `__mysqli_new`', $q->getLastPrepareQuery());
    }

    public function testInvalidBatchShapesAreRejectedBeforeBinding(): void
    {
        foreach ([[], [null], [['id' => 1], []], [['id' => 1], ['id' => 2, 'name' => 'b']],
            [['id' => 1, 'name' => 'a'], ['id' => 2]], [['id' => 1], ['other' => 2]],
            [[0 => 1]], [['' => 1]], [["bad\0name" => 1]]] as $rows) {
            try {
                WriteCompiler::normalizeBatch($rows);
                self::fail('Expected malformed batch rows to be rejected');
            } catch (\InvalidArgumentException $error) { self::assertNotEmpty($error->getMessage()); }
        }
        self::assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => null]], WriteCompiler::normalizeBatch([
            3 => ['id' => 1, 'name' => 'a'], 8 => ['name' => null, 'id' => 2],
        ]));
    }

    public function testMissingImplicitUpdateColumnIsRejectedInsteadOfBindingNull(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new QueryBuilder())->onDuplicate(['missing'])->insertAll('users', [['id' => 1], ['id' => 2]]);
    }

    public function testQuotedColumnNamesAreEscapedInBothInsertAndUpdateReferences(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['na`me'])->insertAll('users', [['na`me' => 'a'], ['na`me' => 'b']]);
        self::assertStringContainsString('(`na``me`)', $q->getLastPrepareQuery());
        self::assertStringEndsWith('`na``me` = `__mysqli_new`.`na``me`', $q->getLastPrepareQuery());
        self::assertSame(['a', 'b'], $q->getLastBindParams());
    }

    public function testUpsertConvenienceAndInvalidUpdateColumns(): void
    {
        $q = (new QueryBuilder())->upsert('users', [
            ['id' => 1, 'name' => 'a'], ['name' => 'b', 'id' => 2],
        ], ['name', 'status' => 'active']);
        self::assertSame([1, 'a', 2, 'b', 'active'], $q->getLastBindParams());
        self::assertStringContainsString('`name` = `__mysqli_new`.`name`', $q->getLastPrepareQuery());

        foreach ([[], ['missing'], [new \stdClass()]] as $updates) {
            $q = new QueryBuilder();
            try {
                $q->upsert('users', [['id' => 1]], $updates);
                self::fail('Expected invalid upsert inputs to be rejected');
            } catch (\InvalidArgumentException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function testBatchWhitelistFiltersBeforeMatchingColumns(): void
    {
        $q = (new QueryBuilder())->insertAll('users', [
            ['id' => 1, 'discard_a' => 'a'], ['discard_b' => 'b', 'id' => 2],
        ], ['field' => ['id']]);
        self::assertSame('INSERT  INTO `users` (`id`)  VALUES (?),(?)', $q->getLastPrepareQuery());
        self::assertSame([1, 2], $q->getLastBindParams());
        $this->expectException(\InvalidArgumentException::class);
        $q->insertAll('users', [['discard' => 1]], ['field' => ['id']]);
    }

    public function testWhitelistPreservesFirstRowFieldOrderNullExpressionsAndOuterRowOrder(): void
    {
        $q = (new QueryBuilder())->onDuplicate(['name' => new Expression('UPPER(?)', ['fixed'])])
            ->insertAll('users', [
                'first' => ['id' => 1, 'discard_a' => 'a', 'name' => null],
                'second' => ['name' => new Expression('CONCAT(?, ?)', ['a', 'b']), 'discard_b' => 'b', 'id' => 2],
            ], ['field' => ['name', 'id']]);
        self::assertSame('INSERT  INTO `users` (`id`, `name`)  VALUES (?, ?),(?, CONCAT(?, ?)) ON DUPLICATE KEY UPDATE `name` = UPPER(?)', $q->getLastPrepareQuery());
        self::assertSame([1, null, 2, 'a', 'b', 'fixed'], $q->getLastBindParams());

        $q->insertAll('users', [
            3 => ['id' => 1, 'name' => null, 'discard_a' => 10],
            8 => ['discard_b' => 20, 'name' => 'next', 'id' => 2],
        ], ['field' => ['id', 'name'], 'replace' => true]);
        self::assertSame('REPLACE  INTO `users` (`id`, `name`)  VALUES (?, ?),(?, ?)', $q->getLastPrepareQuery());
        self::assertSame([1, null, 2, 'next'], $q->getLastBindParams());
    }

    public function testNestedRowsThroughInsertAndReplaceAreAlignedAtTheCompilerBoundary(): void
    {
        foreach (['insert' => 'INSERT', 'replace' => 'REPLACE'] as $method => $keyword) {
            $q = (new QueryBuilder())->{$method}('users', [
                ['id' => 1, 'name' => null], ['name' => new Expression('LOWER(?)', ['NEXT']), 'id' => 2],
            ]);
            self::assertSame($keyword . '  INTO `users` (`id`, `name`)  VALUES (?, ?),(?, LOWER(?))', $q->getLastPrepareQuery());
            self::assertSame([1, null, 2, 'NEXT'], $q->getLastBindParams());
            try {
                (new QueryBuilder())->{$method}('users', [['id' => 1], ['id' => 2, 'name' => 'unexpected']]);
                self::fail('Mismatched rows must not silently discard a column');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('same columns', $error->getMessage());
            }
        }
    }

    public function testExplicitDuplicateColumnNamesTreatDotsAsLiteralCharacters(): void
    {
        $q = (new QueryBuilder())->upsert('users', [['a.b' => 1], ['a.b' => 2]], ['a.b' => 3]);
        self::assertStringEndsWith('ON DUPLICATE KEY UPDATE `a.b` = ?', $q->getLastPrepareQuery());
        self::assertSame([1, 2, 3], $q->getLastBindParams());
        $q->onDuplicate(['a.b' => 4])->insert('users', ['a.b' => 1]);
        self::assertStringEndsWith('ON DUPLICATE KEY UPDATE `a.b` = ?', $q->getLastPrepareQuery());
        self::assertSame([1, 4], $q->getLastBindParams());
        $q->upsert('users', [['1e2' => 1]], ['1e2' => 2]);
        self::assertStringEndsWith('ON DUPLICATE KEY UPDATE `1e2` = ?', $q->getLastPrepareQuery());
        self::assertSame([1, 2], $q->getLastBindParams());
    }
}
