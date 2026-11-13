<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;

/** @internal 负责握手、TLS 升级和认证；会话状态及错误元数据仍由 Connection 接管。 */
final class Authenticator
{
    private const CLIENT_LONG_PASSWORD = 0x00000001;
    private const CLIENT_LONG_FLAG = 0x00000004;
    private const CLIENT_CONNECT_WITH_DB = 0x00000008;
    private const CLIENT_COMPRESS = 0x00000020;
    private const CLIENT_SSL = 0x00000800;
    private const CLIENT_PROTOCOL_41 = 0x00000200;
    private const CLIENT_TRANSACTIONS = 0x00002000;
    private const CLIENT_SECURE_CONNECTION = 0x00008000;
    private const CLIENT_PLUGIN_AUTH = 0x00080000;

    private readonly \Closure $checkError;
    private readonly \Closure $acceptOk;

    /** 两个回调沿用 Connection 的错误解析与 OK 状态更新，不持有额外会话状态。 */
    public function __construct(
        private readonly Config $config,
        private readonly PacketStream $stream,
        callable $checkError,
        callable $acceptOk,
    ) {
        $this->checkError = \Closure::fromCallable($checkError);
        $this->acceptOk = \Closure::fromCallable($acceptOk);
    }

    /**
     * 解析握手信息并发送认证请求。
     */
    public function authenticate(string $handshake, Deadline $deadline): void
    {
        [$options, $expectedName] = TlsOptions::handshakeConfiguration($this->config);
        $this->authenticateWithOptions($handshake, $deadline, $options, $expectedName);
    }

    /** @internal 握手和身份校验沿用建连入口确定的配置。 */
    public function authenticateWithOptions(string $handshake, Deadline $deadline, ?array $tlsOptions, ?string $expectedName): void
    {
        $offset = 0;
        $protocol = Codec::int1($handshake, $offset);
        if ($protocol === 0xff) {
            ($this->checkError)($handshake, 'handshake');
        }
        if ($protocol < 10) {
            throw new Exception("Unsupported MySQL protocol version {$protocol}");
        }
        Codec::nullTerminated($handshake, $offset);
        Codec::int4($handshake, $offset);
        $scramble = substr($handshake, $offset, 8);
        $offset += 9;
        $lowCapabilities = Codec::int2($handshake, $offset);
        if ($offset >= strlen($handshake)) {
            throw new Exception('Old MySQL authentication protocol is not supported');
        }
        $charset = Codec::int1($handshake, $offset);
        Codec::int2($handshake, $offset); // 服务端状态，当前流程无需使用。
        $highCapabilities = Codec::int2($handshake, $offset);
        $serverCapabilities = $lowCapabilities | ($highCapabilities << 16);
        $authLength = Codec::int1($handshake, $offset);
        $offset += 10;
        $secondLength = max(13, $authLength - 8);
        $second = substr($handshake, $offset, min($secondLength, strlen($handshake) - $offset));
        $offset += strlen($second);
        $scramble .= rtrim($second, "\0");
        $scramble = substr($scramble, 0, 20);
        $plugin = $offset < strlen($handshake) ? Codec::nullTerminated($handshake, $offset) : 'mysql_native_password';
        $plugin = $plugin ?: 'mysql_native_password';

        $capabilities = self::CLIENT_LONG_PASSWORD
            | self::CLIENT_LONG_FLAG
            | self::CLIENT_PROTOCOL_41
            | self::CLIENT_TRANSACTIONS
            | self::CLIENT_SECURE_CONNECTION
            | self::CLIENT_PLUGIN_AUTH;
        if ($this->config->getDatabase() !== '') {
            $capabilities |= self::CLIENT_CONNECT_WITH_DB;
        }
        if ($this->config->isCompress()) {
            if (!function_exists('gzcompress') || !function_exists('gzuncompress')) {
                throw new Exception('MySQL compression requires the zlib extension');
            }
            $capabilities |= self::CLIENT_COMPRESS;
        }
        $tls = $tlsOptions !== null;
        if ($tls) {
            if (($serverCapabilities & self::CLIENT_SSL) === 0) {
                throw new Exception('MySQL server does not support TLS; plaintext fallback is disabled');
            }
            $capabilities |= self::CLIENT_SSL;
        }
        $capabilities &= $serverCapabilities;
        $useCompression = ($capabilities & self::CLIENT_COMPRESS) !== 0;
        $token = $this->authToken($plugin, $this->config->getPassword(), $scramble);
        $header = pack('V', $capabilities)
            . pack('V', 0x01000000)
            . chr($charset ?: 45)
            . str_repeat("\0", 23);
        if ($tls) {
            $this->stream->writePacket($header, $deadline, 'TLS request');
            $this->stream->enableTlsWithOptions($tlsOptions, $deadline, $expectedName);
        }
        $payload = $header . $this->config->getUser() . "\0"
            . chr(strlen($token)) . $token;
        if (($capabilities & self::CLIENT_CONNECT_WITH_DB) !== 0) {
            $payload .= $this->config->getDatabase() . "\0";
        }
        if (($capabilities & self::CLIENT_PLUGIN_AUTH) !== 0) {
            $payload .= $plugin . "\0";
        }
        $this->stream->writePacket($payload, $deadline, 'authentication');
        $this->completeAuthentication($plugin, $scramble, $deadline);
        $this->stream->enableCompression($useCompression);
    }

