<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

final class Column
{
    public const TYPE_TINY = 1;
    public const TYPE_SHORT = 2;
    public const TYPE_LONG = 3;
    public const TYPE_FLOAT = 4;
    public const TYPE_DOUBLE = 5;
    public const TYPE_NULL = 6;
    public const TYPE_TIMESTAMP = 7;
    public const TYPE_LONGLONG = 8;
    public const TYPE_INT24 = 9;
    public const TYPE_DATE = 10;
    public const TYPE_TIME = 11;
    public const TYPE_DATETIME = 12;
    public const TYPE_YEAR = 13;
    public const TYPE_VAR_STRING = 253;

    private const UNSIGNED_FLAG = 0x20;

    public function __construct(
        public readonly string $name,
        public readonly int $type,
        public readonly int $flags,
    ) {
    }

    public function isUnsigned(): bool
    {
        return ($this->flags & self::UNSIGNED_FLAG) !== 0;
    }
}
