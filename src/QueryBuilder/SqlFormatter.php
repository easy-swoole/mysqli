<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 生成调试展示 SQL，不修改可执行 SQL 或参数。 */
final class SqlFormatter
{
    public static function renderForMode(string $str, array $vals, bool $noBackslashEscapes, bool $ansiQuotes): string
    {
        $index = 0;
        $start = 0;
        $result = '';
        foreach (SqlScanner::tokensForMode($str, '?', $noBackslashEscapes, $ansiQuotes) as $offset => $_) {
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
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_float($value)) {
            // 17 位有效数字可往返还原双精度值，指数写法让 MySQL 按浮点字面量解析。
            $literal = sprintf('%.17H', $value);
            return str_contains($literal, 'E') ? $literal : $literal . 'E0';
        }
        return "'" . ($noBackslashEscapes ? str_replace("'", "''", $value) : addslashes($value)) . "'";
    }
}
