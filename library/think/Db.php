<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2017 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

use think\Facade;

class Db
{
    // 查询次数
    public static $queryTimes = 0;
    // 执行次数
    public static $executeTimes = 0;

    public static function connect($connection = [])
    {
        $class = Facade::make('config')->get('database.query') ?: '\\think\\db\\Query';
        $query = new $class();

        if ($connection) {
            $query->connect($connection);
        }

        return $query;
    }

    public static function __callStatic($method, $args)
    {
        return call_user_func_array([self::connect(), $method], $args);
    }
}
