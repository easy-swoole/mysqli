<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 不可变的已构建结果，与下一条查询的待构建状态分开保存。 */
final class CompiledQuery
{
    public function __construct(
        public readonly mixed $sql,
        public readonly array $parameters,
        public readonly array $options,
        public readonly ?int $transactionOp,
    ) {}
}
