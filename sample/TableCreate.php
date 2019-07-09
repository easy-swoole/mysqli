<?php

use EasySwoole\Mysqli\Table\Column;

require_once '../vendor/autoload.php';

$column = (new Column('adminUserName', 'VARCHAR(255)'))
    ->setAutoIncrement(true)
    ->setNullable(false)
    ->setComment('管理员名称');
var_dump($column->__toString());

// table has some attributes
// name columns indexes engine foreignKeys defaultCollation rowFormat comment