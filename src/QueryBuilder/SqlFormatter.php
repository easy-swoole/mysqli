<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Render diagnostic SQL without changing executable SQL or parameters. */
final class SqlFormatter
{
    /** @return string|array<array-key, string>|null Preserve preg_replace_callback's subject/result shape. */
    public static function render(mixed $str, mixed $vals): string|array|null
    {
        $i = 0;
        // Match quoted literals and comments before matching actual placeholders.
        $pattern = <<<'REGEX'
~'(?:\\.|''|[^'\\])*'|"(?:\\.|""|[^"\\])*"|`(?:``|[^`])*`|/\*[\s\S]*?\*/|--[ \t][^\r\n]*|\#[^\r\n]*|\?~
REGEX;
        return preg_replace_callback($pattern, static function (array $match) use ($vals, &$i): string {
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
            return "'" . addslashes(is_object($val) ? '[object]' : $val) . "'";
        }, $str);
    }
}
