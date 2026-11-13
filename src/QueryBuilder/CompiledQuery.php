<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 不可变的已构建结果，与下一条查询的待构建状态分开保存。 */
final class CompiledQuery
{
    private ?string $joinedProjection = null;

    public function __construct(
        public readonly string $sql,
        public readonly array $parameters,
        public readonly array $options,
        public readonly ?int $transactionOp,
        public readonly bool $unionOperandNeedsParentheses = true,
    ) {}

    public static function fromBuffer(SqlBuffer $buffer, array $options, ?int $operation): self
    {
        $query = new self($buffer->sql, $buffer->parameters, $options, $operation, $buffer->unionOperandNeedsParentheses);
        $query->joinedProjection = $buffer->projection;
        return $query;
    }

    public function joinedProjection(): ?string
    {
        return $this->joinedProjection;
    }
}
