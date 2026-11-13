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
        $i = 0;
        // 先匹配已引用的字面量及注释，再匹配实际占位符。
        $strings = $noBackslashEscapes
            ? <<<'REGEX'
'(?:''|[^'])*'|"(?:""|[^"])*"
REGEX
            : <<<'REGEX'
'(?:\\.|''|[^'\\])*'|"(?:\\.|""|[^"\\])*"
REGEX;
        $pattern = '~' . $strings . <<<'REGEX'
|`(?:``|[^`])*`|/\*[\s\S]*?\*/|--[ \t][^\r\n]*|\#[^\r\n]*|\?~
REGEX;
        return preg_replace_callback($pattern, static function (array $match) use ($vals, &$i, $noBackslashEscapes): string {
            if ($match[0] !== '?' || !array_key_exists($i, $vals)) {
                return $match[0];
            }
            $val = $vals[$i++];
            if ($val === null) {
                return 'NULL';
            }
            if (is_bool($val)) {
                return (string)(int)$val;
            }
            if (is_int($val) || is_float($val)) {
                return (string)$val;
            }
            return "'" . ($noBackslashEscapes ? str_replace("'", "''", $val) : addslashes($val)) . "'";
        }, $str);
    }
}
