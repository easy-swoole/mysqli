<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Expression, QueryBuilder};
use PHPUnit\Framework\TestCase;

final class QueryBuilderExpressionTest extends TestCase
{
    private function sql(QueryBuilder $builder): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $builder->getLastPrepareQuery()));
        return preg_replace_callback('/\(\s+|\s+\)/', static fn(array $match): string =>
            str_starts_with($match[0], '(') ? '(' : ')', $sql);
    }

    public function testExpressionFactoryRetainsSqlAndBoundValues(): void
    {
        $parameters = ["O'Reilly", 7];
        $expression = QueryBuilder::rawExpression('CONCAT(?, ?) AS label', $parameters);
        self::assertInstanceOf(Expression::class, $expression);
        self::assertSame('CONCAT(?, ?) AS label', $expression->getSql());
        self::assertSame($parameters, $expression->getParameters());
        $parameters[0] = 'changed';
        self::assertSame(["O'Reilly", 7], $expression->getParameters());
        $value = 'before';
        $referenced = new Expression('?', [&$value]);
        $value = 'after';
        self::assertSame(['before'], $referenced->getParameters());
        $copy = $referenced->getParameters();
        $copy[0] = 'changed copy';
        self::assertSame(['before'], $referenced->getParameters());
    }

    public function testSelectRawStartsSelectionAndAppendsWithoutDefaultStar(): void
    {
        $builder = (new QueryBuilder())->selectRaw("CONCAT('?', ?, ?) AS label", ['first', 'second'])
            ->selectRaw('? AS marker', [3])->get('users');
        self::assertSame("SELECT CONCAT('?', ?, ?) AS label, ? AS marker FROM `users`", $this->sql($builder));
        self::assertSame(['first', 'second', 3], $builder->getLastBindParams());
        $builder->fields(['id'])->selectRaw('? AS marker', [4])->get('users');
        self::assertSame('SELECT id, ? AS marker FROM `users`', $this->sql($builder));
        self::assertSame([4], $builder->getLastBindParams());
    }

    public function testExpressionObjectsComposeWithSelectedFields(): void
    {
        $expression = QueryBuilder::rawExpression('COALESCE(name, ?) AS display_name', ['anonymous']);
        $builder = (new QueryBuilder())->fields(['id', $expression])->where('active', true)->get('users');
        self::assertSame('SELECT id, COALESCE(name, ?) AS display_name FROM `users` WHERE `active` = ?', $this->sql($builder));
        self::assertSame(['anonymous', true], $builder->getLastBindParams());
    }

    public function testRawClauseParametersFollowSqlOrderRegardlessOfCallOrder(): void
    {
        $builder = (new QueryBuilder())->orderByRaw('FIELD(status, ?, ?) DESC', ['paid', 'new'])
            ->havingRaw('COUNT(*) > ?', [2])->whereRaw('status <> ?', ['deleted'])
            ->groupBy('status')->selectRaw('COALESCE(status, ?) AS status', ['unknown'])->get('orders');
        self::assertSame(
            'SELECT COALESCE(status, ?) AS status FROM `orders` WHERE (status <> ?) GROUP BY status HAVING (COUNT(*) > ?) ORDER BY FIELD(status, ?, ?) DESC',
            $this->sql($builder)
        );
        self::assertSame(['unknown', 'deleted', 2, 'paid', 'new'], $builder->getLastBindParams());
    }

    public function testRawConditionsBindInjectionTextAndPreserveOrBoundary(): void
    {
        $value = "x' OR 1=1 --";
        $builder = (new QueryBuilder())->where('tenantId', 7)
            ->whereRaw('name = ? OR alias = ?', [$value, $value])
            ->where('active', true)->havingRaw('COUNT(*) > ?', [2])
            ->havingRaw('MAX(score) > ?', [9], 'OR')->get('users');
        self::assertSame(
            'SELECT * FROM `users` WHERE `tenantId` = ? AND (name = ? OR alias = ?) AND `active` = ? HAVING (COUNT(*) > ?) OR (MAX(score) > ?)',
            $this->sql($builder)
        );
        self::assertSame([7, $value, $value, true, 2, 9], $builder->getLastBindParams());
        self::assertStringNotContainsString($value, $builder->getLastPrepareQuery());
    }

    public function testExpressionValuesWorkInInsertUpdateAndComparisons(): void
    {
        $builder = (new QueryBuilder())->insert('users', [
            'name' => QueryBuilder::rawExpression('CONCAT(?, ?)', ['first', 'last']),
            'status' => 'active',
        ]);
        self::assertSame('INSERT INTO `users` (`name`, `status`) VALUES (CONCAT(?, ?), ?)', $this->sql($builder));
        self::assertSame(['first', 'last', 'active'], $builder->getLastBindParams());
        $builder->where('id', 7)->update('users', [
            'score' => QueryBuilder::rawExpression('score + ?', [2]),
            'name' => QueryBuilder::func('CONCAT(?, ?)', ['legacy', 'value']),
        ]);
        self::assertSame('UPDATE `users` SET `score` = score + ?, `name` = CONCAT(?, ?) WHERE `id` = ?', $this->sql($builder));
        self::assertSame([2, 'legacy', 'value', 7], $builder->getLastBindParams());
        $builder->where('score', QueryBuilder::rawExpression('GREATEST(?, baseline)', [10]), '>')->get('users');
        self::assertSame('SELECT * FROM `users` WHERE `score` > GREATEST(?, baseline)', $this->sql($builder));
        self::assertSame([10], $builder->getLastBindParams());
    }

    public function testExpressionsInsideSubqueryAndUnionKeepGlobalBindingOrder(): void
    {
        $subquery = QueryBuilder::subQuery()->selectRaw('COALESCE(id, ?) AS id', [0])
            ->where('active', true)->get('users');
        $branch = (new QueryBuilder())->selectRaw('? AS id', [200])->get('archive');
        $builder = (new QueryBuilder())->orderByRaw('FIELD(id, ?, ?) DESC', [100, 200])
            ->whereRaw('tenantId = ?', [7])->whereIn('userId', $subquery)
            ->union($branch)->selectRaw('id + ? AS id', [100])->get('orders');
        self::assertSame(
            'SELECT id + ? AS id FROM `orders` WHERE (tenantId = ?) AND `userId` IN ((SELECT COALESCE(id, ?) AS id FROM `users` WHERE `active` = ?)) UNION SELECT ? AS id FROM `archive` ORDER BY FIELD(id, ?, ?) DESC',
            $this->sql($builder)
        );
        self::assertSame([100, 7, 0, true, 200, 100, 200], $builder->getLastBindParams());
    }

    public function testRawExpressionsInGroupsAndBuilderReuseDoNotLeakBindings(): void
    {
        $builder = (new QueryBuilder())->selectRaw('? AS selected', ['selected'])
            ->whereGroup(static function (QueryBuilder $group): void {
                $group->whereRaw('a = ?', [1])->whereRaw('b = ?', [2], 'OR');
            })->orderByRaw('FIELD(a, ?) ASC', [3])->get('items');
        self::assertSame('SELECT ? AS selected FROM `items` WHERE ((a = ?) OR (b = ?)) ORDER BY FIELD(a, ?) ASC', $this->sql($builder));
        self::assertSame(['selected', 1, 2, 3], $builder->getLastBindParams());
        $completed = $builder->getLastPrepareQuery();
        $builder->whereRaw('next = ?', [4]);
        self::assertSame($completed, $builder->getLastPrepareQuery());
        self::assertSame(['selected', 1, 2, 3], $builder->getLastBindParams());
        $builder->get('items');
        self::assertSame('SELECT * FROM `items` WHERE (next = ?)', $this->sql($builder));
        self::assertSame([4], $builder->getLastBindParams());
        $builder->reset()->get('items');
        self::assertSame('SELECT * FROM `items`', $this->sql($builder));
        self::assertSame([], $builder->getLastBindParams());
    }

    public function testWholeQueryRawStillDiscardsUncompiledLocalExpressions(): void
    {
        $builder = (new QueryBuilder())->selectRaw('? AS unused', [999])->whereRaw('id = ?', [77])
            ->raw("SELECT '?' AS literal, ? AS value", ['safe']);
        self::assertSame("SELECT '?' AS literal, ? AS value", $builder->getLastPrepareQuery());
        self::assertSame(['safe'], $builder->getLastBindParams());
        $builder->get('users');
        self::assertSame('SELECT * FROM `users`', $this->sql($builder));
        self::assertSame([], $builder->getLastBindParams());
    }

    public function testInvalidExpressionsRejectSqlAndNonScalarParameters(): void
    {
        foreach ([['', []], ['   ', []], ["bad\0sql", []], ['?', [[]]], ['?', [new \stdClass()]]] as [$sql, $parameters]) {
            try {
                new Expression($sql, $parameters);
                self::fail('Expected invalid expression to be rejected');
            } catch (\InvalidArgumentException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        self::assertSame([null, false, 0, 1.5, ''], (new Expression('?,?,?,?,?', [null, false, 0, 1.5, '']))->getParameters());
    }

    public function testColumnHelpersAcceptExpressionsWithoutDiscardingTheirBindings(): void
    {
        $builder = (new QueryBuilder())->getScalar('users', new Expression('COALESCE(name, ?)', ['anonymous']));
        self::assertSame('SELECT COALESCE(name, ?) FROM `users` LIMIT 1', $this->sql($builder));
        self::assertSame(['anonymous'], $builder->getLastBindParams());
        $builder->selectRaw('COALESCE(name, ?)', ['guest'])->getColumn('users');
        self::assertSame('SELECT COALESCE(name, ?) FROM `users`', $this->sql($builder));
        self::assertSame(['guest'], $builder->getLastBindParams());
    }
}
