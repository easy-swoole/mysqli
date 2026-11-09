<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 解析 SQL 列表时，不拆分已引用文本、注释或嵌套表达式。 */
final class SqlList
{
    /** 避免 SQL 尾部行注释吞掉闭括号。 */
    public static function parenthesized(string $sql): string
    {
        return '(' . implode(',', self::items($sql)) . ')';
    }

    /**
     * 仅在顶层逗号处分隔，并保留每个表达式的原始文本。
     * @return list<string>
     */
    public static function items(string $sql): array
    {
        $items = [];
        while ($sql !== '') {
            $first = self::first($sql);
            $items[] = $first;
            if (strlen($first) >= strlen($sql)) { break; }
            $sql = substr($sql, strlen($first) + 1);
        }
        return $items;
    }

    /** 保留首个顶层列表项及其原有空白和别名。 */
    public static function first(string $sql): string
    {
        $length = strlen($sql);
        $depth = 0;
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        for ($position = 0; $position < $length; $position++) {
            $character = $sql[$position];
            $next = $position + 1 < $length ? $sql[$position + 1] : null;
            if ($lineComment) {
                if ($character === "\n" || $character === "\r") { $lineComment = false; }
                continue;
            }
            if ($blockComment) {
                if ($character === '*' && $next === '/') { $blockComment = false; $position++; }
                continue;
            }
            if ($quote !== null) {
                if ($character === '\\' && $quote !== '`') { $position++; continue; }
                if ($character === $quote) {
                    if ($next === $quote) { $position++; }
                    else { $quote = null; }
                }
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === '/' && $next === '*') {
                $blockComment = true;
                $position++;
            } elseif ($character === '#' || ($character === '-' && $next === '-'
                && ($position + 2 === $length || ord($sql[$position + 2]) <= 32))) {
                $lineComment = true;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($character === ',' && $depth === 0) {
                return substr($sql, 0, $position);
            }
        }
        // 在调用方追加其他 SQL 子句之前结束尾部行注释。
        return $lineComment ? $sql . "\n" : $sql;
    }
}
