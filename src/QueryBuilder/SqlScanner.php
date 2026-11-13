<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 按原始字符串偏移查找引号和注释之外的指定字符。 */
final class SqlScanner
{
    /** @return \Generator<int, string> */
    public static function tokens(
        string $sql,
        string $tokens,
        bool $noBackslashEscapes,
        bool $skipLineComments = false,
    ): \Generator {
        $special = $tokens . "'\"`/" . ($skipLineComments ? '-#' : '');
        $length = strlen($sql);
        for ($offset = 0; $offset < $length; $offset++) {
            $offset += strcspn($sql, $special, $offset);
            if ($offset === $length) { break; }
            $character = $sql[$offset];
            $next = $sql[$offset + 1] ?? null;
            if ($character === "'" || $character === '"' || $character === '`') {
                $offset = self::quoteEnd($sql, $offset, $character, !$noBackslashEscapes && $character !== '`');
            } elseif ($character === '/' && $next === '*') {
                $end = strpos($sql, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 1;
            } elseif ($skipLineComments && ($character === '#' || ($character === '-' && $next === '-'
                && in_array($sql[$offset + 2] ?? null, [' ', "\t"], true)))) {
                $offset += strcspn($sql, "\r\n", $offset);
            } elseif (str_contains($tokens, $character)) {
                yield $offset => $character;
            }
        }
    }

    private static function quoteEnd(string $sql, int $offset, string $quote, bool $backslashEscapes): int
    {
        $special = $backslashEscapes ? $quote . '\\' : $quote;
        $length = strlen($sql);
        for ($offset++; $offset < $length; $offset += 2) {
            $offset += strcspn($sql, $special, $offset);
            if ($offset === $length || ($sql[$offset] === $quote && ($sql[$offset + 1] ?? null) !== $quote)) {
                return $offset;
            }
        }
        return $length;
    }
}
