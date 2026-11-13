<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 记录 get() 钩子内编译的计数来源，按实际返回的查询选取。 */
final class CountCompilation
{
    public bool $active = true;

    /** @var \WeakMap<CompiledQuery, CompiledQuery> */
    public readonly \WeakMap $sources;

    public function __construct(public readonly bool $skipResetHook)
    {
        $this->sources = new \WeakMap();
    }
}
