<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 不可变的已构建结果，与下一条查询的待构建状态分开保存。 */
final class CompiledQuery
{
    /** @param array{bool, bool} $quoteMode */
    public function __construct(
        public readonly string $sql,
        public readonly array $parameters,
        public readonly array $options,
        public readonly ?int $transactionOp,
        public readonly bool $unionOperandNeedsParentheses = true,
        public readonly array $quoteMode = [false, false],
    ) {}

    /** @param array{bool, bool} $quoteMode */
    public static function fromBuffer(SqlBuffer $buffer, array $options, ?int $operation, array $quoteMode): self
    {
        return new self($buffer->sql, $buffer->parameters, $options, $operation, $buffer->unionOperandNeedsParentheses, $quoteMode);
    }
}
