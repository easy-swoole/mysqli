<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\TimeoutException;
use Swoole\Coroutine\Socket;

final class Connection
{
    private const CLIENT_LONG_PASSWORD = 0x00000001;
    private const CLIENT_LONG_FLAG = 0x00000004;
    private const CLIENT_CONNECT_WITH_DB = 0x00000008;
    private const CLIENT_COMPRESS = 0x00000020;
    private const CLIENT_PROTOCOL_41 = 0x00000200;
    private const CLIENT_TRANSACTIONS = 0x00002000;
    private const CLIENT_SECURE_CONNECTION = 0x00008000;
    private const CLIENT_PLUGIN_AUTH = 0x00080000;
    private const COM_QUIT = 0x01;
    private const COM_QUERY = 0x03;
    private const COM_PING = 0x0e;
    private const COM_STMT_PREPARE = 0x16;
    private const COM_STMT_EXECUTE = 0x17;
    private const COM_STMT_CLOSE = 0x19;
    private const MAX_PACKET_PAYLOAD = 0xffffff;

    private ?Socket $socket = null;
    private bool $connected = false;
    private int $sequence = 0;
    private int $serverCapabilities = 0;
    private int $compressedSequence = 0;
    private bool $compressionEnabled = false;
    private string $decompressedBuffer = '';
    private float $timeout;
    private bool $busy = false;
    private int $generation = 0;

    public string $connect_error = '';
    public int $connect_errno = 0;
    public string $error = '';
    public int $errno = 0;
    public int|string $insert_id = 0;
    public int $affected_rows = 0;

    public function __construct(private readonly Config $config)
    {
        $this->timeout = $config->getTimeout();
    }

    public function connect(?float $timeout = null): bool
    {
        return $this->guard(fn(): bool => $this->connectInternal($this->deadline($timeout ?? $this->config->getMaxConnectTime())));
    }

