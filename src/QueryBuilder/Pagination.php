<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 计算分页元数据，不将无符号计数转换为浮点数。 */
final class Pagination
{
    public static function offset(int $perPage, int $page): int
    {
        if ($perPage < 1 || $page < 1) {
            throw new \InvalidArgumentException('Page and per-page values must be positive');
        }
        if ($page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new \InvalidArgumentException('Pagination offset exceeds the PHP integer range');
        }
        return ($page - 1) * $perPage;
    }

    /**
     * 使用调用方已校验的分页参数，保留数据库 COUNT 返回的整数或十进制字符串。
     * @param array<array-key, mixed> $items
     * @return array{items: array<array-key, mixed>, total: int|string, page: int, perPage: int, lastPage: int|string}
     */
    public static function result(array $items, mixed $total, int $perPage, int $page): array
    {
        $last = self::ceilDivide((string) $total, (string) $perPage);
        return ['items' => $items, 'total' => $total, 'page' => $page,
            'perPage' => $perPage, 'lastPage' => self::integer($last === '0' ? '1' : $last)];
    }

    private static function integer(string $digits): int|string
    {
        return self::compare($digits, (string) PHP_INT_MAX) <= 0 ? (int) $digits : $digits;
    }

    private static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function subtract(string $left, string $right): string
    {
        $result = '';
        $borrow = 0;
        for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0; $i--, $j--) {
            $digit = (int) $left[$i] - ($j >= 0 ? (int) $right[$j] : 0) - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit + ($borrow ? 10 : 0)) . $result;
        }
        return ltrim($result, '0') ?: '0';
    }

    private static function ceilDivide(string $digits, string $divisor): string
    {
        $quotient = '';
        $remainder = '0';
        foreach (str_split($digits) as $digit) {
            $remainder = ltrim($remainder . $digit, '0') ?: '0';
            $next = 0;
            while (self::compare($remainder, $divisor) >= 0) {
                $remainder = self::subtract($remainder, $divisor);
                $next++;
            }
            $quotient .= (string) $next;
        }
        $quotient = ltrim($quotient, '0') ?: '0';
        if ($remainder === '0') { return $quotient; }
        for ($i = strlen($quotient) - 1; $i >= 0; $i--) {
            if ($quotient[$i] !== '9') {
                $quotient[$i] = (string) ((int) $quotient[$i] + 1);
                return $quotient;
            }
            $quotient[$i] = '0';
        }
        return '1' . $quotient;
    }
}
