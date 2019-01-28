<?php
/**
 * Created by yf's PhpStorm.
 * User: hanwenbo
 * Date: 2019-01-28
 * Time: 12:20
 */

namespace EasySwoole\Mysqli;

use EasySwoole\Spl\SplString;

class TpORM extends DbObject
{
	/**
	 * 数据库前缀
	 * @var string 
	 */
	protected $prefix ='';
	/**
	 * 自动加载的TpORM默认命名空间
	 * @var string 
	 */
	protected $modelPath = '\\App\\TpORM';
	/**
	 * 输出的字段
	 * @var array 
	 */
	protected $fields = [];
	/**
	 * 条数或者开始和结束
	 * @var array | string
	 */
	protected $limit;

	public function __construct( $data = null )
	{
		if( empty( $this->dbTable ) ){
			$split         = explode( "\\", get_class( $this ) );
			$end           = end( $split );
			$splString     = new SplString( $end );
			// 大写骆峰式命名的文件转为下划线区分表 todo 未来需要增加配置开关是否需要
			$name          = $splString->snake( '_' )->__toString();
			// 给表加别名，解决json场景下不需要手动给字段加前缀
			$this->dbTable = $this->prefix.$name." AS {$name}";
		}
		parent::__construct( $data );
	}

	/**
	 * @param string $objectNames
	 * @param string $joinStr
	 * @param string $joinType
	 * @return TpORM
	 * @throws \EasySwoole\Mysqli\Exceptions\JoinFail
	 */
	protected function join( $objectNames, string $joinStr, string $joinType = 'LEFT' ) : TpORM
	{
		if( is_array( $objectNames ) ){
			foreach( $objectNames as $join ){
				$this->getDb()->join( ...$join );
			}
		} else{
			$this->getDb()->join( ...$objectNames );
		}

		return $this;
	}

	/**
	 * @return array
	 * @throws Exceptions\ConnectFail
	 * @throws Exceptions\Option
	 * @throws Exceptions\PrepareQueryFail
	 * @throws \Throwable
	 */
	protected function find() : array
	{
		$list = parent::get( 1, $this->fields );
		return isset( $list[0] ) ? $list[0] : [];
	}

	protected function field( $field ) : TpORM
	{
		$this->fields = $field;
		return $this;
	}

	protected function limit( $limit ) : TpORM
	{
		$this->limit = $limit;
		return $this;
	}

	protected function page( string $page ) : TpORM
	{
		$split = explode( ",", $page );
		$page  = $split[0] - 1;
		$rows  = $split[1];
		return $this->limit( "{$page},{$rows}" );
	}

	/**
	 * @return array
	 * @throws Exceptions\ConnectFail
	 * @throws Exceptions\Option
	 * @throws Exceptions\PrepareQueryFail
	 * @throws \Throwable
	 */
	protected function select() : array
	{
		return parent::get( $this->limit, $this->fields );
	}

	protected function where( $whereProps, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND' ) : TpORM
	{
		if( is_array( $whereProps ) ){
			foreach( $whereProps as $whereProp ){
				$this->getDb()->where( ...$whereProp );
			}
		} else{
			$this->getDb()->where( $whereProps, $whereValue, $operator, $cond );
		}
		return $this;
	}
}