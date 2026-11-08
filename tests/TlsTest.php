<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\{Client, Config, TlsMode};
use EasySwoole\Mysqli\Exception\{Exception, TimeoutException};
use EasySwoole\Mysqli\Protocol\{CertificateIdentity, TlsOptions};
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\{Channel, Socket};

final class TlsTest extends TestCase
{
    private static string $directory;
    private static string $certificate = "";

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir() . '/mysqli-tls-' . bin2hex(random_bytes(8));
        mkdir(self::$directory, 0700);
        $config = self::$directory . '/openssl.cnf';
        file_put_contents($config, <<<'CNF'
[req]
distinguished_name=dn
[dn]
[ca]
basicConstraints=critical,CA:TRUE
keyUsage=critical,keyCertSign,cRLSign
[server]
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=@names
[names]
DNS.1=localhost
DNS.2=*.example.test
DNS.3=evil.invalid, DNS:forged.invalid
IP.1=127.0.0.1
IP.2=::1
CNF);
        $caKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $caCsr = openssl_csr_new(['commonName' => 'Test CA'], $caKey, ['config' => $config, 'digest_alg' => 'sha256']);
        $ca = openssl_csr_sign($caCsr, null, $caKey, 2, ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'ca']);
        openssl_x509_export_to_file($ca, self::$directory . '/ca.pem');
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        // CN must not be accepted when DNS SANs exist.
        $csr = openssl_csr_new(['commonName' => 'cn-only.invalid'], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, $ca, $caKey, 1, ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'server']);
        $expired = openssl_csr_sign($csr, $ca, $caKey, 0, ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'server']);
        openssl_x509_export_to_file($expired, self::$directory . '/expired.pem');
        openssl_x509_export($cert, self::$certificate);
        file_put_contents(self::$directory . '/server.pem', self::$certificate);
        openssl_pkey_export_to_file($key, self::$directory . '/server.key');
        chmod(self::$directory . '/server.key', 0600);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$directory . '/*') as $file) { unlink($file); }
        rmdir(self::$directory);
    }

    public function testTlsDefaultsAndExplicitConfiguration(): void
    {
        self::assertSame(TlsMode::Disabled, (new Config())->getTlsMode());
        foreach (TlsMode::cases() as $mode) {
            self::assertSame($mode, (new Config(['tlsMode' => $mode]))->getTlsMode());
            self::assertSame($mode, (new Config(['tlsMode' => $mode->value]))->getTlsMode());
        }
        $config = new Config();
        $config->setTlsMode('required');
        self::assertSame(TlsMode::Required, $config->getTlsMode());
        $config->restore(['tlsMode' => 'verify_identity']);
        self::assertSame(TlsMode::VerifyIdentity, $config->getTlsMode());
        foreach (['CaFile', 'CaPath', 'CertFile', 'KeyFile', 'KeyPassphrase', 'ServerName'] as $name) {
            $config->{'setTls' . $name}('value');
            self::assertSame('value', $config->{'getTls' . $name}());
        }
        $config->setTlsVersions(['TLSv1.2']);
        self::assertSame(['TLSv1.2'], $config->getTlsVersions());
    }

    public function testInvalidTlsConfigurationFailsClosed(): void
    {
        foreach ([['tlsMode' => 'typo'], ['tlsVersions' => []], ['tlsVersions' => ['TLSv1']],
                  ['tlsCaFile' => '/missing/ca.pem'], ['tlsCaPath' => self::$directory . '/ca.pem'],
                  ['tlsCertFile' => self::$directory . '/server.pem']] as $options) {
            try {
                TlsOptions::fromConfig(new Config($options + ['tlsMode' => TlsMode::VerifyIdentity]));
                self::fail('Invalid TLS configuration must not fall back to plaintext');
            } catch (\InvalidArgumentException|\ValueError $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function testCertificateDnsIpAndWildcardMatching(): void
    {
        foreach (['localhost', 'LOCALHOST.', '127.0.0.1', '::1', 'db.example.test'] as $name) {
            CertificateIdentity::verify(self::$certificate, $name);
            self::addToAssertionCount(1);
        }
        foreach (['127.0.0.2', 'wrong.invalid', 'cn-only.invalid', 'example.test',
                  'a.b.example.test', 'forged.invalid', '', "localhost\0.invalid"] as $name) {
            try {
                CertificateIdentity::verify(self::$certificate, $name);
                self::fail('Invalid certificate identity was accepted: ' . $name);
            } catch (Exception $error) { self::assertStringContainsString('TLS', $error->getMessage()); }
        }
    }

    public function testTlsFullAuthenticationAndIdentityBeforeCredentials(): void
    {
        [$client, $completed] = $this->server('ok', ['tlsMode' => TlsMode::VerifyIdentity, 'tlsCaFile' => self::$directory . '/ca.pem']);
        self::assertTrue($client->connect());
        self::assertTrue($client->mysqlClient()->isTlsEnabled());
        $client->close();
        $this->completed($completed);
    }

    public function testNativePasswordAuthenticationOverTls(): void
    {
        [$client, $completed] = $this->server('native', ['tlsMode' => TlsMode::VerifyIdentity,
            'tlsCaFile' => self::$directory . '/ca.pem']);
        self::assertTrue($client->connect());
        $connection = $client->mysqlClient();
        self::assertTrue($connection->isTlsEnabled());
        $client->close();
        self::assertFalse($connection->isTlsEnabled());
        $this->completed($completed);
    }

    public function testRequiredDoesNotRequireTrustButVerifyCaDoes(): void
    {
        [$client, $completed] = $this->server('ok', ['tlsMode' => TlsMode::Required]);
        self::assertTrue($client->connect());
        $client->close();
        $this->completed($completed);
        [$client, $completed] = $this->server('reject', ['tlsMode' => TlsMode::VerifyCA]);
        try { $client->connect(); self::fail('Untrusted certificate accepted'); }
        catch (Exception $error) { self::assertNull($client->mysqlClient()); }
        $this->completed($completed);
    }

    public function testVerifyCaDoesNotRequireMatchingIdentity(): void
    {
        [$client, $completed] = $this->server('ok', ['tlsMode' => TlsMode::VerifyCA,
            'tlsCaFile' => self::$directory . '/ca.pem', 'tlsServerName' => 'wrong.invalid']);
        self::assertTrue($client->connect());
        $client->close();
        $this->completed($completed);
    }

    public function testIdentityMismatchSendsNoCredentials(): void
    {
        [$client, $completed] = $this->server('reject', ['tlsMode' => TlsMode::VerifyIdentity,
            'tlsCaFile' => self::$directory . '/ca.pem', 'tlsServerName' => 'wrong.invalid']);
        try { $client->connect(); self::fail('Wrong identity accepted'); }
        catch (Exception $error) {
            self::assertStringContainsString('identity', $error->getMessage());
            self::assertNull($client->mysqlClient());
        }
        $this->completed($completed);
    }

    public function testExpiredCertificateSendsNoCredentials(): void
    {
        // The zero-day fixture expires at its creation second; advance beyond that second.
        Coroutine::sleep(1.05);
        [$client, $completed] = $this->server('expired', ['tlsMode' => TlsMode::VerifyIdentity,
            'tlsCaFile' => self::$directory . '/ca.pem']);
        try { $client->connect(); self::fail('Expired certificate accepted'); }
        catch (Exception $error) { self::assertNull($client->mysqlClient()); }
        $this->completed($completed);
    }

    public function testTlsCannotDowngradeAndDisabledRemainsPlaintext(): void
    {
        [$client, $completed] = $this->server('no_tls', ['tlsMode' => TlsMode::Required]);
        try { $client->connect(); self::fail('TLS must not downgrade'); }
        catch (Exception $error) { self::assertStringContainsString('does not support TLS', $error->getMessage()); }
        $this->completed($completed);
        [$client, $completed] = $this->server('plaintext', []);
        self::assertTrue($client->connect());
        self::assertFalse($client->mysqlClient()->isTlsEnabled());
        $client->close();
        $this->completed($completed);
    }

    public function testTlsHandshakeUsesSharedConnectionDeadline(): void
    {
        [$client, $completed] = $this->server('stall', ['tlsMode' => TlsMode::Required, 'maxConnectTime' => 0.08]);
        $start = microtime(true);
        try { $client->connect(); self::fail('TLS handshake must time out'); }
        catch (TimeoutException $error) {
            self::assertLessThan(0.115, microtime(true) - $start);
            self::assertNull($client->mysqlClient());
        }
        $this->completed($completed);
    }

    private function server(string $scenario, array $options): array
    {
        if (!method_exists(Socket::class, 'sslHandshake')) { self::markTestSkipped('Swoole OpenSSL required'); }
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind('127.0.0.1', 0));
        self::assertTrue($listener->listen());
        $completed = new Channel(1);
        Coroutine::create(static function () use ($listener, $completed, $scenario): void {
            $peer = null;
            try {
                $peer = $listener->accept(1);
                self::assertInstanceOf(Socket::class, $peer);
                if ($scenario === 'stall') { Coroutine::sleep(0.04); }
                $capabilities = 0x00000001 | 0x00000004 | 0x00000200 | 0x00002000 | 0x00008000 | 0x00080000;
                if ($scenario !== 'no_tls') { $capabilities |= 0x800; }
                self::send($peer, "\x0a8.0.0-tls\0" . pack('V', 1) . "12345678\0" . pack('v', $capabilities & 0xffff)
                    . "\x2d" . pack('v', 2) . pack('v', $capabilities >> 16) . "\x15" . str_repeat("\0", 10)
                    . "abcdefghijkl\0" . ($scenario === 'native' ? 'mysql_native_password' : 'caching_sha2_password') . "\0", 0);
                if ($scenario === 'no_tls') {
                    self::assertSame('', $peer->recv(1));
                } else {
                    [$auth, $sequence] = self::receive($peer);
                    self::assertSame(1, $sequence);
                    if ($scenario === 'plaintext') {
                        self::assertSame(0, unpack('V', $auth)[1] & 0x800);
                        self::send($peer, self::ok(), 2);
                    } else {
                        self::assertSame(32, strlen($auth));
                        self::assertSame(0x800, unpack('V', $auth)[1] & 0x800);
                        if ($scenario === 'stall') {
                            Coroutine::sleep(0.15);
                        } else {
                            self::assertTrue($peer->setProtocol(['open_ssl' => true, 'ssl_cert_file' => self::$directory . ($scenario === 'expired' ? '/expired.pem' : '/server.pem'),
                                'ssl_key_file' => self::$directory . '/server.key', 'ssl_verify_peer' => false]));
                            $tls = @$peer->sslHandshake();
                            if (in_array($scenario, ['reject', 'expired'], true)) {
                                if ($tls) { self::assertSame('', @$peer->recv(1)); }
                            } else {
                                self::assertTrue($tls);
                                [$auth, $sequence] = self::receive($peer);
                                self::assertSame(2, $sequence);
                                self::assertStringContainsString("test\0", $auth);
                                if ($scenario === 'native') {
                                    self::send($peer, self::ok(), 3);
                                } else {
                                    // Force a cache miss: send the password only inside verified TLS.
                                    self::send($peer, "\x01\x04", 3);
                                    self::assertSame(["secret\0", 4], self::receive($peer));
                                    self::send($peer, self::ok(), 5);
                                }
                            }
                        }
                    }
                    if (in_array($scenario, ['ok', 'native', 'plaintext'], true)) {
                        [$query, $sequence] = self::receive($peer);
                        self::assertSame("\x03SET NAMES utf8mb4", $query);
                        self::assertSame(0, $sequence);
                        self::send($peer, self::ok(), 1);
                        self::assertSame(["\x01", 0], self::receive($peer));
                    }
                }
                $completed->push(true);
            } catch (\Throwable $error) { $completed->push($error); }
            finally { $peer?->close(); $listener->close(); }
        });
        return [new Client(new Config($options + ['host' => '127.0.0.1', 'port' => $listener->getsockname()['port'],
            'user' => 'test', 'password' => 'secret', 'timeout' => 1.0, 'maxConnectTime' => 1.0])), $completed];
    }

    private static function ok(): string { return "\0\0\0" . pack('v', 2) . "\0\0"; }
    private static function send(Socket $peer, string $payload, int $sequence): void
    {
        $packet = substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;
        self::assertSame(strlen($packet), $peer->sendAll($packet, 1));
    }
    private static function receive(Socket $peer): array
    {
        $header = $peer->recvAll(4, 1);
        self::assertIsString($header);
        self::assertSame(4, strlen($header));
        $length = unpack('V', substr($header, 0, 3) . "\0")[1];
        $body = $length === 0 ? '' : $peer->recvAll($length, 1);
        self::assertIsString($body);
        self::assertSame($length, strlen($body));
        return [$body, ord($header[3])];
    }
    private function completed(Channel $channel): void
    {
        $result = $channel->pop(2);
        if ($result instanceof \Throwable) { throw $result; }
        self::assertTrue($result);
    }
}
