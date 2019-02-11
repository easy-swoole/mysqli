<?php
/**
 * Created by yf's PhpStorm.
 * User: hanwenbo
 * Date: 2019-01-28
 * Time: 12:20
 */

namespace EasySwoole\Mysqli;

use EasySwoole\Spl\SplString;

/**
 * Class TpORM
 * @package EasySwoole\Mysqli
 */
class TpORM extends DbObject
{
	/**
	 * 数据库前缀
	 * @var string
	 */
	protected $prefix = '';
	/**
	 * 不带前缀的表名
	 * @var string
	 */
	protected $name = '';
	/**
	 * 输出的字段
	 * @var array | string
	 */
	protected $fields = '*';
	/**
	 * 条数或者开始和结束
	 * @var array | int
	 */
	protected $limit;

	public function __construct( $data = null )
	{
		if( empty( $this->dbTable ) ){
			$split     = explode( "\\", get_class( $this ) );
			$end       = end( $split );
			$splString = new SplString( $end );
			// 大写骆峰式命名的文件转为下划线区分表 todo 未来需要增加配置开关是否需要
			$name       = $splString->snake( '_' )->__toString();
			$this->name = $name;
			// 给表加前缀
			$this->dbTable = $this->prefix.$name;
		}
		parent::__construct( $data );
	}

	/**
	 * @param string | array $objectNames
	 * @param string         $joinStr
	 * @param string         $joinType
	 * @throws \EasySwoole\Mysqli\Exceptions\JoinFail
	 */
	protected function join( $objectNames, string $joinStr = null, string $joinType = 'LEFT' )
	{
		// 给表加别名，解决join场景下不需要手动给字段加前缀
		$this->dbTable = $this->dbTable." AS {$this->name}";
		if( is_array( $objectNames ) ){
			foreach( $objectNames as $join ){
				parent::join( ...$join );
			}
		} else{
			parent::join( $objectNames, $joinStr, $joinType );
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
	protected function find()
	{
		$list = parent::get( 1, $this->fields );
		return isset( $list[0] ) ? $list[0] : [];
	}

	/**
	 * @param array | string $field
	 * @return $this
	 */
	protected function field( $field )
	{
		$this->fields = $field;
		return $this;
	}

	/**
	 * @param array | int eg : $limit [0,10] ， 1
	 * @return $this
	 */
	protected function limit( $limit )
	{
		$this->limit = $limit;
		return $this;
	}

	/**
	 * @param array $pageInfo eg : [1,10]
	 * @return TpORM
	 */
	protected function page( array $pageInfo )
	{
		$page = $pageInfo[0] - 1;
		$rows = $pageInfo[1];
		$this->limit( [$page, $rows] );
		return $this;
	}

	/**
	 * @param string $orderByField
	 * @param string $orderByDirection
	 * @param null   $customFieldsOrRegExp
	 * @return $this
	 * @throws Exceptions\OrderByFail
	 */
	protected function order( string $orderByField, string $orderByDirection = "DESC", $customFieldsOrRegExp = null )
	{
		$this->getDb()->orderBy( $orderByField, $orderByDirection, $customFieldsOrRegExp );
		return $this;
	}

	/**
	 * @return array |false| null
	 * @throws Exceptions\ConnectFail
	 * @throws Exceptions\Option
	 * @throws Exceptions\PrepareQueryFail
	 * @throws \Throwable
	 */
	protected function select()
	{
		return parent::get( $this->limit, $this->fields );
	}

	/**
	 * @param        $whereProps
	 * @param string $whereValue
	 * @param string $operator
	 * @param string $cond
	 * @return $this|DbObject
	 */
	protected function where( $whereProps, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND' )
	{
		if( is_array( $whereProps ) ){
			foreach( $whereProps as $field => $value ){
				if( key( $value ) === 0 ){
					// 用于支持['in',[123,232,32,3,4]]格式
					$this->getDb()->where( $field, [$value[0] => $value[1]] );
				} else{
					// 用于支持['in'=>[12,23,23]]格式
					$this->getDb()->where( $field, $value );
				}
			}
		} else{
			$this->getDb()->where( $whereProps, $whereValue, $operator, $cond );
		}
		return $this;
	}

	/**
	 * 可选的更新数据应用于对象
	 * 如果不存在
	 * @param null $data
	 * @return bool|mixed
	 * @throws Exceptions\ConnectFail
	 * @throws Exceptions\PrepareQueryFail
	 * @throws \Throwable
	 */
	public function update( $data = null )
	{
		// 对象方式的修改
		if( isset( $this->data[$this->primaryKey] ) ){
			return parent::update( $data );
		} else{
			// 过滤约束的fields个是
			foreach( $data as $key => &$value ){
				if( in_array( $key, $this->toSkip ) ){
					continue;
				}

				if( !in_array( $key, array_keys( $this->dbFields ) ) ){
					continue;
				}

				if( !is_array( $value ) ){
					$sqlData[$key] = $value;
					continue;
				}

				if( isset ( $this->jsonFields ) && in_array( $key, $this->jsonFields ) ){
					$sqlData[$key] = json_encode( $value );
				} else if( isset ( $this->arrayFields ) && in_array( $key, $this->arrayFields ) ){
					$sqlData[$key] = implode( "|", $value );
				} else{
					$sqlData[$key] = $value;
				}
			}
			$res = $this->getDb()->update( $this->dbTable, $sqlData );
			return $res;
		}
	}

	/**
	 * 删除的方法。只在定义了对象primaryKey时才有效
	 * @return bool|null 表示成功。0或1。
	 * @throws Exceptions\ConnectFail
	 * @throws Exceptions\PrepareQueryFail
	 * @throws \Throwable
	 */
	public function delete()
	{
		// 对象方式的修改
		if( isset( $this->data[$this->primaryKey] ) ){
			return parent::delete();
		} else{
			$res          = $this->getDb()->delete( $this->dbTable );
			$this->toSkip = [];
			return $res;
		}
	}
}