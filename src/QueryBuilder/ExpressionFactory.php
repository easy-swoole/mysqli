<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Exception\Exception;

/** @internal Build the existing write-expression descriptors and time intervals. */
final class ExpressionFactory
{
    /** @return array{'[F]': array{0: mixed}} */
    public static function now(mixed $diff = null, mixed $func = "NOW()"): array
    {
        return array("[F]" => Array(self::interval($diff, $func)));
    }

    /** @return array{'[I]': string} */
    public static function inc(mixed $num = 1): array
    {
        if (!is_numeric($num)||$num<=0) {
            throw new Exception('Argument supplied to inc must be a positive number');
        }
        return array("[I]" => " + " . $num);
    }

    /** @return array{'[I]': string} */
    public static function dec(mixed $num = 1): array
    {
        if (!is_numeric($num)||$num<=0) {
            throw new Exception('Argument supplied to dec must be a positive number');
        }
        return array("[I]" => " - " . $num);
    }

    /** @return array{'[N]': string} */
    public static function not(mixed $col = null): array
    {
        return array("[N]" => (string)$col);
    }

    /** @return array{'[F]': array{0: mixed, 1: mixed}} */
    public static function func(mixed $expr, mixed $bindParams = null): array
    {
        return array("[F]" => array($expr, $bindParams));
    }

    private static function interval(mixed $diff, mixed $func = "NOW()"): mixed
    {
        $types = Array("s" => "second", "m" => "minute", "h" => "hour", "d" => "day", "M" => "month", "Y" => "year");
        $incr = '+';
        $items = '';
        $type = 'd';
        if ($diff && preg_match('/([+-]?) ?([0-9]+) ?([a-zA-Z]?)/', $diff, $matches)) {
            if (!empty($matches[1])) {
                $incr = $matches[1];
            }
            if (!empty($matches[2])) {
                $items = $matches[2];
            }
            if (!empty($matches[3])) {
                $type = $matches[3];
            }
            if (!in_array($type, array_keys($types))) {
                throw new Exception("invalid interval type in '{$diff}'");
            }
            $func .= " " . $incr . " interval " . $items . " " . $types[$type] . " ";
        }
        return $func;
    }
}
