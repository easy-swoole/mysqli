<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Protocol;

use EasySwoole\Mysqli\Exception\Exception;

/**
 * 单次遍历的结果流。完整读取后复用连接；提前关闭时丢弃连接，避免残留协议数据。
 * 显式持有 getIterator() 返回的迭代器时，提前停止后须调用 close()。
 *
 * @implements \IteratorAggregate<int, array>
 */
final class ResultStream implements \IteratorAggregate
{
    private ?\Closure $fetch;
    private ?\Closure $finish;
    private bool $closed = false;
    private bool $complete = false;
    private bool $started = false;
    private bool $fetching = false;
    private int $rowsRead = 0;
    private array $completionCallbacks = [];
    private array $closeCallbacks = [];

    /** @internal 由协议连接创建，fetch 返回下一行或 EOF 时的 null。 */
    public function __construct(callable $fetch, callable $finish)
    {
        $this->fetch = \Closure::fromCallable($fetch);
        $this->finish = \Closure::fromCallable($finish);
    }

    public function getIterator(): \Traversable
    {
        if ($this->started || $this->closed) {
            throw new \LogicException('A result stream can only be iterated once');
        }
        $this->started = true;
        try {
            while (!$this->closed) {
                $this->fetching = true;
                try { $row = ($this->fetch)(); }
                finally { $this->fetching = false; }
                if ($row === null) {
                    $this->finish(true);
                    return;
                }
                $index = $this->rowsRead++;
                yield $index => $row;
            }
        } finally {
            $this->close();
        }
    }

    /** 提前关闭会关闭所属连接；在事务中会保留事务丢失标记。 */
    public function close(): void
    {
        if ($this->fetching) {
            throw new Exception('Concurrent close while a MySQL result stream is reading is not allowed');
        }
        $this->finish(false);
    }

    public function isClosed(): bool { return $this->closed; }
    public function isComplete(): bool { return $this->complete; }
    public function getRowsRead(): int { return $this->rowsRead; }

    /** @internal 完整读取后执行，参数是行数；提前停止及读取错误不会触发。 */
    public function onComplete(callable $callback): self
    {
        if ($this->complete) {
            $callback($this->rowsRead);
        } elseif (!$this->closed) {
            $this->completionCallbacks[] = $callback;
        }
        return $this;
    }

    /** @internal 完整读取、提前停止和读取失败后均执行；参数表示是否读到 EOF。 */
    public function onClose(callable $callback): self
    {
        if ($this->closed) {
            $callback($this->complete);
        } else {
            $this->closeCallbacks[] = $callback;
        }
        return $this;
    }

    private function finish(bool $complete): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->complete = $complete;
        $finish = $this->finish;
        $this->fetch = $this->finish = null;
        try {
            $finish($complete, $this->rowsRead);
            if ($complete) {
                foreach ($this->completionCallbacks as $callback) { $callback($this->rowsRead); }
            }
        } finally {
            try {
                foreach ($this->closeCallbacks as $callback) { $callback($complete); }
            } finally {
                $this->completionCallbacks = $this->closeCallbacks = [];
            }
        }
    }

    public function __destruct()
    {
        try { $this->close(); } catch (\Throwable) {}
    }
}
