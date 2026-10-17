<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Exception\ProtocolException;
use EasySwoole\Mysqli\Protocol\Codec;
use EasySwoole\Mysqli\Protocol\Column;
use EasySwoole\Mysqli\Protocol\PacketPayload;
use EasySwoole\Mysqli\Protocol\ParameterEncoder;
use EasySwoole\Mysqli\Protocol\ResultDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtocolPayloadTest extends TestCase
{
    #[DataProvider('packetParts')]
    public function testPayloadPartsPreserveBytesAcrossChunkBoundaries(array $parts, int $limit, array $expected): void
    {
        $chunks = iterator_to_array(PacketPayload::chunks($parts, $limit), false);
        self::assertSame($expected, $chunks);
        self::assertSame(implode('', $parts), implode('', $chunks));
    }

    public static function packetParts(): array
    {
        return [
            'empty' => [[], 4, []],
            'empty strings' => [['', ''], 4, []],
            'exact boundary' => [['ab', 'cd'], 4, ['abcd']],
            'multiple boundaries' => [['a', 'bcdef', 'ghi'], 4, ['abcd', 'efgh', 'i']],
            'binary and empty parts' => [["\0\xff", '', "\x01\x02\x03"], 2, ["\0\xff", "\x01\x02", "\x03"]],
        ];
    }

    public function testInvalidChunkSizeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array(PacketPayload::chunks(['x'], 0));
    }

    public function testParameterEncodingPreservesNullBitmapTypesAndBinaryValues(): void
    {
        [$bitmap, $types, $parts] = ParameterEncoder::encode([null, true, false, -1, 1.25, "\0\xff", '']);
        self::assertSame("\x01", $bitmap);
        self::assertSame("\x06\0\x01\0\x01\0\x08\0\x05\0\xfd\0\xfd\0", $types);
        self::assertSame("\x01\0" . str_repeat("\xff", 8) . pack('e', 1.25) . "\x02\0\xff\0", implode('', $parts));
        self::assertSame(['', '', []], ParameterEncoder::encode([]));
        [$bitmap] = ParameterEncoder::encode([null, 1, 2, 3, 4, 5, 6, 7, null]);
        self::assertSame("\x01\x01", $bitmap);
    }

    public function testUnsupportedParameterFailsBeforeSending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ParameterEncoder::encode([new \stdClass()]);
    }

    #[DataProvider('truncatedValues')]
    public function testTruncatedIntegersFailWithoutAdvancingOffset(string $method, string $packet): void
    {
        $offset = 1;
        try {
            Codec::{$method}($packet, $offset);
            self::fail('Expected protocol error');
        } catch (ProtocolException) {
            self::assertSame(1, $offset);
        }
    }

    public static function truncatedValues(): array
    {
        return [['int1', 'x'], ['int2', 'xy'], ['int3', 'xyz'], ['int4', 'xyzw']];
    }

    #[DataProvider('truncatedRows')]
    public function testTruncatedBinaryRowsFailAsProtocolErrors(string $packet, int $type): void
    {
        $this->expectException(ProtocolException::class);
        ResultDecoder::parseBinaryRow($packet, [new Column('v', $type, 0)]);
    }

    public static function truncatedRows(): array
    {
        return [['', Column::TYPE_LONG], ["\0", Column::TYPE_LONG], ["\0\0\x01", Column::TYPE_LONG], ["\0\0\x01", Column::TYPE_DOUBLE]];
    }

    public function testInvalidLengthMarkerIsRejected(): void
    {
        $offset = 0;
        $this->expectException(ProtocolException::class);
        Codec::lenencInt("\xff", $offset);
    }
}
