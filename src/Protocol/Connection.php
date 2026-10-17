<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\ProtocolException;
use EasySwoole\Mysqli\Exception\TimeoutException;
use EasySwoole\Mysqli\Exception\UnsupportedOperationException;

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

    private int $serverCapabilities = 0;
    private PacketStream $stream;
    private SessionState $state;
    private float $timeout;
    private bool $busy = false;

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
        $this->stream = new PacketStream();
        $this->state = new SessionState();
        $this->timeout = $config->getTimeout();
    }

    /**
     * 在并发保护下建立连接。
     */
    public function connect(?float $timeout = null): bool
    {
        return $this->guard(fn(): bool => $this->connectInternal(Deadline::after($timeout ?? $this->config->getMaxConnectTime())));
    }

    /**
     * 在截止时间内完成建连、认证和字符集设置。
     */
    private function connectInternal(Deadline $deadline): bool
    {
        $this->assertTransactionUsable();
        if ($this->isConnected()) {
            return true;
        }
        $this->invalidateConnection();
        $this->connect_error = '';
        $this->connect_errno = 0;
        $deadline = $deadline->cappedBy($this->config->getMaxConnectTime());
        try {
            $this->stream->connect($this->config->getHost(), $this->config->getPort(), $deadline);
            $handshake = $this->stream->readPacket($deadline, 'handshake');
            $this->authenticate($handshake, $deadline);
            $charset = $this->config->getCharset();
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
                throw new \InvalidArgumentException('Invalid MySQL charset');
            }
            $this->stream->beginCommand();
            $this->stream->writePacket(chr(self::COM_QUERY) . "SET NAMES {$charset}", $deadline, 'charset');
            $this->readResponse($deadline, false, 'SET NAMES');
            $this->state->established();
            $this->timeout = $this->config->getTimeout();
            return true;
        } catch (\Throwable $error) {
            $this->connect_errno = $error->getCode();
            $this->connect_error = $error->getMessage();
            $this->invalidateConnection();
            throw $error;
        }
    }

    /**
     * 判断连接和底层 Socket 是否仍然有效。
     */
    public function isConnected(): bool
    {
        return $this->state->isConnected() && $this->stream->isOpen();
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
        return $this->stream->isCompressionEnabled();
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
            $deadline = Deadline::after($timeout ?? $this->timeout);
            $this->ensureConnected($deadline);
            $this->stream->beginCommand();
            $this->stream->writePacket(chr(self::COM_STMT_PREPARE) . $sql, $deadline, 'prepare');
            $packet = $this->stream->readPacket($deadline, 'prepare');
            $this->throwIfError($packet, $sql);
            if (($packet[0] ?? '') !== "\0") {
                throw new ProtocolException('Malformed COM_STMT_PREPARE response');
            }
            $offset = 1;
            $statementId = Codec::int4($packet, $offset);
            $columnCount = Codec::int2($packet, $offset);
            $parameterCount = Codec::int2($packet, $offset);
            $offset++;
            $warningCount = Codec::int2($packet, $offset);

            $this->consumeDefinitions($parameterCount, $deadline);
            $this->consumeDefinitions($columnCount, $deadline);
            return new Statement($this, $statementId, $sql, $parameterCount, $columnCount, $warningCount, $this->state->generation());
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
            $deadline = Deadline::after($timeout ?? $this->timeout);
            [$nullBitmap, $types, $values] = ParameterEncoder::encode($parameters);
            $payload = chr(self::COM_STMT_EXECUTE)
                . pack('V', $statement->getId())
                . "\0"
                . pack('V', 1)
                . $nullBitmap
                . ($parameters === [] ? '' : "\1" . $types);
            $this->resetMetadata();
            $this->stream->beginCommand();
            $this->stream->writePacketParts([$payload, ...$values], $deadline, 'execute');
            return $this->readResponse($deadline, true, $statement->getSql());
        });
    }

    /**
     * 检查语句是否仍属于当前有效会话。
     */
    private function assertStatementGeneration(int $generation): void
    {
        $this->assertTransactionUsable();
        if (!$this->isConnected() || $generation !== $this->state->generation()) {
            throw new \LogicException('The prepared statement belongs to an expired MySQL session');
        }
    }

    /**
     * 关闭当前会话中的语句，预算耗尽时丢弃连接。
     */
    public function closeStatement(int $statementId, ?int $generation = null, ?float $timeout = null): void
    {
        // A stale statement must never send its ID to another server session.
        if (!$this->isConnected() || ($generation !== null && $generation !== $this->state->generation())) {
            return;
        }
        $this->guard(function () use ($statementId, $timeout): void {
            if ($timeout !== null && $timeout <= 0) {
                $this->invalidateConnection();
                return;
            }
            $this->stream->beginCommand();
            $this->stream->writePacket(chr(self::COM_STMT_CLOSE) . pack('V', $statementId), Deadline::after($timeout ?? $this->timeout), 'close statement');
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
            $this->invalidateConnection(true);
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
                $this->stream->beginCommand();
                $this->stream->writePacket(chr(self::COM_QUIT), Deadline::after(min(0.1, $this->timeout)), 'quit');
            } catch (\Throwable) {
            }
        }
        $this->invalidateConnection(true);
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
            $deadline = Deadline::after($timeout ?? $this->timeout);
            $this->ensureConnected($deadline);
            $this->resetMetadata();
            $this->stream->beginCommand();
            $this->stream->writePacket(chr($command) . $payload, $deadline, 'query');
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
    private function readResponse(Deadline $deadline, bool $binary, string $sql): array|bool
    {
        $packet = $this->stream->readPacket($deadline, 'query');
        $this->throwIfError($packet, $sql);
        $headerOffset = 0;
        $header = Codec::int1($packet, $headerOffset);
        if ($header === 0x00) {
            $this->parseOk($packet);
            return true;
        }
        if ($header === 0xfb) {
            throw new ProtocolException('LOCAL INFILE is not supported');
        }

        $offset = 0;
        $columnCount = Codec::lenencInt($packet, $offset);
        if (!is_int($columnCount)) {
            throw new ProtocolException('Malformed result-set header');
        }
        $columns = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $columns[] = ResultDecoder::parseColumn($this->stream->readPacket($deadline, 'column metadata'));
        }
        $terminator = $this->stream->readPacket($deadline, 'column terminator');
        $this->throwIfError($terminator, $sql);
        if (!$this->isEof($terminator) && ord($terminator[0]) !== 0x00) {
            throw new ProtocolException('Malformed result-set column terminator');
        }

        if ($this->isEof($terminator)) {
            $this->parseEofStatus($terminator);
        } else {
            $this->parseOk($terminator);
        }

        $rows = [];
        while (true) {
            $row = $this->stream->readPacket($deadline, 'result row');
            $this->throwIfError($row, $sql);
            if ($this->isEof($row)) {
                $this->parseEofStatus($row);
                break;
            }
            $rows[] = $binary ? ResultDecoder::parseBinaryRow($row, $columns) : ResultDecoder::parseTextRow($row, $columns);
        }
        $this->affected_rows = count($rows);
        return $rows;
    }

    /**
     * 解析握手信息并发送认证请求。
     */
    private function authenticate(string $handshake, Deadline $deadline): void
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
            $this->throwIfError($packet, 'authentication');
            $headerOffset = 0;
        $header = Codec::int1($packet, $headerOffset);
            if ($header === 0x00) {
                $this->parseOk($packet);
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
                if ($this->config->getPassword() === '') {
                    $this->stream->writePacket("\0", $deadline, 'full authentication');
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

    /**
     * 读取预处理语句的参数或字段定义。
     */
    private function consumeDefinitions(int $count, Deadline $deadline): void
    {
        for ($i = 0; $i < $count; $i++) {
            $packet = $this->stream->readPacket($deadline, 'prepare metadata');
            $this->throwIfError($packet, 'prepare');
        }
        if ($count > 0) {
            $terminator = $this->stream->readPacket($deadline, 'prepare metadata terminator');
            $this->throwIfError($terminator, 'prepare');
        }
    }

    /**
     * 读取成功响应中的影响行数、插入 ID 及事务状态。
     */
    private function parseOk(string $packet): void
    {
        $offset = 1;
        $this->affected_rows = Codec::lenencInt($packet, $offset) ?? 0;
        $this->insert_id = Codec::lenencInt($packet, $offset) ?? 0;
        $this->state->applyServerStatus(Codec::int2($packet, $offset));
    }

    /**
     * 从结果集结束包读取服务端事务状态。
     */
    private function parseEofStatus(string $packet): void
    {
        $offset = 3;
        $this->state->applyServerStatus(Codec::int2($packet, $offset));
    }

    /**
     * 返回服务端最近确认的事务是否仍在当前连接中。
     */
    public function inTransaction(): bool
    {
        return $this->state->inTransaction() && $this->stream->isOpen();
    }

    /**
     * 判断事务是否因连接丢失而失效，显式关闭前保持失效状态。
     */
    public function isTransactionLost(): bool
    {
        if ($this->state->isConnected() && !$this->stream->isOpen()) {
            $this->state->disconnected();
        }
        return $this->state->isTransactionLost();
    }

    /**
     * 阻止丢失事务后自动建立新会话继续执行，需先显式关闭连接。
     */
    public function assertTransactionUsable(): void
    {
        $this->isTransactionLost();
        $this->state->assertUsable();
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
            Codec::requireBytes($packet, $offset, 6);
            $offset += 6;
        }
        $message = substr($packet, $offset);
        $this->errno = $code;
        $this->error = $message;
        $this->state->applyServerError($code);
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
        } catch (\Throwable $error) {
            if ($error instanceof ProtocolException || $error instanceof TimeoutException || !$this->stream->isOpen()) {
                $this->invalidateConnection();
            }
            throw $error;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * 在需要时按当前操作预算建立连接。
     */
    private function ensureConnected(Deadline $deadline): void
    {
        $this->assertTransactionUsable();
        if (!$this->isConnected()) {
            $this->connectInternal($deadline);
        }
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
    private function invalidateConnection(bool $intentional = false): void
    {
        $this->state->disconnected($intentional);
        $this->stream->close();
    }
}
