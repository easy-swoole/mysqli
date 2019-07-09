<?php


namespace EasySwoole\Mysqli\Table;


use EasySwoole\Spl\SplBean;

class Column extends SplBean
{
    protected $name;
    protected $type;
    protected $isPk = false;
    protected $isNN = false;
    protected $isUQ = false;
    protected $isBIN = false;
    protected $isUN = false;
    protected $isZF = false;
    protected $isAI = false;
    protected $isG = false;
    protected $default = null;
}