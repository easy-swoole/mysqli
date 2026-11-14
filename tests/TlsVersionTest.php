<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Config, TlsMode, TlsVersion};
use EasySwoole\Mysqli\Protocol\TlsOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TlsVersionTest extends TestCase
{
    public function testDefaultVersionsRemainUnchanged(): void
    {
        $config = new Config();

        self::assertSame(['TLSv1.2', 'TLSv1.3'], $config->getTlsVersions());
        self::assertSame(['TLSv1.2', 'TLSv1.3'], $config->toArray()['tlsVersions']);
        self::assertSame(TlsMode::Disabled, $config->getTlsMode());
        self::assertSame('TLSv1.2', TlsVersion::Tls12->value);
        self::assertSame('TLSv1.3', TlsVersion::Tls13->value);
    }

    public function testConstructorAcceptsMixedVersionsAndPreservesKeys(): void
    {
        $config = new Config(['tlsVersions' => ['first' => TlsVersion::Tls12, 'second' => 'TLSv1.3']]);

        self::assertSame(['first' => 'TLSv1.2', 'second' => 'TLSv1.3'], $config->getTlsVersions());
    }

    public function testSetterAcceptsMixedVersionsAndReturnsStrings(): void
    {
        $config = new Config();
        $config->setTlsVersions(['TLSv1.3', TlsVersion::Tls12]);

        self::assertSame(['TLSv1.3', 'TLSv1.2'], $config->getTlsVersions());
        $config->setTlsVersions([TlsVersion::Tls13]);
        self::assertSame(['TLSv1.3'], $config->getTlsVersions());
    }

    public function testSplBeanRestoreAcceptsMixedVersions(): void
    {
        $config = new Config();
        $config->restore(['tlsVersions' => [TlsVersion::Tls13, 'TLSv1.2']]);

        self::assertSame(['TLSv1.3', 'TLSv1.2'], $config->getTlsVersions());
        self::assertSame(['TLSv1.3', 'TLSv1.2'], json_decode((string) $config, true)['tlsVersions']);
    }

    #[DataProvider('supportedVersions')]
    public function testVersionsPreserveTlsProtocolFlags(array $versions, int $expected): void
    {
        [$options] = TlsOptions::handshakeConfiguration(new Config([
            'tlsMode' => TlsMode::Required,
            'tlsVersions' => $versions,
        ]));

        self::assertTrue($options['open_ssl']);
        self::assertSame($expected, $options['ssl_protocols']);
        self::assertFalse($options['ssl_verify_peer']);
    }

    public static function supportedVersions(): array
    {
        return [
            'original strings' => [['TLSv1.2', 'TLSv1.3'], SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3],
            'TLS 1.2 enum' => [[TlsVersion::Tls12], SWOOLE_SSL_TLSv1_2],
            'TLS 1.3 enum' => [[TlsVersion::Tls13], SWOOLE_SSL_TLSv1_3],
            'mixed versions' => [[TlsVersion::Tls12, 'TLSv1.3'], SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3],
            'duplicate versions' => [[TlsVersion::Tls12, 'TLSv1.2'], SWOOLE_SSL_TLSv1_2],
        ];
    }

    public function testUnknownStringIsStoredUntilTlsOptionsValidation(): void
    {
        $config = new Config(['tlsMode' => TlsMode::Required, 'tlsVersions' => ['TLSv1']]);
        self::assertSame(['TLSv1'], $config->getTlsVersions());
        $config->setTlsVersions(['TLSv1.4']);
        self::assertSame(['TLSv1.4'], $config->getTlsVersions());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only TLSv1.2 and TLSv1.3 are supported');
        TlsOptions::handshakeConfiguration($config);
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidVersionsFailAtTlsOptionsBoundary(array $versions, bool $setter, string $message): void
    {
        $config = new Config(['tlsMode' => TlsMode::Required, 'tlsVersions' => $versions]);
        if ($setter) {
            $config->setTlsVersions($versions);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        TlsOptions::handshakeConfiguration($config);
    }

    public static function invalidVersions(): array
    {
        $cases = [
            'empty' => [[], 'tlsVersions must not be empty'],
            'legacy version' => [['TLSv1'], 'Only TLSv1.2 and TLSv1.3 are supported'],
            'unknown version' => [[TlsVersion::Tls12, 'TLSv1.4'], 'Only TLSv1.2 and TLSv1.3 are supported'],
            'case mismatch' => [['tlsv1.2'], 'Only TLSv1.2 and TLSv1.3 are supported'],
            'integer' => [[12], 'Only TLSv1.2 and TLSv1.3 are supported'],
            'boolean' => [[false], 'Only TLSv1.2 and TLSv1.3 are supported'],
            'null' => [[null], 'Only TLSv1.2 and TLSv1.3 are supported'],
            'nested array' => [[['TLSv1.2']], 'Only TLSv1.2 and TLSv1.3 are supported'],
            'other enum' => [[TlsMode::Required], 'Only TLSv1.2 and TLSv1.3 are supported'],
        ];
        $datasets = [];
        foreach ($cases as $name => [$versions, $message]) {
            $datasets['constructor ' . $name] = [$versions, false, $message];
            $datasets['setter ' . $name] = [$versions, true, $message];
        }
        return $datasets;
    }
}
