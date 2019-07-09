<?php

namespace EasySwoole\Mysqli\Table;

use EasySwoole\Spl\SplBean;

class Table extends SplBean
{

    protected $name;       // 表的名称
    protected $engine;     // 储存引擎
    protected $comment;    // 表的注释
    protected $collation;  // 表字符集
    protected $rowFormat;  // 行格式定义

    protected $columns = [];      // 表列定义
    protected $indexes = [];      // 索引定义 (UNIQUE实际应该定义为索引)

}