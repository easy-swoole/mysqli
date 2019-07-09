<?php

namespace EasySwoole\Mysqli\Table;

use EasySwoole\Spl\SplBean;

class Table extends SplBean
{

    protected $name;       // 表的名称
    protected $engine;     // 储存引擎
    protected $comment;    // 表的注释
    protected $collation;  // 表字符集

    protected $columns = [];      // 表列定义
    protected $indexes = [];      // 索引定义 (UNIQUE/PK实际应该定义为索引)

    const ENGINE_INNODB = 'InnoDb';
    const ENGINE_MYISAM = 'MyIsam';
    const ENGINE_Memory = 'Memory';

    const COLLATION_UTF8_BIN = 'utf8_bin';
    const COLLATION_UTF8_GENERAL_CI = 'utf8_general_ci';
    const COLLATION_UTF8MB4_BIN = 'utf8mb4_bin';
    const COLLATION_UTF8MB4_GENERAL_CI = 'utf8mb4_general_ci';

    /**
     * 获取表名称
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * 设置表名称
     * @param mixed $name
     * @return Table
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 获取表引擎
     * @return mixed
     */
    public function getEngine()
    {
        return $this->engine;
    }

    /**
     * 设置表引擎
     * @param mixed $engine
     * @return Table
     */
    public function setEngine($engine)
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * 获取表注释
     * @return mixed
     */
    public function getComment()
    {
        return $this->comment;
    }

    /**
     * 设置表注释
     * @param mixed $comment
     * @return Table
     */
    public function setComment($comment)
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * 获取表字符集
     * @return mixed
     */
    public function getCollation()
    {
        return $this->collation;
    }

    /**
     * 设置表字符集
     * @param mixed $collation
     * @return Table
     */
    public function setCollation($collation)
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * 添加一列
     * @param $name
     * @param $dataType
     * @return mixed
     */
    public function addColumn(string $name, $dataType)
    {
        $this->columns[$name] = new Column($name, $dataType);
        return $this->columns[$name];
    }

    /**
     * 删除一列
     * @param $name
     */
    public function deleteColumn($name)
    {
        unset($this->columns[$name]);
    }

    /**
     * 列是否存在
     * @param $name
     * @return bool
     */
    function hasColumn($name)
    {
        return isset($this->columns[$name]);
    }

    /**
     * 获取某个列或全部列
     * @param null $name
     * @return array|mixed|null
     */
    function getColumn($name = null)
    {
        if (is_null($name)) {
            return $this->columns;
        } else {
            return $this->hasColumn($name) ? $this->columns[$name] : null;
        }
    }

    // 这里进行整表DDL的创建
    function createTableDDL(): string
    {

    }

    // 转换为DDL
    function __toString()
    {
        return $this->createTableDDL();
    }
}