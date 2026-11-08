<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

/** QueryBuilder control markers; database NULL and booleans use native PHP values. */
enum ConditionValue
{
    /** Treat the condition as a complete SQL expression without binding a value. */
    case Raw;
}
