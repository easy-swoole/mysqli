<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal COUNT 构建和发布的范围，结束时一并解除钩子内副本的临时状态。 */
final class CountCompilation
{
    public bool $active = true;
    public bool $skipResetHook = false;

    /** @param array{bool, bool}|null $quoteMode */
    public function __construct(public readonly ?array $quoteMode = null) {}
}
