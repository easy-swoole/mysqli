<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

/** 不可变的可信 SQL 片段，标量值通过独立参数绑定。 */
final class Expression
{
    private readonly array $parameters;

    public function __construct(private readonly string $sql, array $parameters = [])
    {
        if (trim($sql) === '' || str_contains($sql, "\0")) {
            throw new \InvalidArgumentException('Expression SQL must be non-empty and contain no NUL bytes');
        }
        $values = [];
        foreach ($parameters as $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException('Expression parameters must be scalar values or null');
            }
            // 逐个复制参数值，避免调用方持有的 PHP 引用修改此表达式。
            $values[] = $value;
        }
        $this->parameters = $values;
    }

    public function getSql(): string { return $this->sql; }
    public function getParameters(): array { return $this->parameters; }
}
