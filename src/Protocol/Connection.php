<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\TimeoutException;
use EasySwoole\Mysqli\Exception\TransactionLostException;
use EasySwoole\Mysqli\Exception\UnsupportedOperationException;
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
    private const SERVER_STATUS_IN_TRANS = 0x0001;
    private const COM_QUIT = 0x01;
    private const COM_QUERY = 0x03;
    private const COM_PING = 0x0e;
    private const COM_STMT_PREPARE = 0x16;
    private const COM_STMT_EXECUTE = 0x17;
    private const COM_STMT_CLOSE = 0x19;
    private const MAX_PACKET_PAYLOAD = 0xffffff;

    private ?Socket $socket = null;
    private bool $connected = false;
    private bool $inTransaction = false;
    private bool $transactionLost = false;
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
    public int|string $affected_rows = 0;

    /**
     * 保存连接配置及默认操作超时。
     */
    public function __construct(private readonly Config $config)
    {
        $this->timeout = $config->getTimeout();
    }

    /**
     * 在并发保护下建立连接。
     */
    public function connect(?float $timeout = null): bool
    {
        return $this->guard(fn(): bool => $this->connectInternal($this->deadline($timeout ?? $this->config->getMaxConnectTime())));
    }

    /**
     * 在截止时间内完成建连、认证和字符集设置。
     */
    private function connectInternal(float $deadline): bool
    {
        $this->assertTransactionUsable();
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

    /**
     * 判断连接和底层 Socket 是否仍然有效。
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->socket !== null && !$this->socket->isClosed();
    }

    /**
     * 判断当前连接是否正在执行操作。
     */
    public function isBusy(): bool
    {
        return $this->busy;
    }

    /**
     * 获取实际协商后的协议压缩状态。
     */
    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    /**
     * 通过文本协议发送 SQL 并读取结果。
     */
    public function query(string $sql, ?float $timeout = null): array|bool
    {
        return $this->command(self::COM_QUERY, $sql, $timeout, false);
    }

    /**
     * 在服务端创建预处理语句并读取语句信息。
     */
    public function prepare(string $sql, ?float $timeout = null): Statement
    {
        return $this->guard(function () use ($sql, $timeout): Statement {
            $this->assertSupportedSql($sql);
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

    /**
     * 验证语句会话、编码参数并读取执行结果。
     */
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
            $this->assertSupportedSql($statement->getSql());
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

    /**
     * 检查语句是否仍属于当前有效会话。
     */
    private function assertStatementGeneration(int $generation): void
    {
        $this->assertTransactionUsable();
        if (!$this->isConnected() || $generation !== $this->generation) {
            throw new \LogicException('The prepared statement belongs to an expired MySQL session');
        }
    }

    /**
     * 关闭当前会话中的语句，预算耗尽时丢弃连接。
     */
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

    /**
     * 向服务端发送探测命令以检查连接。
     */
    public function ping(): bool
    {
        return $this->command(self::COM_PING, '', $this->timeout, false) === true;
    }

    /**
     * 开始数据库事务，flags 支持一致性快照、只读或读写模式。
     */
    public function begin_transaction(TransactionStartFlags $flags = TransactionStartFlags::None): bool
    {
        return $this->query($flags->toSql()) === true;
    }

    /**
     * 提交当前事务，flags 支持 CHAIN、NO CHAIN、RELEASE 和 NO RELEASE。
     */
    public function commit(TransactionCompletionFlags $flags = TransactionCompletionFlags::None): bool
    {
        return $this->finishTransaction('COMMIT', $flags);
    }

    /**
     * 回滚当前事务，flags 支持 CHAIN、NO CHAIN、RELEASE 和 NO RELEASE。
     */
    public function rollback(TransactionCompletionFlags $flags = TransactionCompletionFlags::None): bool
    {
        return $this->finishTransaction('ROLLBACK', $flags);
    }

    /**
     * 按枚举选项提交或回滚，并同步 RELEASE 后的连接状态。
     */
    private function finishTransaction(string $command, TransactionCompletionFlags $flags): bool
    {
        $command .= $flags->toSqlSuffix();
        $result = $this->query($command) === true;
        if ($result && $flags->releasesConnection()) {
            // RELEASE 成功后服务端已释放会话，立即同步本地连接状态。
            $this->closeSocket();
        }
        return $result;
    }

    /**
     * 切换当前数据库。
     */
    public function select_db(string $database): bool
    {
        $escaped = str_replace('`', '``', $database);
        return $this->query("USE `{$escaped}`") === true;
    }

    /**
     * 验证并设置连接字符集。
     */
    public function set_charset(string $charset)
    {
        $timeout = func_get_args()[1] ?? null;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
            throw new \InvalidArgumentException('Invalid MySQL charset');
        }
        return $this->query("SET NAMES {$charset}", $timeout) === true;
    }

    /**
     * 在并发保护下关闭连接。
     */
    public function close(): bool
    {
        return $this->guard(fn(): bool => $this->closeInternal());
    }

    /**
     * 尝试发送退出命令并关闭 Socket。
     */
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
        $this->inTransaction = false;
        $this->transactionLost = false;
        return true;
    }

    /**
     * 在共享超时预算内发送命令并读取响应。
     */
    private function command(int $command, string $payload, ?float $timeout, bool $binary): array|bool
    {
        return $this->guard(function () use ($command, $payload, $timeout, $binary): array|bool {
            if ($command === self::COM_QUERY) {
                $this->assertSupportedSql($payload);
            }
            $deadline = $this->deadline($timeout);
            $this->ensureConnected($deadline);
            $this->resetMetadata();
            $this->beginCommand();
            $this->writePacket(chr($command) . $payload, $this->remaining($deadline), 'query');
            return $this->readResponse($deadline, $binary, $payload);
        });
    }

    /**
     * 拒绝 CALL 调用，跳过前导注释并识别 MySQL 可执行注释中的调用。
     */
    private function assertSupportedSql(string $sql): void
    {
        while (true) {
            $sql = preg_replace('/\A[\x00-\x20;]+/', '', $sql);
            if (str_starts_with($sql, '#') || (str_starts_with($sql, '--')
                && (strlen($sql) === 2 || ord($sql[2]) <= 32))) {
                $length = strcspn($sql, "\r\n");
                $sql = substr($sql, $length);
                continue;
            }
            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/', 2);
                if ($end === false) { return; } // 未闭合注释由服务端按 SQL 语法报错。
                $body = ($sql[2] ?? '') === '!'
                    ? preg_replace('/\A[0-9]{5,6}/', '', substr($sql, 3, $end - 3)) : '';
                $sql = $body . ' ' . substr($sql, $end + 2);
                continue;
            }
            break;
        }
        if (preg_match('/\ACALL(?![a-zA-Z0-9_$\x80-\xff])/i', $sql)) {
            throw new UnsupportedOperationException('Stored procedure calls (CALL) are not supported by this client');
        }
    }

    /**
     * 解析服务端响应，返回结果行或执行成功标志。
     */
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
        if (!is_int($columnCount)) {
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

        if ($this->isEof($terminator)) {
            $this->parseEofStatus($terminator);
        } else {
            $this->parseOk($terminator);
        }

        $rows = [];
        while (true) {
            $row = $this->readPacket($this->remaining($deadline), 'result row');
            $this->throwIfError($row, $sql);
            if ($this->isEof($row)) {
                $this->parseEofStatus($row);
                break;
            }
            $rows[] = $binary ? $this->parseBinaryRow($row, $columns) : $this->parseTextRow($row, $columns);
        }
        $this->affected_rows = count($rows);
        return $rows;
    }

    /**
     * 解析握手信息并发送认证请求。
     */
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

    /**
     * 处理认证切换及完整认证流程。
     */
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

    /**
     * 读取预处理语句的参数或字段定义。
     */
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

    /**
     * 解析结果字段的名称、类型及标志。
     */
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
        return new Column($name, $type, $flags, Codec::int1($packet, $offset));
    }

    /**
     * 将文本协议结果行解析为关联数组。
     */
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

    /**
     * 按字段类型转换文本值，并保留超大无符号整数。
     */
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

    /**
     * 将二进制协议结果行解析为关联数组。
     */
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

    /**
     * 按字段类型解码单个二进制值。
     */
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
            Column::TYPE_DATETIME => $this->unpackTemporal($packet, $offset, $column),
            default => Codec::lenencString($packet, $offset),
        };
    }

    /**
     * 解码二进制整数，超大无符号值返回字符串。
     */
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

    /**
     * 解码小端单精度或双精度浮点数。
     */
    private function unpackFloat(string $packet, int &$offset, int $bytes): float
    {
        $raw = substr($packet, $offset, $bytes);
        $offset += $bytes;
        return unpack($bytes === 4 ? 'g' : 'e', $raw)[1];
    }

    /**
     * 将二进制日期时间按字段精度转换为与文本协议一致的字符串。
     */
    private function unpackTemporal(string $packet, int &$offset, Column $column): string
    {
        $length = Codec::int1($packet, $offset);
        $type = $column->type;
        $validLengths = $type === Column::TYPE_TIME ? [0, 8, 12] : [0, 4, 7, 11];
        if (!in_array($length, $validLengths, true) || strlen($packet) - $offset < $length) {
            throw new Exception('Malformed binary temporal value');
        }
        $start = $offset;
        $microseconds = 0;
        if ($type === Column::TYPE_TIME) {
            $negative = false;
            $hours = $minutes = $seconds = 0;
            if ($length !== 0) {
                $negative = Codec::int1($packet, $offset) === 1;
                $days = Codec::int4($packet, $offset);
                $hours = Codec::int1($packet, $offset) + ($days * 24);
                $minutes = Codec::int1($packet, $offset);
                $seconds = Codec::int1($packet, $offset);
                $microseconds = $length === 12 ? Codec::int4($packet, $offset) : 0;
            }
            $value = sprintf('%s%02d:%02d:%02d', $negative ? '-' : '', $hours, $minutes, $seconds);
        } else {
            $year = $month = $day = $hour = $minute = $second = 0;
            if ($length !== 0) {
                $year = Codec::int2($packet, $offset);
                $month = Codec::int1($packet, $offset);
                $day = Codec::int1($packet, $offset);
                if ($length >= 7) {
                    $hour = Codec::int1($packet, $offset);
                    $minute = Codec::int1($packet, $offset);
                    $second = Codec::int1($packet, $offset);
                }
                $microseconds = $length === 11 ? Codec::int4($packet, $offset) : 0;
            }
            $value = sprintf('%04d-%02d-%02d', $year, $month, $day);
            if ($type !== Column::TYPE_DATE) {
                $value .= sprintf(' %02d:%02d:%02d', $hour, $minute, $second);
            }
        }
        $offset = $start + $length;
        // 精度来自字段元数据，零小数也保留对应位数，避免随二进制包长度变化。
        if ($type !== Column::TYPE_DATE && $column->decimals > 0 && $column->decimals <= 6) {
            $value .= '.' . substr(sprintf('%06d', $microseconds), 0, $column->decimals);
        }
        return $value;
    }

    /**
     * 生成预处理参数的空值位图、类型及数据。
     */
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

    /**
     * 读取成功响应中的影响行数、插入 ID 及事务状态。
     */
    private function parseOk(string $packet): void
    {
        $offset = 1;
        $this->affected_rows = Codec::lenencInt($packet, $offset) ?? 0;
        $this->insert_id = Codec::lenencInt($packet, $offset) ?? 0;
        $this->inTransaction = (Codec::int2($packet, $offset) & self::SERVER_STATUS_IN_TRANS) !== 0;
    }

    /**
     * 从结果集结束包读取服务端事务状态。
     */
    private function parseEofStatus(string $packet): void
    {
        $offset = 3;
        $this->inTransaction = (Codec::int2($packet, $offset) & self::SERVER_STATUS_IN_TRANS) !== 0;
    }

    /**
     * 返回服务端最近确认的事务是否仍在当前连接中。
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction && $this->isConnected();
    }

    /**
     * 判断事务是否因连接丢失而失效，显式关闭前保持失效状态。
     */
    public function isTransactionLost(): bool
    {
        if ($this->inTransaction && !$this->isConnected()) {
            $this->transactionLost = true;
        }
        return $this->transactionLost;
    }

    /**
     * 阻止丢失事务后自动建立新会话继续执行，需先显式关闭连接。
     */
    public function assertTransactionUsable(): void
    {
        if ($this->isTransactionLost()) {
            throw new TransactionLostException('MySQL transaction was lost; explicitly close the connection before starting a new session');
        }
    }

    /**
     * 识别服务端错误响应并抛出异常。
     */
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

    /**
     * 判断数据包是否为结果集结束标记。
     */
    private function isEof(string $packet): bool
    {
        return ($packet[0] ?? '') === "\xfe" && strlen($packet) < 9;
    }

    /**
     * 转换文本整数，超大无符号值保留为字符串。
     */
    private function integerValue(string $value, bool $unsigned): int|string
    {
        if ($unsigned && self::compareUnsignedDecimal($value, (string) PHP_INT_MAX) > 0) {
            return $value;
        }
        return (int) $value;
    }

    /**
     * 重置新命令的包序号及解压缓冲区。
     */
    private function beginCommand(): void
    {
        $this->sequence = 0;
        $this->compressedSequence = 0;
        $this->decompressedBuffer = '';
    }

    /**
     * 按协议分片并发送完整数据包。
     */
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

    /**
     * 校验包序号并重组完整数据包。
     */
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

    /**
     * 按压缩协商状态发送协议字节。
     */
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

    /**
     * 读取指定长度的协议字节，必要时解压。
     */
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

    /**
     * 在剩余预算内发送全部字节。
     */
    private function sendExactly(string $data, float $deadline, string $operation): void
    {
        $remaining = $this->remaining($deadline);
        $written = $this->socket?->sendAll($data, $remaining);
        if ($written !== strlen($data)) {
            throw $this->ioException($operation, $remaining);
        }
    }

    /**
     * 在指定超时内读取完整字节串。
     */
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

    /**
     * 关闭失效连接并生成 Socket 错误或超时异常。
     */
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

    /**
     * 阻止同一个连接同时执行多个操作。
     */
    private function guard(callable $callback): mixed
    {
        if ($this->busy) {
            throw new Exception('Concurrent operations on active MySQL connection are not allowed');
        }
        $this->busy = true;
        try {
            return $callback();
        } finally {
            $this->busy = false;
        }
    }

    /**
     * 在需要时按当前操作预算建立连接。
     */
    private function ensureConnected(float $deadline): void
    {
        $this->assertTransactionUsable();
        if (!$this->isConnected()) {
            $this->connectInternal($deadline);
        }
    }

    /**
     * 验证操作超时并计算截止时间。
     */
    private function deadline(?float $timeout): float
    {
        $duration = $timeout ?? $this->timeout;
        if ($duration <= 0) {
            throw new \InvalidArgumentException('Query timeout must be greater than zero');
        }
        return microtime(true) + $duration;
    }

    /**
     * 计算剩余预算，耗尽时关闭连接并抛出异常。
     */
    private function remaining(float $deadline): float
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            $this->closeSocket();
            throw new TimeoutException('MySQL operation timed out', 110);
        }
        return $remaining;
    }

    /**
     * 清空协议层错误信息及查询元数据。
     */
    private function resetMetadata(): void
    {
        $this->error = '';
        $this->errno = 0;
        $this->insert_id = 0;
        $this->affected_rows = 0;
    }

    /**
     * 关闭底层连接、重置压缩状态，并保留事务丢失标记。
     */
    private function closeSocket(): void
    {
        if ($this->inTransaction) {
            $this->transactionLost = true;
        }
        $this->connected = false;
        $this->compressionEnabled = false;
        $this->decompressedBuffer = '';
        if ($this->socket !== null) {
            $this->socket->close();
            $this->socket = null;
        }
    }

    /**
     * 比较两个无符号十进制整数字符串的大小。
     */
    private static function compareUnsignedDecimal(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
