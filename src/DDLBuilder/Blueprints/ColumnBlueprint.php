<?php

namespace EasySwoole\Mysqli\DDLBuilder\Blueprints;

use EasySwoole\Mysqli\DDLBuilder\Enum\DataType;
use ReflectionException;

/**
 * 表的字段描述
 * Class ColumnBlueprint
 * @package EasySwoole\Mysqli\DDLBuilder\Blueprints
 */
class ColumnBlueprint
{
    protected $columnName;     // 字段名称
    protected $columnType;     // 字段类型
    protected $columnLimit;    // 字段限制 如 INT(11) / decimal(10,2) 括号部分
    protected $columnComment;  // 字段注释
    protected $columnCharset;  // 字段编码

    protected $isBinary;         // 二进制 (只允许在字符串类型上设置)
    protected $isUnique;         // 唯一的 (请勿重复设置索引UNI)
    protected $unsigned;         // 无符号 (仅数字类型可以设置)
    protected $zeroFill;         // 零填充 (仅数字类型可以设置)
    protected $defaultValue;     // 默认值 (非TEXT/BLOB可以设置)
    protected $isNotNull = true; // 字段可空 (默认已经设置全部字段不可空)
    protected $autoIncrement;    // 字段自增 (仅数字类型可以设置)
    protected $isPrimaryKey;     // 主键字段 (请勿重复设置索引PK)

    /**
     * ColumnBlueprint constructor.
     * @param string $columnName 列的名称
     * @param string $columnType 列的类型
     * @throws ReflectionException
     */
    function __construct(string $columnName, string $columnType)
    {
        $this->setColumnName($columnName);
        $this->setColumnType($columnType);
    }

    /**
     * 设置字段名称
     * @param string $name 字段名称
     * @return ColumnBlueprint
     */
    function setColumnName(string $name): ColumnBlueprint
    {
        $name = trim($name);
        if (empty($name)) {
            throw new \InvalidArgumentException('The column name cannot be empty');
        }
        $this->columnName = $name;
        return $this;
    }

    /**
     * 设置字段类型
     * @param string $type
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function setColumnType(string $type): ColumnBlueprint
    {
        $type = trim($type);
        if (!DataType::isValidDataTypes($type)) {
            throw new \InvalidArgumentException('The column type ' . $type . ' is invalid');
        }
        $this->columnType = $type;
        return $this;
    }

    /**
     * 设置字段列宽限制
     * @param integer|array $limit
     * @return ColumnBlueprint
     */
    function setColumnLimit($limit): ColumnBlueprint
    {
        // TODO 暂未做规范判断
        // 此处根据类型的不同实际上还应该判断 TEXT/BLOB 不可能存在limit
        // 另外数字类型如 INT DisplayWidth < 256 | DECIMAL (1,5) 总精度必须大于小数部分精度等限制

        $this->columnLimit = $limit;
        return $this;
    }

    /**
     * 设置字段备注
     * @param string $comment
     * @return ColumnBlueprint
     */
    function setColumnComment(string $comment): ColumnBlueprint
    {
        $this->columnComment = $comment;
        return $this;
    }

    /**
     * 设置字段编码
     * @param string $charset
     * @return ColumnBlueprint
     */
    function setColumnCharset(string $charset): ColumnBlueprint
    {
        $this->columnCharset = $charset;
        return $this;
    }


    /**
     * 是否零填充
     * @param bool $enable
     * @return ColumnBlueprint
     */
    function setZeroFill(bool $enable = true): ColumnBlueprint
    {
        $this->zeroFill = $enable;
        return $this;
    }

    /**
     * 是否无符号
     * @param bool $enable
     * @return ColumnBlueprint
     */
    function setIsUnsigned(bool $enable = true): ColumnBlueprint
    {
        // TODO 暂未做规范判断
        // 同样需要做规范判断 字段为文本/日期时间/BLOB时不能设置为无符号

        $this->unsigned = $enable;
        return $this;
    }

    /**
     * 字段默认值
     * @param $value
     * @return ColumnBlueprint
     */
    function setDefaultValue($value): ColumnBlueprint
    {
        // TODO 暂未做规范判断
        // 同样需要做规范判断 字段为文本/BLOB时不能设置默认值

        $this->defaultValue = $value;
        return $this;
    }

