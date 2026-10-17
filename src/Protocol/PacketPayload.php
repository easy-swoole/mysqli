<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

/** Assemble at most one classic packet payload at a time. */
final class PacketPayload
{
    /** @param list<string> $parts @return \Generator<int, string> */
    public static function chunks(array $parts, int $limit): \Generator
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('Packet chunk size must be positive');
        }
        $buffer = '';
        foreach ($parts as $part) {
            $offset = 0;
            $length = strlen($part);
            while ($offset < $length) {
                $take = min($limit - strlen($buffer), $length - $offset);
                $buffer .= substr($part, $offset, $take);
                $offset += $take;
                if (strlen($buffer) === $limit) {
                    yield $buffer;
                    $buffer = '';
                }
            }
        }
        if ($buffer !== '') {
            yield $buffer;
        }
    }
}
