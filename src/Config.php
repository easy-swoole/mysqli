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

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function setDatabase(string $database): void
    {
        $this->database = $database;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    public function getMaxConnectTime(): float
    {
        return $this->maxConnectTime;
    }

    public function setMaxConnectTime(float $maxConnectTime): void
    {
        $this->maxConnectTime = $maxConnectTime;
    }

    public function isCompress(): bool
    {
        return $this->compress;
    }

    public function setCompress(bool $compress): void
    {
        $this->compress = $compress;
    }
}
