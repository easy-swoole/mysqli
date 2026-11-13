<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 解析 SQL 列表时，不拆分已引用文本或嵌套表达式。 */
final class SqlList
{
    /** 原样包裹 SQL。 */
    public static function parenthesized(string $sql): string
    {
        return '(' . $sql . ')';
    }

    /** 保留首个顶层列表项及其原有空白和别名。 */
    public static function firstForMode(string $sql, bool $noBackslashEscapes, bool $ansiQuotes): string
    {
        $depth = 0;
        foreach (SqlScanner::tokensForMode($sql, '(),', $noBackslashEscapes, $ansiQuotes) as $offset => $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0) {
                return substr($sql, 0, $offset);
            }
        }
        return $sql;
    }
}
