<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Read SQL lists without splitting quoted text, comments or nested expressions. */
final class SqlList
{
    /** Keep a trailing SQL line comment from swallowing a closing parenthesis. */
    public static function parenthesized(string $sql): string
    {
        return '(' . implode(',', self::items($sql)) . ')';
    }

    /**
     * Split only top-level commas, retaining every expression's original text.
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

    /** Keep the first top-level item, including its original whitespace and aliases. */
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
        // Terminate a trailing line comment before the caller appends another SQL clause.
        return $lineComment ? $sql . "\n" : $sql;
    }
}
