<?php

namespace EasySwoole\Mysqli\DDLBuilder;

use EasySwoole\Mysqli\DDLBuilder\Blueprints\TableBlueprint;

/**
 * DDL生成助手
 * Class DDLBuilder
 * @package EasySwoole\Mysqli\DDLBuilder
 */
class DDLBuilder
{
    /**
     * 生成建表语句
     * @param string $table 表名称
     * @param callable $callable 在闭包中描述创建过程
     * @return string 返回生成的DDL语句
     */
    static function table($table, callable $callable)
    {
        $blueprint = new TableBlueprint($table);
        $callable($blueprint);
        return $blueprint->__createDDL();
    }
}