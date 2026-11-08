<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Exception\Exception;

/** @internal Build the existing write-expression descriptors and time intervals. */
final class ExpressionFactory
{
    public static function now($diff = null, $func = "NOW()")
    {
        return array("[F]" => Array(self::interval($diff, $func)));
    }

    public static function inc($num = 1)
    {
        if (!is_numeric($num)||$num<=0) {
            throw new Exception('Argument supplied to inc must be a positive number');
        }
        return array("[I]" => " + " . $num);
    }

    public static function dec($num = 1)
    {
        if (!is_numeric($num)||$num<=0) {
            throw new Exception('Argument supplied to dec must be a positive number');
        }
        return array("[I]" => " - " . $num);
    }

    public static function not($col = null)
    {
        return array("[N]" => (string)$col);
    }

    public static function func($expr, $bindParams = null)
    {
        return array("[F]" => array($expr, $bindParams));
    }

    private static function interval($diff, $func = "NOW()")
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
