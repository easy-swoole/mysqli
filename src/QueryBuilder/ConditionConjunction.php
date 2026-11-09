<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** WHERE、HAVING 和 ON 条件之间的逻辑连接符。 */
enum ConditionConjunction: string
{
    /** 要求两侧条件同时成立。 */
    case And = 'AND';

    /** 要求两侧至少一个条件成立。 */
    case Or = 'OR';
}