    /**
     * 设置不可空
     * @param bool $enable
     * @return ColumnBlueprint
     */
    function setIsNotNull(bool $enable = true): ColumnBlueprint
    {
        $this->isNotNull = $enable;
        return $this;
    }

    /**
     * 是否值自增
     * @param bool $enable
     * @return ColumnBlueprint
     */
    function setIsAutoIncrement(bool $enable = true): ColumnBlueprint
    {
        // TODO 暂未做规范判断
        // 同样需要做规范判断 只有数字类型才允许自增

        $this->autoIncrement = $enable;
        return $this;
    }


    /**
     * 是否二进制
     * 在字符列上设置了二进制会使得该列严格区分大小写
     * @param bool $enable
     * @return ColumnBlueprint
     */
    function setIsBinary(bool $enable = true): ColumnBlueprint
    {
        // TODO 暂未做规范判断
        // 同样需要做规范判断 只有字符串类型才允许二进制

        $this->isBinary = $enable;
        return $this;
    }

    /**
     * 直接在字段上设置PK
     * 请不要和索引互相重复设置
     * @param bool $enable
     * @return ColumnBlueprint
     */
    function setIsPrimaryKey(bool $enable = true): ColumnBlueprint
    {
        $this->isPrimaryKey = $enable;
        return $this;
    }

    /**
     * 字段设置为Unique
     * 请不要和索引互相重复设置
     * @param bool $enable
     * @return ColumnBlueprint
     */
    function setIsUnique(bool $enable = true): ColumnBlueprint
    {
        $this->isUnique = $enable;
        return $this;
    }

    /**
     * 处理字段的默认值
     * @return bool|string
     */
    private function parseDefaultValue()
    {
        // AUTO_INCREMENT 和默认值不能同时出现
        if ($this->autoIncrement) {
            return false;
        }

        // 如果当前允许NULL值 而没有设置默认值 那么默认就为NULL
        if (!$this->isNotNull && ($this->defaultValue = null || $this->defaultValue = 'NULL')) {
            return 'NULL';
        }

        // 否则字段是不允许NULL值的 设置有值应该加引号
        if (is_string($this->defaultValue)) {
            return "'" . $this->defaultValue . "'";
        }

        // 否则没有设置默认值 不需要给默认值
        return false;
    }

    /**
     * 处理字段的类型宽度限制
     * @return bool|string
     */
    private function parseColumnLimit()
    {
        // 是一个数组需要用逗号接起来
        if (is_array($this->columnLimit)) {
            return "(" . implode(',', $this->columnLimit) . ")";
        }

        // 是一个数字可以直接设置在类型后面
        if (is_numeric($this->columnLimit)) {
            return "(" . $this->columnLimit . ")";
        }

        // 否则没有设置
        return false;
    }

    /**
     * 处理数据类型
     * @return string
     */
    private function parseDataType()
    {
        $columnLimit = $this->parseColumnLimit();
        $columnType = $this->columnType;
        if ($columnLimit) {
            $columnType .= $columnLimit;
        }
        return $columnType;
    }

    /**
     * 创建DDL
     * 带下划线的方法请不要外部调用
     * @return string
     */
    function __createDDL(): string
    {
        $default = $this->parseDefaultValue();
        $columnCharset = $this->columnCharset ? explode('_', $this->columnCharset)[0] : false;
        return implode(' ',
            array_filter(
                [
                    '`' . $this->columnName . '`',
                    (string)$this->parseDataType(),
                    $this->isBinary ? 'BINARY' : null,
                    $this->columnCharset ? 'CHARACTER SET ' . strtoupper($columnCharset) . ' COLLATE ' . strtoupper($this->columnCharset) : null,
                    $this->unsigned ? 'UNSIGNED' : null,
                    $this->zeroFill ? 'ZEROFILL' : null,
                    $this->isUnique ? 'UNIQUE' : null,
                    $this->isNotNull ? 'NOT NULL' : 'NULL',
                    $default ? 'DEFAULT ' . $default : null,
                    $this->isPrimaryKey ? 'PRIMARY KEY' : null,
                    $this->autoIncrement ? 'AUTO_INCREMENT' : null,
                    $this->columnComment ? sprintf("COMMENT '%s'", addslashes($this->columnComment)) : null
                ]
            )
        );
    }

    /**
     * 转化为字符串
     * @return string
     */
    function __toString()
    {
        return $this->__createDDL();
    }
}