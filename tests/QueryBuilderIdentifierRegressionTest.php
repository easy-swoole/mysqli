<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Expression, QueryBuilder};
use EasySwoole\Mysqli\QueryBuilder\{SqlList, TableReference};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryBuilderIdentifierRegressionTest extends TestCase
{
    private function sql(QueryBuilder $builder): string
    {
        return trim(preg_replace('/\s+/', ' ', $builder->getLastPrepareQuery()));
    }

    public function testDebugSqlUsesTheQuoteModeSavedAtBuildTime(): void
    {
        $sql = <<<'SQL'
SELECT CONCAT('x\', '?'), ? AS id, ? AS label
SQL;
        $builder = (new QueryBuilder())->noBackslashEscapes()->raw($sql, [42, "O'Reilly\\"]);
        $builder->noBackslashEscapes(false);
        self::assertSame(<<<'SQL'
SELECT CONCAT('x\', '?'), 42 AS id, 'O''Reilly\' AS label
SQL, $builder->getLastQuery());
        self::assertSame($sql, $builder->getLastPrepareQuery());
        self::assertSame([42, "O'Reilly\\"], $builder->getLastBindParams());
    }

    public function testDebugSqlAndListSplittingShareQuotedBoundaries(): void
    {
        $literal = "'prefix" . chr(92) . "\n" . "suffix,?''x'";
        $list = $literal . ', ?';
        self::assertSame($literal, SqlList::firstForMode($list, false, false));
        $builder = (new QueryBuilder())->raw('SELECT ' . $list, [42]);
        self::assertSame('SELECT ' . $literal . ', 42', $builder->getLastQuery());
        self::assertSame('SELECT ' . $list, $builder->getLastPrepareQuery());
        self::assertSame([42], $builder->getLastBindParams());

        $literal = "'" . str_repeat('a', 100000) . "?', `label?`";
        $builder->raw('SELECT ' . $literal . ', ?', [42]);
        self::assertSame('SELECT ' . $literal . ', 42', $builder->getLastQuery());
    }

    public function testAnsiQuotesKeepsIdentifierBoundariesAndStringEscapesIndependent(): void
    {
        $identifier = '"label?' . chr(92) . '"';
        $string = "'it" . chr(92) . "'s?'";
        $sql = "SELECT 1 AS $identifier, $string, ? AS answer";
        $builder = (new QueryBuilder())->ansiQuotes()->raw($sql, [42])->ansiQuotes(false);
        self::assertSame("SELECT 1 AS $identifier, $string, 42 AS answer", $builder->getLastQuery());
        self::assertSame($sql, $builder->getLastPrepareQuery());
        $builder->ansiQuotes()->fields("$identifier, id")->getColumn('orders');
        self::assertSame("SELECT  $identifier FROM `orders`", $builder->getLastPrepareQuery());
        $builder->fields(["1 AS $identifier", 'id'])->groupBy("$identifier, id")->count('orders');
        self::assertStringContainsString("SELECT  1 AS $identifier, id FROM", $builder->getLastPrepareQuery());
    }

    #[DataProvider('tableReferences')]
    public function testTableReferencesPreservePrefixAndAliasRules(string $table, string $prefix, string $expected): void
    {
        self::assertSame($expected, TableReference::parse($table, $prefix));
    }

    public static function tableReferences(): array
    {
        return [
            ['users', '', '`users`'],
            ['users', 'app_', '`app_users`'],
            ['users u', 'app_', 'app_users u'],
            ['users AS u', 'app_', 'app_users AS u'],
            ['db.users', 'app_', '`db`.`users`'],
            ['db.users u', 'app_', 'db.users u'],
            ['users', 'db.', '`db`.`users`'],
            ['users u', 'db.app_', 'db.app_users u'],
            ['`users`', 'db.app_', '`db`.`app_users`'],
            ['`order`', 'app_', '`app_order`'],
            ['`user``data`', 'app_', '`app_user``data`'],
            ['`order` AS `o`', 'app_', '`app_order` AS `o`'],
            ['`db`.`users`', 'app_', '`db`.`users`'],
            ['`db`.users', 'app_', '`db`.`users`'],
            ['db.`users` u', 'app_', '`db`.`users` u'],
            ['`db` . `users` u', 'app_', '`db`.`users` u'],
            ['`event.log`', 'app_', '`app_event.log`'],
            ['(SELECT 1 AS id) u', 'app_', '(SELECT 1 AS id) u'],
            ["JSON_TABLE('[1]', '$[*]' COLUMNS(id INT PATH '$')) j", 'app_', "JSON_TABLE('[1]', '$[*]' COLUMNS(id INT PATH '$')) j"],
            ['users FORCE INDEX (idx_user)', 'app_', 'app_users FORCE INDEX (idx_user)'],
            ['`users` FORCE INDEX (idx_user)', 'app_', '`app_users` FORCE INDEX (idx_user)'],
            ['`db`.`users` PARTITION (p0) u', 'app_', '`db`.`users` PARTITION (p0) u'],
        ];
    }

    public function testQuotedTablesWorkWithPrefixesAcrossReadsAndWrites(): void
    {
        $builder = (new QueryBuilder())->setPrefix('app_')->get('`order`');
        self::assertSame('SELECT * FROM `app_order`', $this->sql($builder));
        $builder->where('id', 1)->update('`order`', ['name' => 'Alice']);
        self::assertSame('UPDATE `app_order` SET `name` = ? WHERE `id` = ?', $this->sql($builder));
        self::assertSame(['Alice', 1], $builder->getLastBindParams());
        $builder->where('id', 2)->delete('`order`');
        self::assertSame('DELETE FROM `app_order` WHERE `id` = ?', $this->sql($builder));
        $builder->insert('`order`', ['name' => 'Bob']);
        self::assertStringStartsWith('INSERT INTO `app_order`', $this->sql($builder));
        self::assertSame(['Bob'], $builder->getLastBindParams());
    }

    public function testUpdateColumnsQuoteEveryQualifierAndDecodeExistingQuotes(): void
    {
        $builder = (new QueryBuilder())->where('u.id', 1)->update('users u', [
            '`u`.`name`' => 'Alice',
            'app.users.status' => 'active',
            '`odd.name`' => 'literal dot',
            '`odd``column`' => 'literal backtick',
        ]);
        self::assertSame(
            'UPDATE users u SET `u`.`name` = ?, `app`.`users`.`status` = ?, `odd.name` = ?, `odd``column` = ? WHERE `u`.`id` = ?',
            $this->sql($builder)
        );
        self::assertSame(['Alice', 'active', 'literal dot', 'literal backtick', 1], $builder->getLastBindParams());
    }

    public function testLegacySelfColumnExpressionsUseTheSameQuotedReference(): void
    {
        $builder = (new QueryBuilder())->update('users u', [
            'u.order' => QueryBuilder::inc(),
            '`u`.`count`' => QueryBuilder::dec(2),
            '`u`.`visible`' => QueryBuilder::not(),
        ]);
        self::assertSame(
            'UPDATE users u SET `u`.`order` = `u`.`order` + 1, `u`.`count` = `u`.`count` - 2, `u`.`visible` = !`u`.`visible`',
            $this->sql($builder)
        );
    }

    public function testWhereLikeUsesTheSameQuotedIdentifierRulesAsOtherPredicates(): void
    {
        $builder = (new QueryBuilder())->whereLike('`u`.`odd.name`', '%Alice%')->get('users u');
        self::assertSame('SELECT * FROM users u WHERE `u`.`odd.name` LIKE ?', $this->sql($builder));
        self::assertSame(['%Alice%'], $builder->getLastBindParams());
    }

    #[DataProvider('sqlLists')]
    public function testFirstSqlListItemIgnoresNestedAndQuotedCommas(string $list, string $expected): void
    {
        self::assertSame($expected, SqlList::firstForMode($list, false, false));
    }

    public static function sqlLists(): array
    {
        return [
            ['name AS display_name, id', 'name AS display_name'],
            ["COALESCE(name, 'guest') AS display_name, id", "COALESCE(name, 'guest') AS display_name"],
            ['SUM(IF(a IN (1, 2), x, y)) OVER (PARTITION BY a, b) AS total, id', 'SUM(IF(a IN (1, 2), x, y)) OVER (PARTITION BY a, b) AS total'],
            ["CONCAT('a,b', \"c,d\", `odd,name`) AS `display,name`, id", "CONCAT('a,b', \"c,d\", `odd,name`) AS `display,name`"],
            ["'a''b,c' AS label, id", "'a''b,c' AS label"],
            ['"a""b,c" AS label, id', '"a""b,c" AS label'],
            ['`a``b,c` AS label, id', '`a``b,c` AS label'],
            ["'a\\'b,c' AS label, id", "'a\\'b,c' AS label"],
            ['1--not_a_comment, id', '1--not_a_comment'],
            ['  name AS label  , id', '  name AS label  '],
            ['id', 'id'],
        ];
    }

    public function testJoinedDeleteSharesQualifiedTableParsingAndKeepsTheWholeAlias(): void
    {
        $builder = (new QueryBuilder())->setPrefix('app_')
            ->join('archive.orders o', 'o.user_id=`target user`.id')
            ->joinWhere('archive.orders o', 'o.status', 'closed')
            ->delete('users AS `target user`');
        self::assertSame(
            'DELETE `target user` FROM app_users AS `target user` JOIN archive.orders o on (o.user_id=`target user`.id) AND o.status = ?',
            $this->sql($builder)
        );
        self::assertSame(['closed'], $builder->getLastBindParams());
    }

    public function testGroupingAndOrderingKeepQuotedIdentifiersAndAliases(): void
    {
        $builder = (new QueryBuilder())->setPrefix('app_')
            ->fields('`u`.`tenant$id`')->groupBy('`u`.`tenant$id`')->orderByAsc('`u`.`tenant$id`')->get('users u');
        self::assertSame(
            'SELECT `u`.`tenant$id` FROM app_users u GROUP BY `u`.`tenant$id` ORDER BY `u`.`tenant$id` ASC',
            $this->sql($builder)
        );
    }

    public function testColumnHelpersRetainAliasesAndCompleteFunctionArguments(): void
    {
        $builder = (new QueryBuilder())->fields('name AS display_name, id')->getColumn('users');
        self::assertSame('SELECT name AS display_name FROM `users`', $this->sql($builder));
        $builder->fields(["COALESCE(name, 'guest') AS display_name", 'id'])->getScalar('users');
        self::assertSame("SELECT COALESCE(name, 'guest') AS display_name FROM `users` LIMIT 1", $this->sql($builder));
        $builder->fields([new Expression('COALESCE(name, ?) AS display_name', ['guest']), 'id'])->getColumn('users');
        self::assertSame('SELECT COALESCE(name, ?) AS display_name FROM `users`', $this->sql($builder));
        self::assertSame(['guest'], $builder->getLastBindParams());
    }
}
