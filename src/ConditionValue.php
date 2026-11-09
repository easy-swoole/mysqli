<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

/** QueryBuilder 的控制标记；数据库 NULL 和布尔值使用原生 PHP 值。 */
enum ConditionValue
{
    /** 将条件视为完整 SQL 表达式，不绑定值。 */
    case Raw;
}
