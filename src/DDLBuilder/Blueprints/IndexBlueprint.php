<?php

namespace EasySwoole\Mysqli\DDLBuilder\Blueprints;

use EasySwoole\Mysqli\DDLBuilder\Enum\IndexType;
use ReflectionException;

/**
 * 创建表的索引描述
 * TODO 全部索引都未做字段长度检测 另外需要能够指定 BTREE/RTREE/FULLTEXT/HASH 索引类型
 * Class IndexBlueprint
 * @package EasySwoole\Mysqli\DDLBuilder\Blueprints
 */
class IndexBlueprint
{
    protected $indexName;     // 索引名称
    protected $indexType;     // 索引类型 NORMAL PRI UNI FULLTEXT
    protected $indexColumns;  // 被索引的列 字符串或数组(多个列)
    protected $indexComment;  // 索引注释

    /**
     * IndexBlueprint constructor.
     * @param string|null $indexName 不设置索引名可以传入NULL
     * @param string $indexType 传入类型常量
     * @param string|array $indexColumns 传入索引字段
     * @throws ReflectionException
     */
    function __construct(?string $indexName, $indexType, $indexColumns)
    {
        $this->setIndexName($indexName);
        $this->setIndexType($indexType);
        $this->setIndexColumns($indexColumns);
    }

    /**
     * 设置索引名称
     * @param string $name
     * @return IndexBlueprint
     */
    function setIndexName(?string $name = null): IndexBlueprint
    {
        $name = is_string($name) ? trim($name) : null;
        $this->indexName = $name;
        return $this;
    }

    /**
     * 设置索引类型
     * @param string $type
     * @return IndexBlueprint
     * @throws ReflectionException
     */
    function setIndexType(string $type): IndexBlueprint
    {
        $type = trim($type);
        if (!IndexType::isValidIndexTypes($type)) {
            throw new \InvalidArgumentException('The index type ' . $type . ' is invalid');
        }
        $this->indexType = $type;
        return $this;
    }

    /**
     * 设置索引字段
     * @param string|array $columns 可以设置字符串和数组
     * @return IndexBlueprint
     */
    function setIndexColumns($columns): IndexBlueprint
    {
        $this->indexColumns = $columns;
        return $this;
    }

    /**
     * 设置索引备注
     * @param string $comment
     * @return IndexBlueprint
     */
    function setIndexComment(string $comment): IndexBlueprint
    {
        $this->indexComment = $comment;
        return $this;
    }

    /**
     * 组装索引字段名
     * @return string
     */
    function parseIndexColumns()
    {
        $columnDDLs = [];
        $indexColumns = $this->indexColumns;
        if (is_string($indexColumns)) {
            $indexColumns = array($indexColumns);
        }
        foreach ($indexColumns as $indexedColumn) {
            $columnDDLs[] = '`' . $indexedColumn . '`';
        }
        return '(' . implode(',', $columnDDLs) . ')';
    }

    /**
     * 生成索引DDL结构
     * 带有下划线的方法请不要自行调用
     * @return string
     */
    function __createDDL()
    {
        $indexPrefix = [
            IndexType::NORMAL   => 'INDEX',
            IndexType::UNIQUE   => 'UNIQUE INDEX',
            IndexType::PRIMARY  => 'PRIMARY KEY',
            IndexType::FULLTEXT => 'FULLTEXT INDEX',
        ];
        return implode(' ',
            array_filter(
                [
                    $indexPrefix[$this->indexType],
                    $this->indexName !== null ? '`' . $this->indexName . '`' : null,
                    $this->parseIndexColumns(),
                    $this->indexComment ? "COMMENT '" . addslashes($this->indexComment) . "'" : null
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