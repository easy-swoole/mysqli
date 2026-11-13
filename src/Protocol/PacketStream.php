<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\ProtocolException;
use EasySwoole\Mysqli\Exception\TimeoutException;
use Swoole\Coroutine\Socket;
use Swoole\Coroutine\Client as SocketClient;
use EasySwoole\Mysqli\Config;

/** @internal 负责 Socket 收发、数据包封装、压缩及包序号管理。 */
final class PacketStream
{
    public const ER_CLIENT_INTERACTION_TIMEOUT = 4031;

    private const MAX_PACKET_PAYLOAD = 0xffffff;
    private const MAX_IDLE_ERROR_PAYLOAD = 1024;

    private ?Socket $socket = null;
    // 保留所属 Client 以读取证书和处理 TLS 握手；所有数据包仍由导出的同一 Socket 收发。
    private ?SocketClient $tlsClient = null;
    private bool $tlsEnabled = false;
    private int $sequence = 0;
    private int $writeAttempts = 0;
    private int $compressedSequence = 0;
    private bool $compressionEnabled = false;
    private string $decompressedBuffer = '';
    private int $decompressedOffset = 0;

    public function connect(string $host, int $port, Deadline $deadline, bool $tls = false): void
    {
        if (\Swoole\Coroutine::getCid() < 0) {
            throw new Exception('The coroutine MySQL client must run inside a Swoole coroutine');
        }
        $this->close();
        $timeout = $deadline->remaining();
        $ipv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if ($tls) {
            // 检查 OpenSSL 编译能力，不是 5.1 的版本判断；提高最低版本后仍需保留。
            if (!method_exists(SocketClient::class, 'getPeerCert') || !method_exists(SocketClient::class, 'enableSSL')
                || !method_exists(Socket::class, 'sslHandshake')) {
                throw new Exception('TLS requires Swoole built with OpenSSL support');
            }
            $this->tlsClient = new SocketClient($ipv6 ? SWOOLE_SOCK_TCP6 : SWOOLE_SOCK_TCP);
            if (!$this->tlsClient->connect($host, $port, $timeout)) {
                $code = $this->tlsClient->errCode;
                $message = $this->tlsClient->errMsg;
                throw $this->ioException('connect', $timeout, $code, $message);
            }
            $this->socket = $this->tlsClient->exportSocket();
        } else {
            $this->socket = new Socket($ipv6 ? AF_INET6 : AF_INET, SOCK_STREAM, IPPROTO_IP);
            if (!$this->socket->connect($host, $port, $timeout)) {
                throw $this->ioException('connect', $timeout);
            }
        }
    }

    /** 将已连接的 TCP 流升级为 TLS；调用前必须已发送 MySQL SSLRequest。 */
    public function enableTls(Config $config, Deadline $deadline): void
    {
        $this->enableTlsWithOptions($config, $deadline, null);
    }

    /** @internal 升级连接时使用建连入口生成的选项。 */
    public function enableTlsWithOptions(array|Config $options, Deadline $deadline, ?string $expectedName): void
    {
        $socket = $this->socket;
        $client = $this->tlsClient;
        if ($socket === null || $client === null) {
            throw new \LogicException('TLS transport was not initialized');
        }
        TlsHandshake::enableWithOptions($socket, $client, $options, $deadline,
            fn(float $timeout, int $code, string $message): Exception => $this->ioException('TLS handshake', $timeout, $code, $message),
            $expectedName);
        $this->tlsEnabled = true;
    }

    public function isTlsEnabled(): bool { return $this->tlsEnabled && $this->isOpen(); }

