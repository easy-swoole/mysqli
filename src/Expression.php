<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

/** Immutable trusted SQL fragment with separately bound scalar values. */
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
            // Copy values individually so caller-owned PHP references cannot mutate this expression.
            $values[] = $value;
        }
        $this->parameters = $values;
    }

    public function getSql(): string { return $this->sql; }
    public function getParameters(): array { return $this->parameters; }
}
