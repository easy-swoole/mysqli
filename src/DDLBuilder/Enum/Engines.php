<?php

namespace EasySwoole\Mysqli\DDLBuilder\Enum;

use ReflectionException;

/**
 * 引擎集合
 * Class Engines
 * @package EasySwoole\Mysqli\DDLBuilder\Enum
 */
class Engines
{
    const CSV = 'csv';
    const INNODB = 'innodb';
    const MEMORY = 'memory';
    const MYISAM = 'myisam';
    const ARCHIVE = 'archive';
    const FEDERATED = 'federated';
    const BLACKHOLE = 'blackhole';
    const MRG_MYISAM = 'mrg_myisam';
    const PERFORMANCE_SCHEMA = 'performance_schema';

    /**
     * 全部引擎类型
     * @throws ReflectionException
     */
    public static function allEngine()
    {
        $reflectionClass = new \ReflectionClass(Engines::class);
        $constants = $reflectionClass->getConstants();
        return array_values($constants);
    }

    /**
     * 是否有效的引擎类型
     * @param string $typeName 类型名称
     * @return bool
     * @throws ReflectionException
     */
    public static function isValidEngines(string $typeName)
    {
        $allEngine = Engines::allEngine();
        return in_array(strtolower($typeName), $allEngine);
    }
}