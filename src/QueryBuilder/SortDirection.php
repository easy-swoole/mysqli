<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** 排序方向。 */
enum SortDirection: string
{
    /** 按字段值升序排列。 */
    case Asc = 'ASC';

    /** 按字段值降序排列。 */
    case Desc = 'DESC';
}
