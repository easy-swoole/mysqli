<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli;

use EasySwoole\Spl\SplBean;

class Config extends SplBean
{
    protected string $host = '127.0.0.1';
    protected string $user = '';
    protected string $password = '';
    protected string $database = '';
    protected int $port = 3306;
    protected float $timeout = 3.0;
    protected string $charset = 'utf8mb4';
    protected float $maxConnectTime = 1.0;
    protected bool $compress = false;

    /**
     * 获取数据库主机地址。
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * 设置数据库主机地址。
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * 获取数据库用户名。
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * 设置数据库用户名。
     */
    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    /**
     * 获取数据库密码。
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * 设置数据库密码。
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * 获取数据库名称。
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * 设置数据库名称。
     */
    public function setDatabase(string $database): void
    {
        $this->database = $database;
    }

    /**
     * 获取数据库端口。
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * 设置数据库端口。
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * 获取默认查询超时，单位为秒。
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * 设置默认查询超时，单位为秒。
     */
    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * 获取连接字符集。
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * 设置连接字符集。
     */
    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * 获取完整连接过程的超时上限，单位为秒。
     */
    public function getMaxConnectTime(): float
    {
        return $this->maxConnectTime;
    }

    /**
     * 设置完整连接过程的超时上限，单位为秒。
     */
    public function setMaxConnectTime(float $maxConnectTime): void
    {
        $this->maxConnectTime = $maxConnectTime;
    }

    /**
     * 获取是否请求启用协议压缩。
     */
    public function isCompress(): bool
    {
        return $this->compress;
    }

    /**
     * 设置是否请求启用协议压缩。
     */
    public function setCompress(bool $compress): void
    {
        $this->compress = $compress;
    }
}
