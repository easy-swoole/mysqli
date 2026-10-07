<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

chdir(dirname(__DIR__));
$argv = $_SERVER['argv'];
// Informational commands may exit inside PHPUnit; keep them outside a coroutine.
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--list-') || in_array($argument, ['--help', '-h', '--version'], true)) {
        exit((new PHPUnit\TextUI\Application())->run($argv));
    }
}

$exitCode = 1;
Swoole\Coroutine\run(function () use ($argv, &$exitCode): void {
    try {
        $exitCode = (new PHPUnit\TextUI\Application())->run($argv);
    } finally {
        Swoole\Timer::clearAll();
    }
});
exit($exitCode);
