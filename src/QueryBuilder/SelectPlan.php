<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 不可变的 SELECT 头部及参数，供后续子句编译使用。 */
final class SelectPlan
{
    public function __construct(
        public readonly string $sql,
        public readonly array $parameters,
        public readonly string $tableName,
    ) {}
}
