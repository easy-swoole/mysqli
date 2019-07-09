<?php

namespace EasySwoole\Mysqli\Table;

class Column
{
    protected $name;     // 列名称
    protected $default;  // 默认值
    protected $comment;  // 列的注释
    protected $dataType; // 列的类型 TODO 类型应该抽象为对象使得可以链式设置
    protected $nullable = false; // 是否允许为空 (默认NOT NULL)
    protected $autoIncrement;    // 该列是否为自增

    // PK和UNI实际上属于索引 应该在索引中设置

    /**
     * Column constructor.
     * @param string $name 字段名称
     * @param string $dataType 字段类型
     */
    function __construct($name, $dataType)
    {
        $this->name = $name;
        $this->dataType = $dataType;
    }

    /**
     * 获取列名称
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 设置列名称
     * @param string $name
     * @return Column
     */
    public function setName(string $name): Column
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 获取默认值
     * @return mixed
     */
    public function getDefault()
    {
        return $this->default;
    }

    /**
     * 设置默认值
     * @param mixed $default
     * @return Column
     */
    public function setDefault($default)
    {
        $this->default = $default;
        return $this;
    }

    /**
     * 获取列注释
     * @return mixed
     */
    public function getComment()
    {
        return $this->comment;
    }

    /**
     * 设置列注释
     * @param mixed $comment
     * @return Column
     */
    public function setComment($comment)
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * 获取数据类型
     * @return string
     */
    public function getDataType(): string
    {
        return $this->dataType;
    }

    /**
     * 设置数据类型
     * TODO 未抽象成对象前请不要设置奇怪的值
     * @param string $dataType
     * @return Column
     * @example INT(11) UNSIGNED | VARCHAR(255)
     */
    public function setDataType(string $dataType): Column
    {
        $this->dataType = $dataType;
        return $this;
    }

    /**
     * 获取是否允许NULL
     * @return bool
     */
    public function isNullable(): bool
    {
        return $this->nullable;
    }

    /**
     * 设置是否允许NULL
     * @param bool $nullable
     * @return Column
     */
    public function setNullable(bool $nullable): Column
    {
        $this->nullable = $nullable;
        return $this;
    }

    /**
     * 是否自增字段
     * @return mixed
     */
    public function getAutoIncrement()
    {
        return $this->autoIncrement;
    }

    /**设置自增字段
     * @param mixed $autoIncrement
     * @return Column
     */
    public function setAutoIncrement($autoIncrement)
    {
        $this->autoIncrement = $autoIncrement;
        return $this;
    }

    /**
     * 获取创建该列的DDL
     * @return string
     */
    public function getCreateDDL(): string
    {
        $default = $this->createDefaultDDL($this->default);

        // 使用空格组装成完整DDL
        return implode(' ',
            array_filter(
                [
                    '`' . $this->getName() . '`',  // 名称
                    (string)$this->getDataType(),  // 类型
                    $this->nullable ? 'NULL' : 'NOT NULL',  // 是否允许NULL
                    $default ? 'DEFAULT ' . $default : null,
                    $this->getAutoIncrement() ? 'AUTO_INCREMENT' : null,
                    $this->getComment() ? sprintf("COMMENT '%s'", addslashes($this->getComment())) : null
                ]
            )
        );
    }

    /**
     * 转换默认值
     * @param $default
     * @return string
     */
    function createDefaultDDL($default)
    {
        // 如果当前允许NULL值 而没有设置默认值 那么默认就为NULL
        if ($this->default === null && $this->nullable) {
            return 'NULL';
        } else if ($this->default !== null) {
            return '"' . $default . '"'; // 否则不管都加引号
        }
        return false;
    }

    function __toString()
    {
        return $this->getCreateDDL();
    }
}