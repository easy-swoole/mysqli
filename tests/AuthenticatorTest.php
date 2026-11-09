<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Config, TlsMode};
use EasySwoole\Mysqli\Exception\{Exception, ProtocolException, TimeoutException, UnsupportedOperationException};
use EasySwoole\Mysqli\Protocol\{Authenticator, Connection, Deadline, PacketStream, Statement};
use PHPUnit\Framework\TestCase;

final class AuthenticatorTest extends TestCase
{
    public function testServerHandshakeErrorUsesConnectionErrorCallback(): void
    {
        $packet = "\xff" . pack('v', 1045) . '#28000access denied';
        $seen = [];
        $authenticator = new Authenticator(new Config(), new PacketStream(),
            static function (string $value, string $context) use (&$seen): void {
                $seen[] = [$value, $context];
                throw new Exception('forwarded server error', 1045);
            }, static function (): void { self::fail('An error packet must not update OK status'); });

        try { $authenticator->authenticate($packet, Deadline::after(1.0)); self::fail('Expected server error'); }
        catch (Exception $error) {
            self::assertSame(1045, $error->getCode());
            self::assertSame('forwarded server error', $error->getMessage());
        }
        self::assertSame([[$packet, 'handshake']], $seen);
    }

    public function testLegacyProtocolAndTruncatedHandshakeFailBeforeIo(): void
    {
        $authenticator = $this->authenticator(new Config());
        foreach ([0, 9] as $version) {
            try { $authenticator->authenticate(chr($version), Deadline::after(1.0)); self::fail('Expected protocol rejection'); }
            catch (Exception $error) { self::assertSame("Unsupported MySQL protocol version {$version}", $error->getMessage()); }
        }
        $this->expectException(ProtocolException::class);
        $authenticator->authenticate("\x0a8.4.0\0", Deadline::after(1.0));
    }

    public function testRequiredTlsCannotFallBackBeforeSendingCredentials(): void
    {
        $authenticator = $this->authenticator(new Config(['tlsMode' => TlsMode::Required, 'password' => 'secret']));
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('MySQL server does not support TLS; plaintext fallback is disabled');
        $authenticator->authenticate(self::handshake('caching_sha2_password'), Deadline::after(1.0));
    }

    public function testUnsupportedPluginFailsBeforeWritingAuthenticationPacket(): void
    {
        $authenticator = $this->authenticator(new Config(['password' => 'secret']));
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported MySQL authentication plugin unsupported_plugin');
        $authenticator->authenticate(self::handshake('unsupported_plugin'), Deadline::after(1.0));
    }

    public function testAuthenticationUsesOriginalExpiredDeadline(): void
    {
        $deadline = Deadline::after(0.001);
        usleep(2000);
        $authenticator = $this->authenticator(new Config(['password' => 'secret']));
        $this->expectException(TimeoutException::class);
        $authenticator->authenticate(self::handshake('mysql_native_password'), $deadline);
    }

    public function testBufferedAndStreamingParameterValidationStayBeforeIo(): void
    {
        $connection = new Connection(new Config());
        $statement = new Statement($connection, 7, 'CALL unsupported(?)', 1, 0, 0);
        foreach (['executeStatement', 'iterateStatement'] as $method) {
            try { $connection->{$method}($statement, [], 0.0); self::fail('Expected parameter count mismatch'); }
            catch (\InvalidArgumentException $error) {
                self::assertSame('Statement expects 1 parameters, 0 given', $error->getMessage());
            }
            self::assertFalse($connection->isBusy());
            self::assertFalse($connection->isConnected());
        }
    }

    public function testSqlAndDeadlineValidationKeepExistingEntranceOrder(): void
    {
        $connection = new Connection(new Config());
        try { $connection->query('CALL unsupported()', 0.0); self::fail('Expected CALL rejection'); }
        catch (UnsupportedOperationException) { self::addToAssertionCount(1); }

        // iterate() 原本会先创建共享截止时间，再进入结果流的并发保护。
        try { $connection->iterate('CALL unsupported()', 0.0); self::fail('Expected timeout validation'); }
        catch (\InvalidArgumentException $error) { self::assertSame('Query timeout must be greater than zero', $error->getMessage()); }
        self::assertFalse($connection->isBusy());
    }

    private function authenticator(Config $config): Authenticator
    {
        return new Authenticator($config, new PacketStream(),
            static function (): void { self::fail('No server response is expected before the first write'); },
            static function (): void { self::fail('No OK status is expected before the first write'); });
    }

    private static function handshake(string $plugin): string
    {
        $capabilities = 1 | 4 | 0x200 | 0x2000 | 0x8000 | 0x80000;
        return "\x0a8.4.0-auth-test\0" . pack('V', 1) . "12345678\0"
            . pack('v', $capabilities & 0xffff) . "\x2d" . pack('vv', 2, $capabilities >> 16)
            . "\x15" . str_repeat("\0", 10) . "abcdefghijkl\0" . $plugin . "\0";
    }
}
