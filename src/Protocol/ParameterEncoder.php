<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

/** Keep string parameters as separate parts instead of copying a full execute payload. */
final class ParameterEncoder
{
    /**
     * 生成预处理参数的空值位图、类型及分段数据；字符串正文保留原引用。
     */
    public static function encode(array $parameters): array
    {
        $bitmap = str_repeat("\0", intdiv(count($parameters) + 7, 8));
        $types = '';
        $values = [];
        foreach (array_values($parameters) as $index => $value) {
            if ($value === null) {
                $byte = intdiv($index, 8);
                $bitmap[$byte] = chr(ord($bitmap[$byte]) | (1 << ($index % 8)));
                $types .= chr(Column::TYPE_NULL) . "\0";
            } elseif (is_bool($value)) {
                $types .= chr(Column::TYPE_TINY) . "\0";
                $values[] = chr($value ? 1 : 0);
            } elseif (is_int($value)) {
                $types .= chr(Column::TYPE_LONGLONG) . "\0";
                $values[] = pack('P', $value);
            } elseif (is_float($value)) {
                $types .= chr(Column::TYPE_DOUBLE) . "\0";
                $values[] = pack('e', $value);
            } elseif (is_string($value)) {
                $types .= chr(Column::TYPE_VAR_STRING) . "\0";
                $values[] = Codec::encodeLenencInt(strlen($value));
                $values[] = $value;
            } else {
                throw new \InvalidArgumentException('Unsupported bind parameter type: ' . get_debug_type($value));
            }
        }
        return [$bitmap, $types, $values];
    }
}
