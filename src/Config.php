<?php


namespace EasySwoole\Mysqli;


use EasySwoole\Spl\SplBean;

class Config extends SplBean
{
    protected string $host;
    protected string $user;
    protected string $password;
    protected string $database;//数据库
    protected int $port = 3306;
    protected int $timeout = 3;
    protected string $charset = 'utf8mb4';
    protected int $maxConnectTime = 3;


    /**
     * @return mixed
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @param mixed $host
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * @return mixed
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * @param mixed $user
     */
    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    /**
     * @return mixed
     */
    public function getPassword():string
    {
        return $this->password;
    }

    /**
     * @param mixed $password
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * @return mixed
     */
    public function getDatabase():string
    {
        return $this->database;
    }

    /**
     * @param mixed $database
     */
    public function setDatabase(string $database): void
    {
        $this->database = $database;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @param int $port
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }


    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @param float $timeout
     */
    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * @return string
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * @param string $charset
     */
    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * @return int
     */
    public function getMaxConnectTime(): int
    {
        return $this->maxConnectTime;
    }

    /**
     * @param int $maxConnectTime
     */
    public function setMaxConnectTime(int $maxConnectTime): void
    {
        $this->maxConnectTime = $maxConnectTime;
    }
}