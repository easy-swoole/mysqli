<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** 查询构造器的公开只读结果，保存 SQL、绑定参数及构建时的引号模式。 */
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

    /** 使用构建时的引号模式按需展开调试 SQL。 */
    public function toDebugSql(): string
    {
        if ($this->parameters === []) {
            return $this->sql;
        }
        return SqlFormatter::renderForMode($this->sql, $this->parameters, ...$this->quoteMode);
    }

    /** @param array{bool, bool} $quoteMode */
    public static function fromBuffer(SqlBuffer $buffer, array $options, ?int $operation, array $quoteMode): self
    {
        return new self($buffer->sql, $buffer->parameters, $options, $operation, $buffer->unionOperandNeedsParentheses, $quoteMode);
    }
}