    private function connectInternal(float $deadline): bool
    {
        if ($this->isConnected()) {
            return true;
        }
        if ($this->socket !== null || $this->connected) {
            $this->closeSocket();
        }
        if (\Swoole\Coroutine::getCid() < 0) {
            throw new Exception('The coroutine MySQL client must run inside a Swoole coroutine');
        }

        $this->connect_error = '';
        $this->connect_errno = 0;
        $deadline = min($deadline, $this->deadline($this->config->getMaxConnectTime()));
        $socket = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        if (!$socket->connect($this->config->getHost(), $this->config->getPort(), $this->remaining($deadline))) {
            $this->connect_errno = $socket->errCode;
            $this->connect_error = $socket->errMsg ?: 'connection failed';
            $socket->close();
            throw $this->ioException('connect', $this->config->getMaxConnectTime(), $socket->errCode, $socket->errMsg);
        }
        $this->socket = $socket;

        try {
            $this->sequence = 0;
            $handshake = $this->readPacket($this->remaining($deadline), 'handshake');
            $this->authenticate($handshake, $deadline);
            $this->connected = true;
            $charset = $this->config->getCharset();
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
                throw new \InvalidArgumentException('Invalid MySQL charset');
            }
            $this->beginCommand();
            $this->writePacket(chr(self::COM_QUERY) . "SET NAMES {$charset}", $this->remaining($deadline), 'charset');
            $this->readResponse($deadline, false, 'SET NAMES');
            $this->generation++;
            $this->timeout = $this->config->getTimeout();
            return true;
        } catch (\Throwable $error) {
            $this->connect_errno = $error->getCode();
            $this->connect_error = $error->getMessage();
            $this->closeSocket();
            throw $error;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null && !$this->socket->isClosed();
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    public function query(string $sql, ?float $timeout = null): array|bool
    {
        return $this->command(self::COM_QUERY, $sql, $timeout, false);
    }

    public function prepare(string $sql, ?float $timeout = null): Statement
    {
        return $this->guard(function () use ($sql, $timeout): Statement {
            $deadline = $this->deadline($timeout);
            $this->ensureConnected($deadline);
            $this->beginCommand();
            $this->writePacket(chr(self::COM_STMT_PREPARE) . $sql, $this->remaining($deadline), 'prepare');
            $packet = $this->readPacket($this->remaining($deadline), 'prepare');
            $this->throwIfError($packet, $sql);
            if (($packet[0] ?? '') !== "\0") {
                throw new Exception('Malformed COM_STMT_PREPARE response');
            }
            $offset = 1;
            $statementId = Codec::int4($packet, $offset);
            $columnCount = Codec::int2($packet, $offset);
            $parameterCount = Codec::int2($packet, $offset);
            $offset++;
            $warningCount = Codec::int2($packet, $offset);

            $this->consumeDefinitions($parameterCount, $deadline);
            $this->consumeDefinitions($columnCount, $deadline);
            return new Statement($this, $statementId, $sql, $parameterCount, $columnCount, $warningCount, $this->generation);
        });
    }

    public function executeStatement(Statement $statement, array $parameters, ?float $timeout): array|bool
    {
        if (count($parameters) !== $statement->getParameterCount()) {
            throw new \InvalidArgumentException(sprintf(
                'Statement expects %d parameters, %d given',
                $statement->getParameterCount(),
                count($parameters),
            ));
        }

        return $this->guard(function () use ($statement, $parameters, $timeout): array|bool {
            $this->assertStatementGeneration($statement->getGeneration());
            $deadline = $this->deadline($timeout);
            [$nullBitmap, $types, $values] = $this->encodeParameters($parameters);
            $payload = chr(self::COM_STMT_EXECUTE)
                . pack('V', $statement->getId())
                . "\0"
                . pack('V', 1)
                . $nullBitmap
                . ($parameters === [] ? '' : "\1" . $types . $values);
            $this->resetMetadata();
            $this->beginCommand();
            $this->writePacket($payload, $this->remaining($deadline), 'execute');
            return $this->readResponse($deadline, true, $statement->getSql());
        });
    }

    private function assertStatementGeneration(int $generation): void
    {
        if (!$this->isConnected() || $generation !== $this->generation) {
            throw new \LogicException('The prepared statement belongs to an expired MySQL session');
        }
    }

    public function closeStatement(int $statementId, ?int $generation = null, ?float $timeout = null): void
    {
        // A stale statement must never send its ID to another server session.
        if (!$this->isConnected() || ($generation !== null && $generation !== $this->generation)) {
            return;
        }
        $this->guard(function () use ($statementId, $timeout): void {
            if ($timeout !== null && $timeout <= 0) {
                $this->closeSocket();
                return;
            }
            $this->beginCommand();
            $this->writePacket(chr(self::COM_STMT_CLOSE) . pack('V', $statementId), $timeout ?? $this->timeout, 'close statement');
        });
    }

    public function ping(): bool
    {
        return $this->command(self::COM_PING, '', $this->timeout, false) === true;
    }

    public function begin_transaction(int $flags = 0): bool
    {
        return $this->query('START TRANSACTION') === true;
    }

    public function commit(int $flags = 0): bool
    {
        return $this->query('COMMIT') === true;
    }

    public function rollback(int $flags = 0): bool
    {
        return $this->query('ROLLBACK') === true;
    }

    public function select_db(string $database): bool
    {
        $escaped = str_replace('`', '``', $database);
        return $this->query("USE `{$escaped}`") === true;
    }

    public function set_charset(string $charset)
    {
        $timeout = func_get_args()[1] ?? null;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
            throw new \InvalidArgumentException('Invalid MySQL charset');
        }
        return $this->query("SET NAMES {$charset}", $timeout) === true;
    }

    public function close(): bool
    {
        return $this->guard(fn(): bool => $this->closeInternal());
    }

    private function closeInternal(): bool
    {
        if ($this->isConnected()) {
            try {
                $this->beginCommand();
                $this->writePacket(chr(self::COM_QUIT), min(0.1, $this->timeout), 'quit');
            } catch (\Throwable) {
            }
        }
        $this->closeSocket();
        return true;
    }

