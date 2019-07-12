<?php

namespace EasySwoole\Mysqli\DDLBuilder\Blueprints;

use EasySwoole\Mysqli\DDLBuilder\Enum\Character;
use EasySwoole\Mysqli\DDLBuilder\Enum\DataType;
use EasySwoole\Mysqli\DDLBuilder\Enum\Engines;
use EasySwoole\Mysqli\DDLBuilder\Enum\IndexType;
use ReflectionException;

/**
 * 创建表结构描述
 * 暂只支持创建表 CREATE 结构
 * Class TableBlueprint
 * @package EasySwoole\Mysqli\DDLBuilder\Blueprints
 */
class TableBlueprint
{
    // 基础属性
    protected $table;
    protected $comment;
    protected $engine = Engines::INNODB;
    protected $charset = Character::UTF8_GENERAL_CI;

    // 结构定义
    protected $columns = [];  // 表的行定义
    protected $indexes = [];  // 表的索引

    // 额外选项
    protected $isTemporary = false;  // 是否临时表
    protected $ifNotExists = false;  // 是否不存在才创建
    protected $autoIncrement;        // 默认自增从该值开始

    /**
     * TableBlueprint constructor.
     * @param $tableName
     */
    function __construct($tableName)
    {
        $this->table = $tableName;
    }

    // 以下为字段构造方法

