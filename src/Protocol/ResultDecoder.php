<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\ProtocolException;

/** Decode complete result packets without owning sockets or connection state. */
final class ResultDecoder
{
    /**
     * 解析结果字段的名称、类型及标志。
     */
    public static function parseColumn(string $packet): Column
    {
        $offset = 0;
        for ($i = 0; $i < 4; $i++) {
            Codec::lenencString($packet, $offset);
        }
        $name = Codec::lenencString($packet, $offset) ?? '';
        Codec::lenencString($packet, $offset);
        Codec::lenencInt($packet, $offset);
        Codec::int2($packet, $offset);
        Codec::int4($packet, $offset);
        $type = Codec::int1($packet, $offset);
        $flags = Codec::int2($packet, $offset);
        return new Column($name, $type, $flags, Codec::int1($packet, $offset));
    }

    /**
     * 将文本协议结果行解析为关联数组。
     */
    public static function parseTextRow(string $packet, array $columns): array
    {
        $offset = 0;
        $row = [];
        foreach ($columns as $column) {
            $value = Codec::lenencString($packet, $offset);
            $row[$column->name] = self::castTextValue($value, $column);
        }
        return $row;
    }

    /**
     * 按字段类型转换文本值，并保留超大无符号整数。
     */
    private static function castTextValue(?string $value, Column $column): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($column->type) {
            Column::TYPE_TINY,
            Column::TYPE_SHORT,
            Column::TYPE_LONG,
            Column::TYPE_LONGLONG,
            Column::TYPE_INT24,
            Column::TYPE_YEAR => self::integerValue($value, $column->isUnsigned()),
            Column::TYPE_FLOAT, Column::TYPE_DOUBLE => (float) $value,
            default => $value,
        };
    }

    /**
     * 将二进制协议结果行解析为关联数组。
     */
    public static function parseBinaryRow(string $packet, array $columns): array
    {
        if (($packet[0] ?? '') !== "\0") {
            throw new ProtocolException('Malformed binary result row');
        }
        $offset = 1;
        $bitmapLength = intdiv(count($columns) + 9, 8);
        Codec::requireBytes($packet, $offset, $bitmapLength);
        $bitmap = substr($packet, $offset, $bitmapLength);
        $offset += $bitmapLength;
        $row = [];
        foreach ($columns as $index => $column) {
            $bit = $index + 2;
            if ((ord($bitmap[intdiv($bit, 8)]) & (1 << ($bit % 8))) !== 0) {
                $row[$column->name] = null;
                continue;
            }
            $row[$column->name] = self::decodeBinaryValue($packet, $offset, $column);
        }
        return $row;
    }

    /**
     * 按字段类型解码单个二进制值。
     */
    private static function decodeBinaryValue(string $packet, int &$offset, Column $column): mixed
    {
        return match ($column->type) {
            Column::TYPE_TINY => self::unpackInteger($packet, $offset, 1, $column->isUnsigned()),
            Column::TYPE_SHORT, Column::TYPE_YEAR => self::unpackInteger($packet, $offset, 2, $column->isUnsigned()),
            Column::TYPE_LONG, Column::TYPE_INT24 => self::unpackInteger($packet, $offset, 4, $column->isUnsigned()),
            Column::TYPE_LONGLONG => self::unpackInteger($packet, $offset, 8, $column->isUnsigned()),
            Column::TYPE_FLOAT => self::unpackFloat($packet, $offset, 4),
            Column::TYPE_DOUBLE => self::unpackFloat($packet, $offset, 8),
            Column::TYPE_TIMESTAMP,
            Column::TYPE_DATE,
            Column::TYPE_TIME,
            Column::TYPE_DATETIME => self::unpackTemporal($packet, $offset, $column),
            default => Codec::lenencString($packet, $offset),
        };
    }

    /**
     * 解码二进制整数，超大无符号值返回字符串。
     */
    private static function unpackInteger(string $packet, int &$offset, int $bytes, bool $unsigned): int|string
    {
        Codec::requireBytes($packet, $offset, $bytes);
        $raw = substr($packet, $offset, $bytes);
        $offset += $bytes;
        if ($bytes === 1) {
            $value = ord($raw);
            return !$unsigned && $value >= 128 ? $value - 256 : $value;
        }
        if ($bytes === 2) {
            $value = unpack('v', $raw)[1];
            return !$unsigned && $value >= 0x8000 ? $value - 0x10000 : $value;
        }
        if ($bytes === 4) {
            $value = unpack('V', $raw)[1];
            return !$unsigned && $value >= 0x80000000 ? $value - 0x100000000 : $value;
        }
        $value = unpack('P', $raw)[1];
        if (!$unsigned || $value >= 0) {
            return $value;
        }
        // PHP stores the bit pattern as a signed integer. %u converts that
        // exact pattern to decimal without passing through a float.
        return sprintf('%u', $value);
    }

    /**
     * 解码小端单精度或双精度浮点数。
     */
    private static function unpackFloat(string $packet, int &$offset, int $bytes): float
    {
        Codec::requireBytes($packet, $offset, $bytes);
        $raw = substr($packet, $offset, $bytes);
        $offset += $bytes;
        return unpack($bytes === 4 ? 'g' : 'e', $raw)[1];
    }

    /**
     * 将二进制日期时间按字段精度转换为与文本协议一致的字符串。
     */
    private static function unpackTemporal(string $packet, int &$offset, Column $column): string
    {
        $length = Codec::int1($packet, $offset);
        $type = $column->type;
        $validLengths = $type === Column::TYPE_TIME ? [0, 8, 12] : [0, 4, 7, 11];
        if (!in_array($length, $validLengths, true) || strlen($packet) - $offset < $length) {
            throw new ProtocolException('Malformed binary temporal value');
        }
        $start = $offset;
        $microseconds = 0;
        if ($type === Column::TYPE_TIME) {
            $negative = false;
            $hours = $minutes = $seconds = 0;
            if ($length !== 0) {
                $negative = Codec::int1($packet, $offset) === 1;
                $days = Codec::int4($packet, $offset);
                $hours = Codec::int1($packet, $offset) + ($days * 24);
                $minutes = Codec::int1($packet, $offset);
                $seconds = Codec::int1($packet, $offset);
                $microseconds = $length === 12 ? Codec::int4($packet, $offset) : 0;
            }
            $value = sprintf('%s%02d:%02d:%02d', $negative ? '-' : '', $hours, $minutes, $seconds);
        } else {
            $year = $month = $day = $hour = $minute = $second = 0;
            if ($length !== 0) {
                $year = Codec::int2($packet, $offset);
                $month = Codec::int1($packet, $offset);
                $day = Codec::int1($packet, $offset);
                if ($length >= 7) {
                    $hour = Codec::int1($packet, $offset);
                    $minute = Codec::int1($packet, $offset);
                    $second = Codec::int1($packet, $offset);
                }
                $microseconds = $length === 11 ? Codec::int4($packet, $offset) : 0;
            }
            $value = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($type !== Column::TYPE_DATE) {
                $value .= sprintf(' %02d:%02d:%02d', $hour, $minute, $second);
            }
        }
        $offset = $start + $length;
        // 精度来自字段元数据，零小数也保留对应位数，避免随二进制包长度变化。
        if ($type !== Column::TYPE_DATE && $column->decimals > 0 && $column->decimals <= 6) {
            $value .= '.' . substr(sprintf('%06d', $microseconds), 0, $column->decimals);
        }
        return $value;
    }

    /**
     * 转换文本整数，超大无符号值保留为字符串。
     */
    private static function integerValue(string $value, bool $unsigned): int|string
    {
        if ($unsigned && self::compareUnsignedDecimal($value, (string) PHP_INT_MAX) > 0) {
            return $value;
        }
        return (int) $value;
    }

    /**
     * 比较两个无符号十进制整数字符串的大小。
     */
    private static function compareUnsignedDecimal(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }}
