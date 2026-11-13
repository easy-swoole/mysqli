<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 按原始字符串偏移查找引号之外的指定字符。 */
final class SqlScanner
{
    /** @return \Generator<int, string> */
    public static function tokensForMode(
        string $sql,
        string $tokens,
        bool $noBackslashEscapes,
        bool $ansiQuotes,
    ): \Generator {
        $special = $tokens . "'\"`";
        $length = strlen($sql);
        for ($offset = 0; $offset < $length; $offset++) {
            $offset += strcspn($sql, $special, $offset);
            if ($offset === $length) { break; }
            $character = $sql[$offset];
            if ($character === "'" || $character === '"' || $character === '`') {
                $stringQuote = $character === "'" || ($character === '"' && !$ansiQuotes);
                $offset = self::quoteEnd($sql, $offset, $character, $stringQuote && !$noBackslashEscapes);
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