    private function command(int $command, string $payload, ?float $timeout, bool $binary): array|bool
    {
        return $this->guard(function () use ($command, $payload, $timeout, $binary): array|bool {
            $deadline = $this->deadline($timeout);
            $this->ensureConnected($deadline);
            $this->resetMetadata();
            $this->beginCommand();
            $this->writePacket(chr($command) . $payload, $this->remaining($deadline), 'query');
            return $this->readResponse($deadline, $binary, $payload);
        });
    }

    private function readResponse(float $deadline, bool $binary, string $sql): array|bool
    {
        $packet = $this->readPacket($this->remaining($deadline), 'query');
        $this->throwIfError($packet, $sql);
        $header = ord($packet[0]);
        if ($header === 0x00) {
            $this->parseOk($packet);
            return true;
        }
        if ($header === 0xfb) {
            throw new Exception('LOCAL INFILE is not supported');
        }

        $offset = 0;
        $columnCount = Codec::lenencInt($packet, $offset);
        if ($columnCount === null) {
            throw new Exception('Malformed result-set header');
        }
        $columns = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $columns[] = $this->parseColumn($this->readPacket($this->remaining($deadline), 'column metadata'));
        }
        $terminator = $this->readPacket($this->remaining($deadline), 'column terminator');
        $this->throwIfError($terminator, $sql);
        if (!$this->isEof($terminator) && ord($terminator[0]) !== 0x00) {
            throw new Exception('Malformed result-set column terminator');
        }

