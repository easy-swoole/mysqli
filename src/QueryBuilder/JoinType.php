<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** JOIN 的连接类型。 */
enum JoinType: string
{
    /** 使用不带类型前缀的 JOIN。 */
    case Default = '';

    /** 仅保留两侧满足连接条件的记录。 */
    case Inner = 'INNER';

    /** 保留左表的记录。 */
    case Left = 'LEFT';

    /** 保留右表的记录。 */
    case Right = 'RIGHT';

    /** 使用 LEFT OUTER JOIN 保留左表的记录。 */
    case LeftOuter = 'LEFT OUTER';

    /** 使用 RIGHT OUTER JOIN 保留右表的记录。 */
    case RightOuter = 'RIGHT OUTER';

    /** 按两侧同名字段进行自然连接。 */
    case Natural = 'NATURAL';
}
