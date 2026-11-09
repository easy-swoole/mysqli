<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** LOCK TABLES 使用的表锁模式。 */
enum TableLockMode: string
{
    /** 申请表的读锁。 */
    case Read = 'READ';

    /** 申请表的写锁。 */
    case Write = 'WRITE';
}
