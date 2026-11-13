<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 解析 SQL 列表时，不拆分已引用文本、块注释或嵌套表达式。 */
final class SqlList
{
    /** 原样包裹 SQL。 */
    public static function parenthesized(string $sql): string
    {
        return '(' . $sql . ')';
    }

    /**
     * 仅在顶层逗号处分隔，并保留每个表达式的原始文本。
     * @return list<string>
     */
    public static function items(string $sql): array
    {
        return self::splitItems($sql, false);
    }

    /** @internal 按调用方指定的反斜杠规则拆分列表。 */
    public static function splitItems(string $sql, bool $noBackslashEscapes): array
    {
        $items = [];
        $start = 0;
        foreach (self::separators($sql, $noBackslashEscapes) as $offset) {
            $items[] = substr($sql, $start, $offset - $start);
            $start = $offset + 1;
        }
        if ($start < strlen($sql)) {
            $items[] = substr($sql, $start);
        }
        return $items;
    }

    /** 保留首个顶层列表项及其原有空白和别名。 */
    public static function first(string $sql): string
    {
        return self::firstItem($sql, false);
    }

    /** @internal 按调用方指定的反斜杠规则读取首项。 */
    public static function firstItem(string $sql, bool $noBackslashEscapes): string
    {
        foreach (self::separators($sql, $noBackslashEscapes) as $offset) {
            return substr($sql, 0, $offset);
        }
        return $sql;
    }

    /** @return \Generator<int, int> */
    private static function separators(string $sql, bool $noBackslashEscapes): \Generator
    {
        $depth = 0;
        foreach (SqlScanner::tokens($sql, '(),', $noBackslashEscapes) as $offset => $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0) {
                yield $offset;
            }
        }
    }
}
