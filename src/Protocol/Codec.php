<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;

final class Codec
{
    public static function int1(string $data, int &$offset): int
    {
        return ord($data[$offset++]);
    }

    public static function int2(string $data, int &$offset): int
    {
        $value = unpack('v', substr($data, $offset, 2))[1];
        $offset += 2;
        return $value;
    }

    public static function int3(string $data, int &$offset): int
    {
        $value = ord($data[$offset]) | (ord($data[$offset + 1]) << 8) | (ord($data[$offset + 2]) << 16);
        $offset += 3;
        return $value;
    }

    public static function int4(string $data, int &$offset): int
    {
        $value = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        return $value;
    }

    public static function nullTerminated(string $data, int &$offset): string
    {
        $end = strpos($data, "\0", $offset);
        if ($end === false) {
            $end = strlen($data);
        }
        $value = substr($data, $offset, $end - $offset);
        $offset = min($end + 1, strlen($data));
        return $value;
    }

    /**
     * 读取 MySQL 长度编码整数；NULL 标记返回 null。
     * 其中无符号 64 位数值超过 PHP_INT_MAX 时返回十进制字符串，保留完整数值。
     */
    public static function lenencInt(string $data, int &$offset): int|string|null
    {
        $first = self::int1($data, $offset);
        return match ($first) {
            0xfb => null,
            0xfc => self::int2($data, $offset),
            0xfd => self::int3($data, $offset),
            0xfe => self::int8($data, $offset),
            default => $first,
        };
    }

    /**
     * 读取小端无符号 64 位整数，并推进偏移量。
     * 不超过 PHP_INT_MAX 时返回 int，超过时返回精确的十进制字符串，避免溢出和浮点精度丢失。
     * 使用整数与十进制运算，不依赖 BCMath 或 GMP；按当前 PHP 的整数范围判断返回类型。
     */
    public static function int8(string $data, int &$offset): int|string
    {
        $bytes = substr($data, $offset, 8);
        if (strlen($bytes) !== 8) {
            throw new Exception('Malformed MySQL unsigned 64-bit integer');
        }
        $offset += 8;
        $value = 0;
        for ($i = 7; $i >= 0; $i--) {
            $byte = ord($bytes[$i]);
            if (is_int($value) && $value <= intdiv(PHP_INT_MAX - $byte, 256)) {
                $value = $value * 256 + $byte;
                continue;
            }
            // 使用十进制逐位运算保留完整数值，不经过浮点转换，也无需额外扩展。
            $digits = (string) $value;
            $carry = $byte;
            for ($j = strlen($digits) - 1; $j >= 0; $j--) {
                $product = ((int) $digits[$j]) * 256 + $carry;
                $digits[$j] = (string) ($product % 10);
                $carry = intdiv($product, 10);
            }
            $value = ($carry === 0 ? '' : (string) $carry) . $digits;
        }
        return $value;
    }

    public static function lenencString(string $data, int &$offset): ?string
    {
        $length = self::lenencInt($data, $offset);
        if ($length === null) {
            return null;
        }
        if (!is_int($length) || $length < 0 || $length > strlen($data) - $offset) {
            throw new Exception('Malformed MySQL length-encoded string');
        }
        $value = substr($data, $offset, $length);
        $offset += $length;
        return $value;
    }

    public static function encodeLenencInt(int $value): string
    {
        if ($value < 0xfb) {
            return chr($value);
        }
        if ($value <= 0xffff) {
            return "\xfc" . pack('v', $value);
        }
        if ($value <= 0xffffff) {
            return "\xfd" . substr(pack('V', $value), 0, 3);
        }
        return "\xfe" . pack('V2', $value & 0xffffffff, intdiv($value, 4294967296));
    }

    public static function encodeLenencString(string $value): string
    {
        return self::encodeLenencInt(strlen($value)) . $value;
    }

    public static function xorBytes(string $left, string $right): string
    {
        $result = '';
        $length = strlen($left);
        for ($i = 0; $i < $length; $i++) {
            $result .= $left[$i] ^ $right[$i % strlen($right)];
        }
        return $result;
    }
}
