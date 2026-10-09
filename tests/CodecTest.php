<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Protocol\Codec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CodecTest extends TestCase
{
    #[DataProvider('unsignedIntegers')]
    public function testInt8PreservesUnsignedIntegerAndOffset(string $hex, string $decimal): void
    {
        $offset = 2;
        $packet = 'xx' . hex2bin($hex) . 'tail';
        self::assertSame(self::expectedValue($decimal), Codec::int8($packet, $offset));
        self::assertSame(10, $offset);
        self::assertSame('tail', substr($packet, $offset));
    }

    #[DataProvider('unsignedIntegers')]
    public function testLengthEncodedInt8PreservesUnsignedInteger(string $hex, string $decimal): void
    {
        $offset = 1;
        $packet = 'x' . "\xfe" . hex2bin($hex) . 'tail';
        self::assertSame(self::expectedValue($decimal), Codec::lenencInt($packet, $offset));
        self::assertSame(10, $offset);
    }

    public static function unsignedIntegers(): array
    {
        return [
            'zero' => ['0000000000000000', '0'],
            '32-bit signed max' => ['ffffff7f00000000', '2147483647'],
            '32-bit signed max plus one' => ['0000008000000000', '2147483648'],
            '32-bit unsigned max' => ['ffffffff00000000', '4294967295'],
            '32-bit unsigned max plus one' => ['0000000001000000', '4294967296'],
            'beyond float exact integer range' => ['0100000000002000', '9007199254740993'],
            '64-bit signed max minus one' => ['feffffffffffff7f', '9223372036854775806'],
            '64-bit signed max' => ['ffffffffffffff7f', '9223372036854775807'],
            '64-bit signed max plus one' => ['0000000000000080', '9223372036854775808'],
            '64-bit unsigned max minus one' => ['feffffffffffffff', '18446744073709551614'],
            '64-bit unsigned max' => ['ffffffffffffffff', '18446744073709551615'],
        ];
    }

    private static function expectedValue(string $decimal): int|string
    {
        $max = (string) PHP_INT_MAX;
        return strlen($decimal) < strlen($max)
            || (strlen($decimal) === strlen($max) && strcmp($decimal, $max) <= 0)
            ? (int) $decimal : $decimal;
    }

    public function testTruncatedInt8IsRejectedWithoutAdvancingOffset(): void
    {
        $offset = 1;
        try {
            Codec::int8('x' . str_repeat("\xff", 7), $offset);
            self::fail('Expected malformed integer to be rejected');
        } catch (Exception $error) {
            self::assertStringContainsString('64-bit integer', $error->getMessage());
            self::assertSame(1, $offset);
        }
    }

    #[DataProvider('invalidStringLengths')]
    public function testInvalidLengthEncodedStringIsRejected(string $packet): void
    {
        $offset = 0;
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('length-encoded string');
        Codec::lenencString($packet, $offset);
    }

    public static function invalidStringLengths(): array
    {
        return [
            'length exceeds integer range' => ["\xfe" . str_repeat("\xff", 8)],
            'truncated string' => ["\x05abc"],
        ];
    }

    public function testSmallLengthEncodedIntegersAndStringsRemainCompatible(): void
    {
        foreach ([0, 250, 251, 65535, 65536, 16777215, 16777216] as $value) {
            $packet = Codec::encodeLenencInt($value);
            $offset = 0;
            self::assertSame($value, Codec::lenencInt($packet, $offset));
            self::assertSame(strlen($packet), $offset);
        }
        foreach (['', 'hello', str_repeat('a', 300)] as $value) {
            $packet = Codec::encodeLenencString($value);
            $offset = 0;
            self::assertSame($value, Codec::lenencString($packet, $offset));
            self::assertSame(strlen($packet), $offset);
        }
        $offset = 0;
        self::assertNull(Codec::lenencString("\xfb", $offset));
        self::assertSame(1, $offset);
    }
}
