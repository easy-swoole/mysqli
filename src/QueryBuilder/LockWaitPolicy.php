<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** SELECT FOR UPDATE 遇到行锁时的等待策略。 */
enum LockWaitPolicy: string
{
    /** 不附加等待选项，使用服务端默认行为。 */
    case Default = '';

    /** 遇到已锁定的行时立即报错。 */
    case Nowait = 'NOWAIT';

    /** 跳过已锁定的行。 */
    case SkipLocked = 'SKIP LOCKED';
}
