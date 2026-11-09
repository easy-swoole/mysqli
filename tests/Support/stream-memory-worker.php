<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use EasySwoole\Mysqli\{Client, Config};

$compress = ($argv[1] ?? '') === '1';
$exit = 0;
Swoole\Coroutine\run(static function () use ($compress, &$exit): void {
    $client = new Client(new Config(array_replace(MYSQL_CONFIG, [
        'timeout'=>120.0,'maxConnectTime'=>5.0,'compress'=>$compress,
    ])));
    try {
        $digits = implode(' UNION ALL ', array_map(static fn(int $n): string => 'SELECT '.$n.' AS n', range(0,9)));
        $sql = 'WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM seq WHERE n<1000) '
            . 'SELECT seq.n + d.n * 1000 AS id, REPEAT(\'x\',4096) AS payload FROM seq CROSS JOIN ('.$digits.') d';
        $count=0;
        $expected=hash('sha256',str_repeat('x',4096));
        $stream=$client->rawIterate($sql);
        foreach($stream as $row) {
            if(strlen($row['payload'])!==4096 || hash('sha256',$row['payload'])!==$expected) {
                throw new RuntimeException('Corrupted stream row');
            }
            $count++;
        }
        if($count!==10000 || !$stream->isComplete() || $client->getLastAffectRows()!==10000) {
            throw new RuntimeException('Incomplete result stream');
        }
        if($client->mysqlClient()->isCompressionEnabled()!==$compress) {
            throw new RuntimeException('Unexpected compression negotiation');
        }
        if($client->rawQuery('SELECT 1 AS value')!==[['value'=>1]]) {
            throw new RuntimeException('Connection was not reusable after EOF');
        }
        echo json_encode(['rows'=>$count,'payloadBytes'=>$count*4096,'peakBytes'=>memory_get_peak_usage(true),
            'compression'=>$compress,'tls'=>$client->mysqlClient()->isTlsEnabled()],JSON_THROW_ON_ERROR),"\n";
    } catch(Throwable $error) {
        fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");
        $exit=1;
    } finally {$client->close();}
});
exit($exit);
