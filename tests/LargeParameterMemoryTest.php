<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use PHPUnit\Framework\TestCase;

final class LargeParameterMemoryTest extends TestCase
{
    public function testCompressedAndPlainRandomParametersFitWithin128MiB(): void
    {
        if (MYSQL_CONFIG['host'] === '' || !function_exists('proc_open')) {
            self::markTestSkipped('Requires database credentials and proc_open for an isolated memory limit.');
        }
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=128M', __DIR__ . '/Support/large-parameter-worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        self::assertSame('', $errors);
        $lines = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($output)));
        self::assertSame(['case' => 'large', 'compress' => false, 'bytes' => 0x1000000, 'verified' => true], $lines[0]);
        self::assertSame(['case' => 'exact_boundary', 'compress' => false, 'verified' => true], $lines[1]);
        self::assertSame(['case' => 'large', 'compress' => true, 'bytes' => 0x1000000, 'verified' => true], $lines[2]);
        self::assertSame(['case' => 'exact_boundary', 'compress' => true, 'verified' => true], $lines[3]);
        self::assertLessThan(128 * 1024 * 1024, $lines[4]['peak_memory_bytes']);
    }
}
