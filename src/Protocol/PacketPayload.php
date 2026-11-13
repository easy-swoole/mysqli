<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

/** 每次最多组装一个经典协议报文的载荷。 */
final class PacketPayload
{
    /** @param list<string> $parts @return \Generator<int, string> */
    public static function chunks(array $parts, int $limit): \Generator
    {
        yield from self::split($parts, $limit, false);
    }

    /** @internal @param list<string> $parts @return \Generator<int, string> */
    public static function packetChunks(array $parts, int $limit): \Generator
    {
        yield from self::split($parts, $limit, true);
    }

    private static function split(array $parts, int $limit, bool $includeTerminator): \Generator
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
        // 空载荷及整包整数倍都以空包结束。
        if ($buffer !== '' || $includeTerminator) {
            yield $buffer;
        }
    }
}
