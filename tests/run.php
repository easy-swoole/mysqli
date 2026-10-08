<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'];
foreach (array_slice($arguments, 1) as $argument) {
    if (str_starts_with($argument, '--list-') || in_array($argument, ['--help', '-h', '--version'], true)) {
        exit((new PHPUnit\TextUI\Application())->run($arguments));
    }
}

$exitCode = 1;
Swoole\Coroutine\run(static function () use ($arguments, &$exitCode): void {
    try {
        $exitCode = (new PHPUnit\TextUI\Application())->run($arguments);
    } finally {
        Swoole\Timer::clearAll();
    }
});
exit($exitCode);