    /**
     * 整数 INT
     * @param string $name 字段名称
     * @param null|integer $limit INT 4Bytes(2^31)
     * @return mixed
     * @throws ReflectionException
     */
    function colInt(string $name, int $limit = null): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::INT);
        $this->columns[$name]->setColumnLimit($limit);
        return $this->columns[$name];
    }

    /**
     * 整数 BIGINT
     * @param string $name 字段名称
     * @param int|null $limit BIGINT 8Bytes(2^63)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colBigInt(string $name, int $limit = null): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::BIGINT);
        $this->columns[$name]->setColumnLimit($limit);
        return $this->columns[$name];
    }

    /**
     * 整数 TINYINT
     * @param string $name 字段名称
     * @param int|null $limit TINYINT 1Bytes(2^7)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colTinyInt(string $name, int $limit = null): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::TINYINT);
        $this->columns[$name]->setColumnLimit($limit);
        return $this->columns[$name];
    }

    /**
     * 整数 SMALLINT
     * @param string $name 字段名称
     * @param int|null $limit TINYINT 2Bytes(2^15)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colSmallInt(string $name, int $limit = null): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::SMALLINT);
        $this->columns[$name]->setColumnLimit($limit);
        return $this->columns[$name];
    }

    /**
     * 整数 MEDIUMINT
     * @param string $name 字段名称
     * @param int|null $limit MEDIUMINT 3Bytes(2^23)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colMediumInt(string $name, int $limit = null): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::MEDIUMINT);
        $this->columns[$name]->setColumnLimit($limit);
        return $this->columns[$name];
    }

    /**
     * 单精度浮点 - FLOAT
     * 注: 当设置总精度为24-53内部实际上是DOUBLE序列
     * @param string $name 字段名称
     * @param int|null $precision 字段总精度(允许储存多少位数|含小数点)
     * @param int|null $digits 小数点部分的精度(可空)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colFloat(string $name, int $precision = null, int $digits = null): ColumnBlueprint
    {
        if (is_numeric($precision) && is_numeric($digits) && ($digits > $precision)) {
            throw new \InvalidArgumentException('col ' . $name . ' type float(M,D), M must be >= D');
        }
        $this->columns[$name] = new ColumnBlueprint($name, DataType::FLOAT);
        if (is_numeric($precision) && is_numeric($digits)) {
            $this->columns[$name]->setColumnLimit([$precision, $digits]);
        } elseif (is_numeric($precision)) {
            $this->columns[$name]->setColumnLimit($precision);
        }
        return $this->columns[$name];
    }

    /**
     * 双精度浮点 - DOUBLE
     * @param string $name 字段名称
     * @param int|null $precision 字段总精度(允许储存多少位数|含小数点)
     * @param int|null $digits 小数点部分的精度(可空)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colDouble(string $name, int $precision = null, int $digits = null): ColumnBlueprint
    {
        if (is_numeric($precision) && is_numeric($digits) && ($digits > $precision)) {
            throw new \InvalidArgumentException('col ' . $name . ' type double(M,D), M must be >= D');
        }
        $this->columns[$name] = new ColumnBlueprint($name, DataType::DOUBLE);
        if (is_numeric($precision) && is_numeric($digits)) {
            $this->columns[$name]->setColumnLimit([$precision, $digits]);
        } elseif (is_numeric($precision)) {
            $this->columns[$name]->setColumnLimit($precision);
        }
        return $this->columns[$name];
    }

    /**
     * 定点小数 - DECIMAL
     * 注意当设置小数精度和总精度一致时整数部分只能为零
     * 注: 当未设置精度时MYSQL默认按 DECIMAL(10,0) 所以此处给出默认值
     * @param string $name 字段名称
     * @param int $precision 字段总精度(允许储存多少位数|含小数点)
     * @param int $digits 小数点部分的精度
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colDecimal(string $name, int $precision = 10, int $digits = 0): ColumnBlueprint
    {
        if ($digits > $precision) {
            throw new \InvalidArgumentException('col ' . $name . ' type decimal(M,D), M must be >= D');
        }
        $this->columns[$name] = new ColumnBlueprint($name, DataType::DECIMAL);
        $this->columns[$name]->setColumnLimit([$precision, $digits]);
        return $this->columns[$name];
    }

    /**
     * 日期时间 - DATE
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colDate(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::DATE);
        return $this->columns[$name];
    }

    /**
     * 日期时间 - YEAR
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colYear(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::YEAR);
        return $this->columns[$name];
    }

    /**
     * 日期时间 - TIME
     * @param string $name 字段名称
     * @param int|null $fsp 精度分数(详见MYSQL文档)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colTime(string $name, ?int $fsp = null): ColumnBlueprint
    {
        if ($fsp < 0 || $fsp > 6) {
            throw new \InvalidArgumentException('col ' . $name . ' type time(fsp), fsp must be range 0 to 6');
        }
        $this->columns[$name] = new ColumnBlueprint($name, DataType::TIME);
        if (is_numeric($fsp)) $this->columns[$name]->setColumnLimit($fsp);
        return $this->columns[$name];
    }

    /**
     * 日期时间 - DATETIME
     * @param string $name 字段名称
     * @param int|null $fsp 精度分数(详见MYSQL文档)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colDateTime(string $name, ?int $fsp = null): ColumnBlueprint
    {
        if ($fsp < 0 || $fsp > 6) {
            throw new \InvalidArgumentException('col ' . $name . ' type datetime(fsp), fsp must be range 0 to 6');
        }
        $this->columns[$name] = new ColumnBlueprint($name, DataType::DATETIME);
        if (is_numeric($fsp)) $this->columns[$name]->setColumnLimit($fsp);
        return $this->columns[$name];
    }

    /**
     * 日期时间 - TIMESTAMP
     * @param string $name 字段名称
     * @param int|null $fsp 精度分数(详见MYSQL文档)
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colTimestamp(string $name, ?int $fsp = null): ColumnBlueprint
    {
        if ($fsp < 0 || $fsp > 6) {
            throw new \InvalidArgumentException('col ' . $name . ' type timestamp(fsp), fsp must be range 0 to 6');
        }
        $this->columns[$name] = new ColumnBlueprint($name, DataType::TIMESTAMP);
        if (is_numeric($fsp)) $this->columns[$name]->setColumnLimit($fsp);
        return $this->columns[$name];
    }

    /**
     * 字符串 - CHAR
     * @param string $name 字段名称
     * @param int|null $limit
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colChar(string $name, ?int $limit = null): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::CHAR);
        $this->columns[$name]->setColumnLimit($limit);
        return $this->columns[$name];
    }

    /**
     * 字符串 - VARCHAR
     * @param string $name 字段名称
     * @param int|null $limit
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colVarChar(string $name, ?int $limit = null): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::VARCHAR);
        $this->columns[$name]->setColumnLimit($limit);
        return $this->columns[$name];
    }

    /**
     * 字符串 - TEXT
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colText(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::TEXT);
        return $this->columns[$name];
    }

    /**
     * 字符串 - TINYTEXT
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colTinyText(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::TINYTEXT);
        return $this->columns[$name];
    }

    /**
     * 字符串 - LONGTEXT
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colLongText(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::LONGTEXT);
        return $this->columns[$name];
    }

    /**
     * 字符串 - MEDIUMTEXT
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colMediumText(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::MEDIUMTEXT);
        return $this->columns[$name];
    }

    /**
     * 二进制字符串 - BLOB
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colBlob(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::BLOB);
        return $this->columns[$name];
    }

    /**
     * 二进制字符串 - LONGBLOB
     * @param string $name 字段名称
     * @return ColumnBlueprint
     * @throws ReflectionException
     */
    function colLongBlob(string $name): ColumnBlueprint
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::LONGBLOB);
        return $this->columns[$name];
    }

    /**
     * 二进制字符串 - TINYBLOB
     * @param string $name 字段名称
     * @return mixed
     * @throws ReflectionException
     */
    function colTinyBlob(string $name)
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::TINYBLOB);
        return $this->columns[$name];
    }

    /**
     * 二进制字符串 - MEDIUMBLOB
     * @param string $name 字段名称
     * @return mixed
     * @throws ReflectionException
     */
    function colMediumBlob(string $name)
    {
        $this->columns[$name] = new ColumnBlueprint($name, DataType::MEDIUMBLOB);
        return $this->columns[$name];
    }


    // 以下为索引构造方法

    /**
     * 普通索引
     * @param string|null $name 索引名称(不需要名称也可以传null)
     * @param string|array $columns 索引字段(多个字段可以传入数组)
     * @return mixed
     * @throws ReflectionException
     */
    function indexNormal(string $name, $columns): IndexBlueprint
    {
        $this->indexes[$name] = new IndexBlueprint($name, IndexType::NORMAL, $columns);
        return $this->indexes[$name];
    }

    /**
     * 唯一索引
     * 请注意这属于约束的一种类型 不要和字段上的约束重复定义
     * @param string|null $name 索引名称(不需要名称也可以传null)
     * @param string|array $columns 索引字段(多个字段可以传入数组)
     * @return mixed
     * @throws ReflectionException
     */
    function indexUnique(string $name, $columns): IndexBlueprint
    {
        $this->indexes[$name] = new IndexBlueprint($name, IndexType::UNIQUE, $columns);
        return $this->indexes[$name];
    }

    /**
     * 主键索引
     * 请注意这属于约束的一种类型 不要和字段上的约束重复定义
     * @param string|null $name 索引名称(不需要名称也可以传null)
     * @param string|array $columns 索引字段(多个字段可以传入数组)
     * @return mixed
     * @throws ReflectionException
     */
    function indexPrimary(string $name, $columns): IndexBlueprint
    {
        $this->indexes[$name] = new IndexBlueprint($name, IndexType::PRIMARY, $columns);
        return $this->indexes[$name];
    }

    /**
     * 全文索引
     * 请注意该类型的索引只能施加在TEXT类型的字段上
     * @param string|null $name 索引名称(不需要名称也可以传null)
     * @param string|array $columns 索引字段(多个字段可以传入数组)
     * @return mixed
     * @throws ReflectionException
     */
    function indexFullText(string $name, $columns): IndexBlueprint
    {
        $this->indexes[$name] = new IndexBlueprint($name, IndexType::FULLTEXT, $columns);
        return $this->indexes[$name];
    }

    // 以下为表本身属性的设置方法

    /**
     * 这是一个临时表
     * @param bool $enable
     * @return TableBlueprint
     */
    function setIsTemporary($enable = true): TableBlueprint
    {
        $this->isTemporary = $enable;
        return $this;
    }


    /**
     * CREATE IF NOT EXISTS
     * @param bool $enable
     * @return TableBlueprint
     */
    function setIfNotExists($enable = true): TableBlueprint
    {
        $this->ifNotExists = $enable;
        return $this;
    }

    /**
     * 设置表名称
     * @param string $name
     * @return TableBlueprint
     */
    function setTableName(string $name): TableBlueprint
    {
        $name = trim($name);
        if (empty($name)) {
            throw new \InvalidArgumentException('The table name cannot be empty');
        }
        $this->table = $name;
        return $this;
    }

    /**
     * 设置储存引擎
     * @param string $engine
     * @return TableBlueprint
     * @throws ReflectionException
     */
    function setTableEngine(string $engine): TableBlueprint
    {
        $engine = trim($engine);
        if (!Engines::isValidEngines($engine)) {
            throw new \InvalidArgumentException('The engine ' . $engine . ' is invalid');
        }
        $this->engine = $engine;
        return $this;
    }

    /**
     * 设置表注释
     * @param string $comment
     * @return TableBlueprint
     */
    function setTableComment(string $comment): TableBlueprint
    {
        $this->comment = $comment;
        return $this;
    }

    /**
     * 设置表字符集
     * @param string $charset
     * @return TableBlueprint
     * @throws ReflectionException
     */
    function setTableCharset(string $charset): TableBlueprint
    {
        $charset = trim($charset);
        if (!Character::isValidCharacters($charset)) {
            throw new \InvalidArgumentException('The character ' . $charset . ' is invalid');
        }
        $this->charset = $charset;
        return $this;
    }

    /**
     * 设置起始自增值
     * @param int $startIncrement
     */
    function setTableAutoIncrement(int $startIncrement)
    {
        $this->autoIncrement = $startIncrement;

    }

    // 生成表结构 带有下划线的方法请不要自行调用
    function __createDDL()
    {
        // 表名称定义
        $tableName = "`{$this->table}`"; // 安全起见引号包裹

        // 表格字段定义
        $columnDefinitions = [];
        foreach ($this->columns as $name => $column) {
            $columnDefinitions[] = '  ' . (string)$column;
        }

        // 表格索引定义
        $indexDefinitions = [];
        foreach ($this->indexes as $name => $index) {
            $indexDefinitions[] = '  ' . (string)$index;
        }

        // 表格属性定义
        $tableOptions = array_filter(
            [
                $this->engine ? 'ENGINE = ' . strtoupper($this->engine) : null,
                $this->charset ? "DEFAULT COLLATE = '" . $this->charset . "'" : null,
                $this->comment ? "COMMENT = '" . addslashes($this->comment) . "'" : null
            ]
        );

        // 构建表格DDL
        $createDDL = implode(
                "\n",
                array_filter(
                    [
                        "CREATE TABLE {$tableName} (",
                        implode(",\n",
                            array_merge(
                                $columnDefinitions,
                                $indexDefinitions
                            )
                        ),
                        ')',
                        implode(" ", $tableOptions),
                    ]
                )
            ) . ';';

        return $createDDL;
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