<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 生成调试展示 SQL，不修改可执行 SQL 或参数。 */
final class SqlFormatter
{
    /** @return string|array<array-key, string>|null 保留 preg_replace_callback 输入与结果的类型形状。 */
    public static function render(mixed $str, mixed $vals): string|array|null
    {
        $i = 0;
        // 先匹配已引用的字面量及注释，再匹配实际占位符。
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
