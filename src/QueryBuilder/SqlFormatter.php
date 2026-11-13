<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 生成调试展示 SQL，不修改可执行 SQL 或参数。 */
final class SqlFormatter
{
    public static function render(string $str, array $vals): ?string
    {
        return self::renderWithQuoteMode($str, $vals, false);
    }

    /** @internal 调试 SQL 使用构建时的引号规则。 */
    public static function renderWithQuoteMode(string $str, array $vals, bool $noBackslashEscapes): ?string
    {
        return self::renderForMode($str, $vals, $noBackslashEscapes, false);
    }

    public static function renderForMode(string $str, array $vals, bool $noBackslashEscapes, bool $ansiQuotes): string
    {
        $index = 0;
        $start = 0;
        $result = '';
        foreach (SqlScanner::tokensForMode($str, '?', $noBackslashEscapes, $ansiQuotes, skipLineComments: true) as $offset => $_) {
            if (!array_key_exists($index, $vals)) { break; }
            $result .= substr($str, $start, $offset - $start) . self::literal($vals[$index++], $noBackslashEscapes);
            $start = $offset + 1;
        }
        return $result . substr($str, $start);
    }

    private static function literal(mixed $value, bool $noBackslashEscapes): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return (string)(int)$value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . ($noBackslashEscapes ? str_replace("'", "''", $value) : addslashes($value)) . "'";
    }
}
