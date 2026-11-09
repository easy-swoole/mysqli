<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreamMemoryIntegrationTest extends TestCase
{
    public static function modes(): array { return ['plain protocol'=>[false],'compressed protocol'=>[true]]; }

    #[DataProvider('modes')]
    public function testResultLargerThanMemoryBudgetIsReadIncrementally(bool $compression): void
    {
        if(MYSQL_CONFIG['host']==='') {self::markTestSkipped('Set MYSQLI_TEST_* for live checks');}
        $pipes=[];
        $process=proc_open([PHP_BINARY,'-d','memory_limit=32M',__DIR__.'/Support/stream-memory-worker.php',$compression?'1':'0'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);
        $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        $exit=proc_close($process);
        self::assertSame(0,$exit,$error.' '.$output);
        $result=json_decode(trim($output),true,512,JSON_THROW_ON_ERROR);
        self::assertSame(10000,$result['rows']);
        self::assertSame(40960000,$result['payloadBytes']);
        self::assertLessThanOrEqual(32*1024*1024,$result['peakBytes']);
        self::assertSame($compression,$result['compression']);
    }
}
