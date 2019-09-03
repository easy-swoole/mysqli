# Mysqli
本Mysqli构造器基于 https://github.com/ThingEngineer/PHP-MySQLi-Database-Class 移植实现的协成安全版本。
## 单元测试
```php
./vendor/bin/co-phpunit tests
```

## 安装
```
composer require easyswoole/mysqli
```

## Client实例

## 查询构造器
QueryBuilder是一个SQL构造器，用来构造prepare sql。例如：
```php
use EasySwoole\Mysqli\QueryBuilder;

$builder = new QueryBuilder();

//执行条件构造逻辑
$builder->where('col1',2)->get('my_table');

//获取上次条件构造的预处理sql语句
echo $builder->getLastPrepareQuery();
// SELECT  * FROM whereGet WHERE  col1 = ? 

//获取上次条件构造的sql语句
echo $builder->getLastQuery();
//SELECT  * FROM whereGet WHERE  col1 = 2 

//获取上次条件构造的预处理sql语句所以需要的绑定参数
echo $builder->getLastBindParams();
//[2]
```

### GET


### UPDATE
### DELETE
### INSERT