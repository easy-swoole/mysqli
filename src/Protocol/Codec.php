<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

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

    public static function lenencInt(string $data, int &$offset): ?int
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

    public static function int8(string $data, int &$offset): int
    {
        $parts = unpack('Vlow/Vhigh', substr($data, $offset, 8));
        $offset += 8;
        return (int) ($parts['low'] + ($parts['high'] * 4294967296));
    }

    public static function lenencString(string $data, int &$offset): ?string
    {
        $length = self::lenencInt($data, $offset);
        if ($length === null) {
            return null;
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