    public function isOpen(): bool
    {
        return $this->socket !== null && !$this->socket->isClosed();
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    public function enableCompression(bool $enabled): void
    {
        $this->compressionEnabled = $enabled;
        $this->compressedSequence = 0;
        $this->decompressedBuffer = '';
        $this->decompressedOffset = 0;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
        $this->tlsClient = null;
        $this->tlsEnabled = false;
        $this->sequence = 0;
        $this->enableCompression(false);
    }

    /**
     * 重置新命令的包序号及解压缓冲区。
     */
    public function beginCommand(): void
    {
        $this->sequence = 0;
        $this->compressedSequence = 0;
        $this->decompressedBuffer = '';
        $this->decompressedOffset = 0;
    }

    /**
     * 按协议分片并发送完整数据包。
     */
    public function writePacket(string $payload, Deadline $deadline, string $operation): void
    {
        $this->writePacketParts([$payload], $deadline, $operation);
    }

    /** 为分段负载封装数据包，避免额外复制整条命令。 */
    public function writePacketParts(array $parts, Deadline $deadline, string $operation): void
    {
        $total = array_sum(array_map('strlen', $parts));
        foreach (PacketPayload::chunks($parts, self::MAX_PACKET_PAYLOAD) as $chunk) {
            $header = substr(pack('V', strlen($chunk)), 0, 3) . chr($this->sequence++ & 0xff);
            $this->writeProtocolBytes($header . $chunk, $deadline, $operation);
            unset($chunk);
        }
        if ($total % self::MAX_PACKET_PAYLOAD === 0) {
            // 负载长度恰为最大包长的整数倍时（包括空命令），还需发送一个空包作为结束标记。
            $header = "\0\0\0" . chr($this->sequence++ & 0xff);
            $this->writeProtocolBytes($header, $deadline, $operation);
        }
        if ($this->compressionEnabled) {
            // 普通协议包连同四字节包头超过压缩帧的 24 位长度上限时，
            // MySQL 压缩传输层会额外占用一个序号。
            $this->sequence = $this->compressedSequence;
        }
    }

    /**
     * 校验包序号并重组完整数据包。
     */
    public function readPacket(Deadline $deadline, string $operation): string
    {
        $payload = '';
        do {
            $header = $this->receiveProtocolExactly(4, $deadline, $operation);
            $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $receivedSequence = ord($header[3]);
            $expectedSequence = $this->sequence & 0xff;
            if ($receivedSequence !== $expectedSequence) {
                // 服务端在两条命令之间发送的空闲超时错误使用序号 0。
                if ($receivedSequence === 0 && $payload === '' && $length >= 9 && $length <= self::MAX_IDLE_ERROR_PAYLOAD) {
                    $error = $this->receiveProtocolExactly($length, $deadline, $operation);
                    if (self::isIdleTimeoutError($error)) {
                        return $error;
                    }
                }
                $this->close();
                throw new ProtocolException(sprintf('MySQL packet sequence mismatch: expected %d, got %d', $expectedSequence, $receivedSequence));
            }
            $this->sequence = ($this->sequence + 1) & 0xff;
            $payload .= $this->receiveProtocolExactly($length, $deadline, $operation);
        } while ($length === self::MAX_PACKET_PAYLOAD);
        return $payload;
    }

    /**
     * 按压缩协商状态发送协议字节。
     */
    private function writeProtocolBytes(string $data, Deadline $deadline, string $operation): void
    {
        if (!$this->compressionEnabled) {
            $this->sendExactly($data, $deadline, $operation);
            return;
        }

        $offset = 0;
        $total = strlen($data);
        while ($offset < $total) {
            $chunk = substr($data, $offset, min(self::MAX_PACKET_PAYLOAD, $total - $offset));
            $compressed = gzcompress($chunk);
            if ($compressed !== false && strlen($compressed) < strlen($chunk)) {
                $payload = $compressed;
                $uncompressedLength = strlen($chunk);
            } else {
                unset($compressed);
                $payload = $chunk;
                $uncompressedLength = 0;
            }
            $header = substr(pack('V', strlen($payload)), 0, 3)
                . chr($this->compressedSequence++ & 0xff)
                . substr(pack('V', $uncompressedLength), 0, 3);
            $this->sendExactly($header, $deadline, $operation);
            $this->sendExactly($payload, $deadline, $operation);
            $offset += strlen($chunk);
        }
    }

    /**
     * 读取指定长度的协议字节，必要时解压。
     */
    private function receiveProtocolExactly(int $length, Deadline $deadline, string $operation): string
    {
        if (!$this->compressionEnabled) {
            return $this->receiveExactly($length, $deadline, $operation);
        }

        while (strlen($this->decompressedBuffer) - $this->decompressedOffset < $length) {
            if ($this->decompressedOffset !== 0) {
                $this->decompressedBuffer = substr($this->decompressedBuffer, $this->decompressedOffset);
                $this->decompressedOffset = 0;
            }
            $header = $this->receiveExactly(7, $deadline, $operation);
            $compressedLength = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $receivedSequence = ord($header[3]);
            $expectedSequence = $this->compressedSequence & 0xff;
            $uncompressedLength = ord($header[4]) | (ord($header[5]) << 8) | (ord($header[6]) << 16);
            $outOfSequence = $receivedSequence !== $expectedSequence;
            $idleFrameLimit = self::MAX_IDLE_ERROR_PAYLOAD + 4;
            if ($outOfSequence && ($receivedSequence !== 0 || $this->decompressedBuffer !== ''
                || $compressedLength > $idleFrameLimit || $uncompressedLength > $idleFrameLimit)) {
                $this->rejectCompressedSequence($expectedSequence, $receivedSequence);
            }
            $payload = $this->receiveExactly($compressedLength, $deadline, $operation);
            if ($uncompressedLength !== 0) {
                $decoded = @gzuncompress($payload, $uncompressedLength);
                if ($decoded === false || strlen($decoded) !== $uncompressedLength) {
                    $this->close();
                    throw new ProtocolException('Invalid MySQL compressed packet');
                }
                $payload = $decoded;
            }
            if ($outOfSequence && !self::isIdleTimeoutFrame($payload)) {
                $this->rejectCompressedSequence($expectedSequence, $receivedSequence);
            }
            $this->compressedSequence = ($receivedSequence + 1) & 0xff;
            $this->decompressedBuffer .= $payload;
        }

        $data = substr($this->decompressedBuffer, $this->decompressedOffset, $length);
        $this->decompressedOffset += $length;
        if ($this->decompressedOffset === strlen($this->decompressedBuffer)) {
            $this->decompressedBuffer = '';
            $this->decompressedOffset = 0;
        }
        return $data;
    }

    private static function isIdleTimeoutFrame(string $payload): bool
    {
        // 压缩帧必须恰好包含一个序号为 0 的完整 ERR 报文。
        $offset = 0;
        return strlen($payload) >= 13 && $payload[3] === "\0"
            && Codec::int3($payload, $offset) === strlen($payload) - 4
            && self::isIdleTimeoutError(substr($payload, 4));
    }

    private static function isIdleTimeoutError(string $payload): bool
    {
        return str_starts_with($payload, "\xff" . pack('v', self::ER_CLIENT_INTERACTION_TIMEOUT) . '#HY000');
    }

    private function rejectCompressedSequence(int $expected, int $received): never
    {
        $this->close();
        throw new ProtocolException(sprintf('MySQL compressed packet sequence mismatch: expected %d, got %d', $expected, $received));
    }

    /** @internal 记录进入 Socket 发送的次数，区分发包前和传输中的超时。 */
    public function writeAttempts(): int
    {
        return $this->writeAttempts;
    }

    /**
     * 在剩余预算内发送全部字节。
     */
    private function sendExactly(string $data, Deadline $deadline, string $operation): void
    {
        $remaining = $deadline->remaining();
        $this->writeAttempts++;
        $written = $this->socket?->sendAll($data, $remaining);
        if ($written !== strlen($data)) {
            throw $this->ioException($operation, $remaining);
        }
    }

    /**
     * 在指定超时内读取完整字节串。
     */
    private function receiveExactly(int $length, Deadline $deadline, string $operation): string
    {
        $timeout = $deadline->remaining();
        if ($length === 0) {
            return '';
        }
        $data = $this->socket?->recvAll($length, $timeout);
        if ($data === false || strlen($data) !== $length) {
            throw $this->ioException($operation, $timeout);
        }
        return $data;
    }

    /**
     * 关闭失效连接并生成 Socket 错误或超时异常。
     */
    private function ioException(string $operation, float $timeout, ?int $code = null, ?string $message = null): Exception
    {
        $code ??= $this->socket?->errCode ?? 0;
        $message = $message ?: ($this->socket?->errMsg ?: 'socket I/O failed');
        if (in_array($code, [110, 60], true) || str_contains(strtolower($message), 'timed out')) {
            $this->close();
            return new TimeoutException(sprintf('MySQL %s timed out after %.3f seconds', $operation, $timeout), $code ?: 110);
        }
        $this->close();
        return new Exception("MySQL {$operation} failed: {$message}", $code);
    }

}