    /**
     * 处理认证切换及完整认证流程。
     */
    private function completeAuthentication(string $plugin, string $scramble, Deadline $deadline): void
    {
        while (true) {
            $packet = $this->stream->readPacket($deadline, 'authentication');
            ($this->checkError)($packet, 'authentication');
            $headerOffset = 0;
            $header = Codec::int1($packet, $headerOffset);
            if ($header === 0x00) {
                ($this->acceptOk)($packet);
                return;
            }
            if ($header === 0xfe) {
                $offset = 1;
                $plugin = Codec::nullTerminated($packet, $offset);
                $scramble = rtrim(substr($packet, $offset), "\0");
                $this->stream->writePacket($this->authToken($plugin, $this->config->getPassword(), $scramble), $deadline, 'authentication switch');
                continue;
            }
            if ($header === 0x01 && ($packet[1] ?? '') === "\x03") {
                continue;
            }
            if ($header === 0x01 && ($packet[1] ?? '') === "\x04" && $plugin === 'caching_sha2_password') {
                if ($this->stream->isTlsEnabled() || $this->config->getPassword() === '') {
                    $this->stream->writePacket($this->config->getPassword() . "\0", $deadline, 'full authentication');
                    continue;
                }
                $this->stream->writePacket("\x02", $deadline, 'public key request');
                $keyPacket = $this->stream->readPacket($deadline, 'public key');
                $publicKey = $keyPacket[0] === "\x01" ? substr($keyPacket, 1) : $keyPacket;
                $plain = Codec::xorBytes($this->config->getPassword() . "\0", $scramble);
                if (!openssl_public_encrypt($plain, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                    throw new Exception('Unable to encrypt caching_sha2_password credentials');
                }
                $this->stream->writePacket($encrypted, $deadline, 'full authentication');
                continue;
            }
            throw new Exception('Unsupported MySQL authentication response');
        }
    }

    /**
     * 根据认证插件生成密码认证数据。
     */
    private function authToken(string $plugin, string $password, string $scramble): string
    {
        if ($password === '') {
            return '';
        }
        return match ($plugin) {
            'mysql_native_password' => Codec::xorBytes(sha1($password, true), sha1($scramble . sha1(sha1($password, true), true), true)),
            'caching_sha2_password' => Codec::xorBytes(hash('sha256', $password, true), hash('sha256', hash('sha256', hash('sha256', $password, true), true) . $scramble, true)),
            default => throw new Exception("Unsupported MySQL authentication plugin {$plugin}"),
        };
    }

}
