<?php

namespace EasySwoole\Mysqli\DDLBuilder\Enum;

use ReflectionException;

/**
 * 索引类型集合
 * Class IndexType
 * @package EasySwoole\Mysqli\DDLBuilder\Enum
 */
class IndexType
{
    const NORMAL = 'normal';
    const UNIQUE = 'unique';
    const PRIMARY = 'primary';
    const FULLTEXT = 'fulltext';


    /**
     * 全部的索引类型
     * @throws ReflectionException
     */
    public static function allIndexType()
    {
        $reflectionClass = new \ReflectionClass(IndexType::class);
        $constants = $reflectionClass->getConstants();
        return array_values($constants);
    }

    /**
     * 是否有效的索引
     * @param string $typeName 类型名称
     * @return bool
     * @throws ReflectionException
     */
    public static function isValidIndexTypes(string $typeName)
    {
        $allIndexType = IndexType::allIndexType();
        return in_array(strtolower($typeName), $allIndexType);
    }

}