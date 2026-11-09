<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\ProtocolException;
use EasySwoole\Mysqli\Exception\TimeoutException;
use Swoole\Coroutine\Socket;
use Swoole\Coroutine\Client as SocketClient;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\TlsMode;

/** @internal 负责 Socket 收发、数据包封装、压缩及包序号管理。 */
final class PacketStream
{
    private const MAX_PACKET_PAYLOAD = 0xffffff;

    private ?Socket $socket = null;
    // 保留所属 Client 以读取证书和处理 TLS 握手；所有数据包仍由导出的同一 Socket 收发。
    private ?SocketClient $tlsClient = null;
    private bool $tlsEnabled = false;
    private int $sequence = 0;
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
        if ($tls) {
            // 检查 OpenSSL 编译能力，不是 5.1 的版本判断；提高最低版本后仍需保留。
            if (!method_exists(SocketClient::class, 'getPeerCert') || !method_exists(SocketClient::class, 'enableSSL')
                || !method_exists(Socket::class, 'sslHandshake')) {
                throw new Exception('TLS requires Swoole built with OpenSSL support');
            }
            $this->tlsClient = new SocketClient(SWOOLE_SOCK_TCP);
            if (!$this->tlsClient->connect($host, $port, $timeout)) {
                $code = $this->tlsClient->errCode;
                $message = $this->tlsClient->errMsg;
                throw $this->ioException('connect', $timeout, $code, $message);
            }
            $this->socket = $this->tlsClient->exportSocket();
        } else {
            $this->socket = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
            if (!$this->socket->connect($host, $port, $timeout)) {
                throw $this->ioException('connect', $timeout);
            }
        }
    }

    /** 将已连接的 TCP 流升级为 TLS；调用前必须已发送 MySQL SSLRequest。 */
    public function enableTls(Config $config, Deadline $deadline): void
    {
        $socket = $this->socket;
        $client = $this->tlsClient;
        if ($socket === null || $client === null) {
            throw new \LogicException('TLS transport was not initialized');
        }
        $options = TlsOptions::fromConfig($config);
        // SWOOLE_51_COMPAT：5.1 在握手时才创建 SSL_CTX，且仅在开启验证时加载 CA。
        // 因此先保存配置，再由 Client::enableSSL() 初始化上下文并提供原生握手错误码。
        // 此处移除 open_ssl 是 enableSSL() 的调用要求，在 6.2 中也不能单独删除。
        // 停止支持 5.1 后的清理边界，见 README 的“Swoole 版本兼容与清理”。
        unset($options['open_ssl']);
        if (!$client->set($options)) {
            throw new Exception('Unable to configure MySQL TLS');
        }
        // SWOOLE_SNI_COMPAT：按验证模式和 SNI 判断；这不是 Swoole 版本号判断。
        $verifyAfterHandshake = $options['ssl_verify_peer'] && $options['ssl_host_name'] !== '';
        if ($verifyAfterHandshake && !method_exists(SocketClient::class, 'verifyPeerCert')) {
            throw new Exception('Unable to configure separate TLS certificate verification');
        }
        $remaining = $deadline->remaining();
        $seconds = (int) $remaining;
        if (!$socket->setOption(SOL_SOCKET, SO_RCVTIMEO, ['sec' => $seconds, 'usec' => max(1, (int) (($remaining - $seconds) * 1_000_000))])) {
            throw new Exception('Unable to configure TLS handshake timeout');
        }
        $expired = false;
        // 固定总截止时间，避免对端通过持续推进多步握手而无限延长连接过程。
        $timer = \Swoole\Timer::after(max(1, (int) ceil($remaining * 1000)), static function () use ($socket, &$expired): void {
            $expired = true;
            $socket->cancel(SWOOLE_EVENT_READ);
            $socket->cancel(SWOOLE_EVENT_WRITE);
        });
        if ($timer === false) { throw new Exception('Unable to schedule TLS handshake deadline'); }
        try {
            // SWOOLE_51_COMPAT：必须保持验证开启，让 5.1 在创建 SSL_CTX 时加载 CA。
            $certificate = null;
            $ok = @$client->enableSSL();
            if ($expired) { throw new TimeoutException('MySQL TLS handshake timed out', 110); }
            if (!$ok && $verifyAfterHandshake && $client->errCode === SWOOLE_ERROR_SSL_VERIFY_FAILED) {
                $deadline->remaining();
                // SWOOLE_SNI_COMPAT：5.1.8 和 6.2.4 都会根据 SNI 执行原生主机名检查。
                // 仅在证书存在且原生完整链复核通过后完成 TLS 状态，再独立校验身份。
                // 这不是仅供 5.1 使用的分支；停止支持 5.1 时不能直接删除。
                $certificate = $this->verifyTlsCertificate($socket, $client);
                $ok = @$socket->sslHandshake();
                if ($expired) { throw new TimeoutException('MySQL TLS handshake timed out', 110); }
            }
            if (!$ok) { throw $this->ioException('TLS handshake', $remaining, $client->errCode, $client->errMsg); }
            $deadline->remaining();
            if ($verifyAfterHandshake && $certificate === null) {
                $certificate = $this->verifyTlsCertificate($socket, $client);
            }
            if ($config->getTlsMode() === TlsMode::VerifyIdentity) {
                $certificate ??= $client->getPeerCert();
                if ($certificate === false) { throw new Exception('TLS peer certificate is unavailable'); }
                CertificateIdentity::verify($certificate, $config->getTlsServerName() !== '' ? $config->getTlsServerName() : $config->getHost());
            }
            $deadline->remaining();
            $this->tlsEnabled = true;
        } finally {
            if (\Swoole\Timer::exists($timer)) { \Swoole\Timer::clear($timer); }
        }
    }

    /** 独立于 SNI 复核原生证书链，保留服务端提供的中间证书参与验证。 */
    private function verifyTlsCertificate(Socket $socket, SocketClient $client): string
    {
        // SWOOLE_SNI_COMPAT：此时清除上下文中的主机名，不会改变已经发送的 SNI。
        if (!$socket->setProtocol(['ssl_verify_host' => false, 'ssl_host_name' => ''])
            || ($certificate = $client->getPeerCert()) === false || !@$client->verifyPeerCert(false)) {
            throw new Exception('MySQL TLS certificate verification failed');
        }
        return $certificate;
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
            if ($receivedSequence !== $expectedSequence) {
                $this->close();
                throw new ProtocolException(sprintf('MySQL compressed packet sequence mismatch: expected %d, got %d', $expectedSequence, $receivedSequence));
            }
            $this->compressedSequence = ($this->compressedSequence + 1) & 0xff;
            $uncompressedLength = ord($header[4]) | (ord($header[5]) << 8) | (ord($header[6]) << 16);
            $payload = $this->receiveExactly($compressedLength, $deadline, $operation);
            if ($uncompressedLength !== 0) {
                $decoded = @gzuncompress($payload, $uncompressedLength);
                if ($decoded === false || strlen($decoded) !== $uncompressedLength) {
                    $this->close();
                    throw new ProtocolException('Invalid MySQL compressed packet');
                }
                $payload = $decoded;
            }
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

    /**
     * 在剩余预算内发送全部字节。
     */
    private function sendExactly(string $data, Deadline $deadline, string $operation): void
    {
        $remaining = $deadline->remaining();
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
