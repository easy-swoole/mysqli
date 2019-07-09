<?php

namespace EasySwoole\Mysqli\Table;

use EasySwoole\Spl\SplBean;

// TODO INDEX 实际上有几种情况 需要区分
// PK_INDEX 主键 UNI_INDEX 唯一索引 FULL_TEXT 全文索引 和 普通的索引
// 需要能够指定索引类型 B+Tree / Hash
class Index extends SplBean
{

}