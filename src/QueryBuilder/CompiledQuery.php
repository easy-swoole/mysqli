<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Immutable completed output, kept independently of the next query's pending state. */
final class CompiledQuery
{
    public function __construct(
        public readonly mixed $sql,
        public readonly array $parameters,
        public readonly array $options,
        public readonly ?int $transactionOp,
    ) {}
}
