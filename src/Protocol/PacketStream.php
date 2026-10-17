<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\ProtocolException;
use EasySwoole\Mysqli\Exception\TimeoutException;
use Swoole\Coroutine\Socket;

/** @internal Owns socket I/O, framing, compression and packet sequence numbers. */
final class PacketStream
{
    private const MAX_PACKET_PAYLOAD = 0xffffff;

    private ?Socket $socket = null;
    private int $sequence = 0;
    private int $compressedSequence = 0;
    private bool $compressionEnabled = false;
    private string $decompressedBuffer = '';
    private int $decompressedOffset = 0;

    public function connect(string $host, int $port, Deadline $deadline): void
    {
        if (\Swoole\Coroutine::getCid() < 0) {
            throw new Exception('The coroutine MySQL client must run inside a Swoole coroutine');
        }
        $this->close();
        $this->socket = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        $timeout = $deadline->remaining();
        if (!$this->socket->connect($host, $port, $timeout)) {
            throw $this->ioException('connect', $timeout);
        }
    }

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

    /** Frame a segmented payload without building a second copy of the complete command. */
    public function writePacketParts(array $parts, Deadline $deadline, string $operation): void
    {
        $total = array_sum(array_map('strlen', $parts));
        foreach (PacketPayload::chunks($parts, self::MAX_PACKET_PAYLOAD) as $chunk) {
            $header = substr(pack('V', strlen($chunk)), 0, 3) . chr($this->sequence++ & 0xff);
            $this->writeProtocolBytes($header . $chunk, $deadline, $operation);
            unset($chunk);
        }
        if ($total % self::MAX_PACKET_PAYLOAD === 0) {
            // An exact multiple (including an empty command) needs a final empty packet.
            $header = "\0\0\0" . chr($this->sequence++ & 0xff);
            $this->writeProtocolBytes($header, $deadline, $operation);
        }
        if ($this->compressionEnabled) {
            // MySQL's compressed net layer counts an extra sequence whenever a
            // classic packet plus its four-byte header crosses the 24-bit frame limit.
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