        $rows = [];
        while (true) {
            $row = $this->readPacket($this->remaining($deadline), 'result row');
            $this->throwIfError($row, $sql);
            if ($this->isEof($row)) {
                break;
            }
            $rows[] = $binary ? $this->parseBinaryRow($row, $columns) : $this->parseTextRow($row, $columns);
        }
        $this->affected_rows = count($rows);
        return $rows;
    }

    private function authenticate(string $handshake, float $deadline): void
    {
        $offset = 0;
        $protocol = Codec::int1($handshake, $offset);
        if ($protocol === 0xff) {
            $this->throwIfError($handshake, 'handshake');
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
        Codec::int2($handshake, $offset); // server status, currently not needed
        $highCapabilities = Codec::int2($handshake, $offset);
        $this->serverCapabilities = $lowCapabilities | ($highCapabilities << 16);
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
        $capabilities &= $this->serverCapabilities;
        $useCompression = ($capabilities & self::CLIENT_COMPRESS) !== 0;
        $token = $this->authToken($plugin, $this->config->getPassword(), $scramble);
        $payload = pack('V', $capabilities)
            . pack('V', 0x01000000)
            . chr($charset ?: 45)
            . str_repeat("\0", 23)
            . $this->config->getUser() . "\0"
            . chr(strlen($token)) . $token;
        if (($capabilities & self::CLIENT_CONNECT_WITH_DB) !== 0) {
            $payload .= $this->config->getDatabase() . "\0";
        }
        if (($capabilities & self::CLIENT_PLUGIN_AUTH) !== 0) {
            $payload .= $plugin . "\0";
        }
        $this->writePacket($payload, $this->remaining($deadline), 'authentication');
        $this->completeAuthentication($plugin, $scramble, $deadline);
        $this->compressionEnabled = $useCompression;
        $this->compressedSequence = 0;
        $this->decompressedBuffer = '';
    }

    private function completeAuthentication(string $plugin, string $scramble, float $deadline): void
    {
        while (true) {
            $packet = $this->readPacket($this->remaining($deadline), 'authentication');
            $this->throwIfError($packet, 'authentication');
            $header = ord($packet[0]);
            if ($header === 0x00) {
                $this->parseOk($packet);
                return;
            }
            if ($header === 0xfe) {
                $offset = 1;
                $plugin = Codec::nullTerminated($packet, $offset);
                $scramble = rtrim(substr($packet, $offset), "\0");
                $this->writePacket($this->authToken($plugin, $this->config->getPassword(), $scramble), $this->remaining($deadline), 'authentication switch');
                continue;
            }
            if ($header === 0x01 && ($packet[1] ?? '') === "\x03") {
                continue;
            }
            if ($header === 0x01 && ($packet[1] ?? '') === "\x04" && $plugin === 'caching_sha2_password') {
                if ($this->config->getPassword() === '') {
                    $this->writePacket("\0", $this->remaining($deadline), 'full authentication');
                    continue;
                }
                $this->writePacket("\x02", $this->remaining($deadline), 'public key request');
                $keyPacket = $this->readPacket($this->remaining($deadline), 'public key');
                $publicKey = $keyPacket[0] === "\x01" ? substr($keyPacket, 1) : $keyPacket;
                $plain = Codec::xorBytes($this->config->getPassword() . "\0", $scramble);
                if (!openssl_public_encrypt($plain, $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
                    throw new Exception('Unable to encrypt caching_sha2_password credentials');
                }
                $this->writePacket($encrypted, $this->remaining($deadline), 'full authentication');
                continue;
            }
            throw new Exception('Unsupported MySQL authentication response');
        }
    }

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

    private function consumeDefinitions(int $count, float $deadline): void
    {
        for ($i = 0; $i < $count; $i++) {
            $packet = $this->readPacket($this->remaining($deadline), 'prepare metadata');
            $this->throwIfError($packet, 'prepare');
        }
        if ($count > 0) {
            $terminator = $this->readPacket($this->remaining($deadline), 'prepare metadata terminator');
            $this->throwIfError($terminator, 'prepare');
        }
    }

    private function parseColumn(string $packet): Column
    {
        $offset = 0;
        for ($i = 0; $i < 4; $i++) {
            Codec::lenencString($packet, $offset);
        }
        $name = Codec::lenencString($packet, $offset) ?? '';
        Codec::lenencString($packet, $offset);
        Codec::lenencInt($packet, $offset);
        Codec::int2($packet, $offset);
        Codec::int4($packet, $offset);
        $type = Codec::int1($packet, $offset);
        $flags = Codec::int2($packet, $offset);
        return new Column($name, $type, $flags);
    }

    private function parseTextRow(string $packet, array $columns): array
    {
        $offset = 0;
        $row = [];
        foreach ($columns as $column) {
            $value = Codec::lenencString($packet, $offset);
            $row[$column->name] = $this->castTextValue($value, $column);
        }
        return $row;
    }

    private function castTextValue(?string $value, Column $column): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($column->type) {
            Column::TYPE_TINY,
            Column::TYPE_SHORT,
            Column::TYPE_LONG,
            Column::TYPE_LONGLONG,
            Column::TYPE_INT24,
            Column::TYPE_YEAR => $this->integerValue($value, $column->isUnsigned()),
            Column::TYPE_FLOAT, Column::TYPE_DOUBLE => (float) $value,
            default => $value,
        };
    }

    private function parseBinaryRow(string $packet, array $columns): array
    {
        if (($packet[0] ?? '') !== "\0") {
            throw new Exception('Malformed binary result row');
        }
        $offset = 1;
        $bitmapLength = intdiv(count($columns) + 9, 8);
        $bitmap = substr($packet, $offset, $bitmapLength);
        $offset += $bitmapLength;
        $row = [];
        foreach ($columns as $index => $column) {
            $bit = $index + 2;
            if ((ord($bitmap[intdiv($bit, 8)]) & (1 << ($bit % 8))) !== 0) {
                $row[$column->name] = null;
                continue;
            }
            $row[$column->name] = $this->decodeBinaryValue($packet, $offset, $column);
        }
        return $row;
    }

    private function decodeBinaryValue(string $packet, int &$offset, Column $column): mixed
    {
        return match ($column->type) {
            Column::TYPE_TINY => $this->unpackInteger($packet, $offset, 1, $column->isUnsigned()),
            Column::TYPE_SHORT, Column::TYPE_YEAR => $this->unpackInteger($packet, $offset, 2, $column->isUnsigned()),
            Column::TYPE_LONG, Column::TYPE_INT24 => $this->unpackInteger($packet, $offset, 4, $column->isUnsigned()),
            Column::TYPE_LONGLONG => $this->unpackInteger($packet, $offset, 8, $column->isUnsigned()),
            Column::TYPE_FLOAT => $this->unpackFloat($packet, $offset, 4),
            Column::TYPE_DOUBLE => $this->unpackFloat($packet, $offset, 8),
            Column::TYPE_TIMESTAMP,
            Column::TYPE_DATE,
            Column::TYPE_TIME,
            Column::TYPE_DATETIME => $this->unpackTemporal($packet, $offset, $column->type),
            default => Codec::lenencString($packet, $offset),
        };
    }

    private function unpackInteger(string $packet, int &$offset, int $bytes, bool $unsigned): int|string
    {
        $raw = substr($packet, $offset, $bytes);
        $offset += $bytes;
        if ($bytes === 1) {
            $value = ord($raw);
            return !$unsigned && $value >= 128 ? $value - 256 : $value;
        }
        if ($bytes === 2) {
            $value = unpack('v', $raw)[1];
            return !$unsigned && $value >= 0x8000 ? $value - 0x10000 : $value;
        }
        if ($bytes === 4) {
            $value = unpack('V', $raw)[1];
            return !$unsigned && $value >= 0x80000000 ? $value - 0x100000000 : $value;
        }
        $value = unpack('P', $raw)[1];
        if (!$unsigned || $value >= 0) {
            return $value;
        }
        // PHP stores the bit pattern as a signed integer. %u converts that
        // exact pattern to decimal without passing through a float.
        return sprintf('%u', $value);
    }

    private function unpackFloat(string $packet, int &$offset, int $bytes): float
    {
        $raw = substr($packet, $offset, $bytes);
        $offset += $bytes;
        return unpack($bytes === 4 ? 'g' : 'e', $raw)[1];
    }

    private function unpackTemporal(string $packet, int &$offset, int $type): string
    {
        $length = Codec::int1($packet, $offset);
        if ($length === 0) {
            return $type === Column::TYPE_TIME ? '00:00:00' : '0000-00-00';
        }
        $start = $offset;
        if ($type === Column::TYPE_TIME) {
            $negative = Codec::int1($packet, $offset) === 1;
            $days = Codec::int4($packet, $offset);
            $hours = Codec::int1($packet, $offset) + ($days * 24);
            $minutes = Codec::int1($packet, $offset);
            $seconds = Codec::int1($packet, $offset);
            $microseconds = $length > 8 ? Codec::int4($packet, $offset) : 0;
            return sprintf('%s%02d:%02d:%02d%s', $negative ? '-' : '', $hours, $minutes, $seconds, $microseconds ? sprintf('.%06d', $microseconds) : '');
        }
        $year = Codec::int2($packet, $offset);
        $month = Codec::int1($packet, $offset);
        $day = Codec::int1($packet, $offset);
        $value = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($length > 4) {
            $hour = Codec::int1($packet, $offset);
            $minute = Codec::int1($packet, $offset);
            $second = Codec::int1($packet, $offset);
            $value .= sprintf(' %02d:%02d:%02d', $hour, $minute, $second);
            if ($length > 7) {
                $value .= sprintf('.%06d', Codec::int4($packet, $offset));
            }
        }
        $offset = $start + $length;
        return $value;
    }

    private function encodeParameters(array $parameters): array
    {
        $bitmap = str_repeat("\0", intdiv(count($parameters) + 7, 8));
        $types = '';
        $values = '';
        foreach (array_values($parameters) as $index => $value) {
            if ($value === null) {
                $byte = intdiv($index, 8);
                $bitmap[$byte] = chr(ord($bitmap[$byte]) | (1 << ($index % 8)));
                $types .= chr(Column::TYPE_NULL) . "\0";
            } elseif (is_bool($value)) {
                $types .= chr(Column::TYPE_TINY) . "\0";
                $values .= chr($value ? 1 : 0);
            } elseif (is_int($value)) {
                $types .= chr(Column::TYPE_LONGLONG) . "\0";
                $values .= pack('P', $value);
            } elseif (is_float($value)) {
                $types .= chr(Column::TYPE_DOUBLE) . "\0";
                $values .= pack('e', $value);
            } elseif (is_string($value)) {
                $types .= chr(Column::TYPE_VAR_STRING) . "\0";
                $values .= Codec::encodeLenencString($value);
            } else {
                throw new \InvalidArgumentException('Unsupported bind parameter type: ' . get_debug_type($value));
            }
        }
        return [$bitmap, $types, $values];
    }

    private function parseOk(string $packet): void
    {
        $offset = 1;
        $this->affected_rows = Codec::lenencInt($packet, $offset) ?? 0;
        $this->insert_id = Codec::lenencInt($packet, $offset) ?? 0;
    }

    private function throwIfError(string $packet, string $context): void
    {
        if (($packet[0] ?? '') !== "\xff") {
            return;
        }
        $offset = 1;
        $code = Codec::int2($packet, $offset);
        if (($packet[$offset] ?? '') === '#') {
            $offset += 6;
        }
        $message = substr($packet, $offset);
        $this->errno = $code;
        $this->error = $message;
        throw new Exception("{$context}: {$message}", $code);
    }

    private function isEof(string $packet): bool
    {
        return ($packet[0] ?? '') === "\xfe" && strlen($packet) < 9;
    }

    private function integerValue(string $value, bool $unsigned): int|string
    {
        if ($unsigned && self::compareUnsignedDecimal($value, (string) PHP_INT_MAX) > 0) {
            return $value;
        }
        return (int) $value;
    }

    private function beginCommand(): void
    {
        $this->sequence = 0;
        $this->compressedSequence = 0;
        $this->decompressedBuffer = '';
    }

    private function writePacket(string $payload, float $timeout, string $operation): void
    {
        $deadline = microtime(true) + $timeout;
        $offset = 0;
        $total = strlen($payload);
        do {
            $length = min(self::MAX_PACKET_PAYLOAD, $total - $offset);
            $chunk = substr($payload, $offset, $length);
            $header = substr(pack('V', $length), 0, 3) . chr($this->sequence++ & 0xff);
            $this->writeProtocolBytes($header . $chunk, $deadline, $operation);
            $offset += $length;
        } while ($length === self::MAX_PACKET_PAYLOAD);
        if ($this->compressionEnabled) {
            // MySQL's compressed net layer counts an extra sequence whenever a
            // classic packet plus its four-byte header crosses the 24-bit frame limit.
            $this->sequence = $this->compressedSequence;
        }
    }

    private function readPacket(float $timeout, string $operation): string
    {
        $deadline = microtime(true) + $timeout;
        $payload = '';
        do {
            $header = $this->receiveProtocolExactly(4, $deadline, $operation);
            $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $receivedSequence = ord($header[3]);
            if ($receivedSequence !== ($this->sequence & 0xff)) {
                $this->closeSocket();
                throw new Exception(sprintf('MySQL packet sequence mismatch: expected %d, got %d', $this->sequence & 0xff, $receivedSequence));
            }
            $this->sequence = ($this->sequence + 1) & 0xff;
            $payload .= $this->receiveProtocolExactly($length, $deadline, $operation);
        } while ($length === self::MAX_PACKET_PAYLOAD);
        return $payload;
    }

    private function writeProtocolBytes(string $data, float $deadline, string $operation): void
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
                $payload = $chunk;
                $uncompressedLength = 0;
            }
            $header = substr(pack('V', strlen($payload)), 0, 3)
                . chr($this->compressedSequence++ & 0xff)
                . substr(pack('V', $uncompressedLength), 0, 3);
            $this->sendExactly($header . $payload, $deadline, $operation);
            $offset += strlen($chunk);
        }
    }

    private function receiveProtocolExactly(int $length, float $deadline, string $operation): string
    {
        if (!$this->compressionEnabled) {
            return $this->receiveExactly($length, $this->remaining($deadline), $operation);
        }

        while (strlen($this->decompressedBuffer) < $length) {
            $header = $this->receiveExactly(7, $this->remaining($deadline), $operation);
            $compressedLength = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $receivedSequence = ord($header[3]);
            if ($receivedSequence !== ($this->compressedSequence & 0xff)) {
                $this->closeSocket();
                throw new Exception(sprintf('MySQL compressed packet sequence mismatch: expected %d, got %d', $this->compressedSequence & 0xff, $receivedSequence));
            }
            $this->compressedSequence = ($this->compressedSequence + 1) & 0xff;
            $uncompressedLength = ord($header[4]) | (ord($header[5]) << 8) | (ord($header[6]) << 16);
            $payload = $this->receiveExactly($compressedLength, $this->remaining($deadline), $operation);
            if ($uncompressedLength !== 0) {
                $decoded = gzuncompress($payload);
                if ($decoded === false || strlen($decoded) !== $uncompressedLength) {
                    $this->closeSocket();
                    throw new Exception('Invalid MySQL compressed packet');
                }
                $payload = $decoded;
            }
            $this->decompressedBuffer .= $payload;
        }

        $data = substr($this->decompressedBuffer, 0, $length);
        $this->decompressedBuffer = substr($this->decompressedBuffer, $length);
        return $data;
    }

    private function sendExactly(string $data, float $deadline, string $operation): void
    {
        $remaining = $this->remaining($deadline);
        $written = $this->socket?->sendAll($data, $remaining);
        if ($written !== strlen($data)) {
            throw $this->ioException($operation, $remaining);
        }
    }

    private function receiveExactly(int $length, float $timeout, string $operation): string
    {
        if ($timeout <= 0) {
            throw new TimeoutException('MySQL operation timed out', 110);
        }
        if ($length === 0) {
            return '';
        }
        $data = $this->socket?->recvAll($length, $timeout);
        if ($data === false || strlen($data) !== $length) {
            throw $this->ioException($operation, $timeout);
        }
        return $data;
    }

    private function ioException(string $operation, float $timeout, ?int $code = null, ?string $message = null): Exception
    {
        $code ??= $this->socket?->errCode ?? 0;
        $message = $message ?: ($this->socket?->errMsg ?: 'socket I/O failed');
        if (in_array($code, [110, 60], true) || str_contains(strtolower($message), 'timed out')) {
            $this->closeSocket();
            return new TimeoutException(sprintf('MySQL %s timed out after %.3f seconds', $operation, $timeout), $code ?: 110);
        }
        $this->closeSocket();
        return new Exception("MySQL {$operation} failed: {$message}", $code);
    }

    private function guard(callable $callback): mixed
    {
        if ($this->busy) {
            throw new Exception('Concurrent operations on one MySQL connection are not allowed');
        }
        $this->busy = true;
        try {
            return $callback();
        } finally {
            $this->busy = false;
        }
    }

    private function ensureConnected(float $deadline): void
    {
        if (!$this->isConnected()) {
            $this->connectInternal($deadline);
        }
    }

    private function deadline(?float $timeout): float
    {
        $duration = $timeout ?? $this->timeout;
        if ($duration <= 0) {
            throw new \InvalidArgumentException('Query timeout must be greater than zero');
        }
        return microtime(true) + $duration;
    }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            $this->closeSocket();
            throw new TimeoutException('MySQL operation timed out', 110);
        }
        return $remaining;
    }

    private function resetMetadata(): void
    {
        $this->error = '';
        $this->errno = 0;
        $this->insert_id = 0;
        $this->affected_rows = 0;
    }

    private function closeSocket(): void
    {
        $this->connected = false;
        $this->compressionEnabled = false;
        $this->decompressedBuffer = '';
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
    }

    private static function compareUnsignedDecimal(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
