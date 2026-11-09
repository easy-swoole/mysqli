<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** 两个值或字段之间的比较运算符。 */
enum ComparisonOperator: string
{
    /** 判断两侧值相等。 */
    case Equal = '=';

    /** 判断两侧值相等，并允许对 NULL 进行安全比较。 */
    case NullSafeEqual = '<=>';

    /** 使用 SQL 标准写法判断两侧值不相等。 */
    case NotEqual = '<>';

    /** 使用兼容写法判断两侧值不相等。 */
    case NotEqualAlt = '!=';

    /** 判断左侧值大于右侧值。 */
    case GreaterThan = '>';

    /** 判断左侧值小于右侧值。 */
    case LessThan = '<';

    /** 判断左侧值大于或等于右侧值。 */
    case GreaterThanOrEqual = '>=';

    /** 判断左侧值小于或等于右侧值。 */
    case LessThanOrEqual = '<=';
}
