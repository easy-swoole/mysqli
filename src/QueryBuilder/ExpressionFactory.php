<?php

namespace EasySwoole\Mysqli\QueryBuilder;

use EasySwoole\Mysqli\Exception\Exception;

/** @internal 构建既有写入表达式描述及时间间隔。 */
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
        if ($diff === null) {
            return $func;
        }
        if (preg_match('/\A\s*([+-]?)\s*([0-9]+)\s*([smhdMY]?)\s*\z/', $diff, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid time interval');
        }
        $types = ['s' => 'second', 'm' => 'minute', 'h' => 'hour', 'd' => 'day', 'M' => 'month', 'Y' => 'year'];
        $sign = $matches[1] === '' ? '+' : $matches[1];
        $type = $matches[3] === '' ? 'd' : $matches[3];
        return $func . ' ' . $sign . ' interval ' . $matches[2] . ' ' . $types[$type] . ' ';
    }
}
