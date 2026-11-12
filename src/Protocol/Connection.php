<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\TlsMode;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\ProtocolException;
use EasySwoole\Mysqli\Exception\TimeoutException;
use EasySwoole\Mysqli\Exception\UnsupportedOperationException;

final class Connection
{
    private const COM_QUIT = 0x01;
    private const COM_QUERY = 0x03;
    private const COM_PING = 0x0e;
    private const COM_STMT_PREPARE = 0x16;
    private const COM_STMT_EXECUTE = 0x17;
    private const COM_STMT_CLOSE = 0x19;

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
            $tls = $this->config->getTlsMode() !== TlsMode::Disabled;
            if ($tls) { TlsOptions::fromConfig($this->config); }
            $this->stream->connect($this->config->getHost(), $this->config->getPort(), $deadline, $tls);
            $handshake = $this->stream->readPacket($deadline, 'handshake');
            $authenticator = new Authenticator(
                $this->config,
                $this->stream,
                function (string $packet, string $context): void { $this->throwIfError($packet, $context); },
                function (string $packet): void { $this->parseOk($packet); },
            );
            $authenticator->authenticate($handshake, $deadline);
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

    /** 当前有效连接是否已完成 TLS 握手和所配置的证书校验。 */
    public function isTlsEnabled(): bool { return $this->stream->isTlsEnabled(); }

    /** 获取实际协商后的协议压缩状态。 */
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

    /** 按行读取文本协议结果；结果流关闭前独占连接。 */
    public function iterate(string $sql, ?float $timeout = null): ResultStream
    {
        $deadline = Deadline::after($timeout ?? $this->timeout);
        return $this->startStream(function () use ($sql, $deadline): ?array {
            $this->assertSupportedSql($sql);
            $this->writeTextCommand(self::COM_QUERY, $sql, $deadline);
            return $this->readResultHeader($deadline, $sql);
        }, $deadline, false, $sql);
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
        $this->assertParameterCount($statement, $parameters);

        return $this->guard(function () use ($statement, $parameters, $timeout): array|bool {
            $deadline = Deadline::after($timeout ?? $this->timeout);
            $this->writeStatementCommand($statement, $parameters, $deadline);
            return $this->readResponse($deadline, true, $statement->getSql());
        });
    }

    /** 按行读取预处理结果，沿用语句会话校验及单次操作超时预算。 */
    public function iterateStatement(Statement $statement, array $parameters, ?float $timeout): ResultStream
    {
        $this->assertParameterCount($statement, $parameters);
        $deadline = Deadline::after($timeout ?? $this->timeout);
        return $this->startStream(function () use ($statement, $parameters, $deadline): ?array {
            $this->writeStatementCommand($statement, $parameters, $deadline);
            return $this->readResultHeader($deadline, $statement->getSql());
        }, $deadline, true, $statement->getSql(), $statement);
    }

    /** 参数校验保持在命令并发保护与 I/O 之前，缓冲和流式入口共用。 */
    private function assertParameterCount(Statement $statement, array $parameters): void
    {
        if (count($parameters) !== $statement->getParameterCount()) {
            throw new \InvalidArgumentException(sprintf(
                'Statement expects %d parameters, %d given',
                $statement->getParameterCount(),
                count($parameters),
            ));
        }
    }

    /** 共享预处理会话检查和分段参数编码，缓冲与流式读取使用同一命令格式。 */
    private function writeStatementCommand(Statement $statement, array $parameters, Deadline $deadline): void
    {
        if (!$statement->belongsTo($this)) {
            throw new \LogicException('The prepared statement belongs to a different MySQL connection');
        }
        $this->assertStatementGeneration($statement->getGeneration());
        [$nullBitmap, $types, $values] = ParameterEncoder::encode($parameters);
        $payload = chr(self::COM_STMT_EXECUTE) . pack('V', $statement->getId())
            . "\0" . pack('V', 1) . $nullBitmap
            . ($parameters === [] ? '' : "\1" . $types);
        $this->resetMetadata();
        $this->stream->beginCommand();
        $this->stream->writePacketParts([$payload, ...$values], $deadline, 'execute');
    }

    /** 开始流式响应，并把并发保护延长至 EOF 或显式关闭。 */
    private function startStream(callable $start, Deadline $deadline, bool $binary, string $sql, ?Statement $owner = null): ResultStream
    {
        if ($this->busy) {
            throw new Exception('Concurrent operations on active MySQL connection are not allowed');
        }
        $this->busy = true;
        try {
            $columns = $start();
            return new ResultStream(
                function () use ($columns, $deadline, $binary, $sql, $owner): ?array {
                    try {
                        return $columns === null ? null : $this->readResultRow($deadline, $binary, $sql, $columns);
                    } catch (\Throwable $error) {
                        $this->handleOperationFailure($error);
                        throw $error;
                    }
                },
                function (bool $complete, int $rows) use ($columns, $owner): void {
                    try {
                        if (!$complete) { $this->invalidateConnection(); }
                        elseif ($columns !== null) { $this->affected_rows = $rows; }
                    } finally {
                        $this->busy = false;
                    }
                },
            );
        } catch (\Throwable $error) {
            $this->handleOperationFailure($error);
            $this->busy = false;
            throw $error;
        }
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
        // 已失效的语句不得将自身 ID 发送到其他服务端会话。
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
    public function set_charset(string $charset): bool
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
            $this->writeTextCommand($command, $payload, $deadline);
            return $this->readResponse($deadline, $binary, $payload);
        });
    }

    /** 共用发包顺序；SQL 支持性检查及 Deadline 创建仍由原入口按原时机执行。 */
    private function writeTextCommand(int $command, string $payload, Deadline $deadline): void
    {
        $this->ensureConnected($deadline);
        $this->resetMetadata();
        $this->stream->beginCommand();
        $this->stream->writePacket(chr($command) . $payload, $deadline, 'query');
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
        $columns = $this->readResultHeader($deadline, $sql);
        if ($columns === null) { return true; }
        $rows = [];
        while (($row = $this->readResultRow($deadline, $binary, $sql, $columns)) !== null) {
            $rows[] = $row;
        }
        $this->affected_rows = count($rows);
        return $rows;
    }

    /** 读取结果元数据；普通 OK 响应返回 null。 */
    private function readResultHeader(Deadline $deadline, string $sql): ?array
    {
        $packet = $this->stream->readPacket($deadline, 'query');
        $this->throwIfError($packet, $sql);
        $headerOffset = 0;
        $header = Codec::int1($packet, $headerOffset);
        if ($header === 0x00) {
            $this->parseOk($packet);
            return null;
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

        return $columns;
    }

    /** 只读取当前行，不缓存其他行；EOF 时同步事务状态。 */
    private function readResultRow(Deadline $deadline, bool $binary, string $sql, array $columns): ?array
    {
        $row = $this->stream->readPacket($deadline, 'result row');
        $this->throwIfError($row, $sql);
        if ($this->isEof($row)) {
            $this->parseEofStatus($row);
            return null;
        }
        return $binary ? ResultDecoder::parseBinaryRow($row, $columns) : ResultDecoder::parseTextRow($row, $columns);
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
            $this->handleOperationFailure($error);
            throw $error;
        } finally {
            $this->busy = false;
        }
    }

    private function handleOperationFailure(\Throwable $error): void
    {
        if ($error instanceof ProtocolException || $error instanceof TimeoutException || !$this->stream->isOpen()) {
            $this->invalidateConnection();
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
