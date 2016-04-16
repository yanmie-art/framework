<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\db;

use PDO;
use think\Config;
use think\Db;
use think\db\Query;
use think\Debug;
use think\Exception;
use think\exception\DbBindParamException;
use think\exception\PDOException;
use think\Loader;
use think\Log;

abstract class Connection
{
    // PDO操作实例
    protected $PDOStatement = null;
    // 当前操作的数据表名
    protected $table = '';
    // 当前操作的数据对象名
    protected $name = '';
    // 当前SQL指令
    protected $queryStr = '';
    // 最后插入ID
    protected $lastInsID = null;
    // 返回或者影响记录数
    protected $numRows = 0;
    // 事务指令数
    protected $transTimes = 0;
    // 错误信息
    protected $error = '';
    // 数据库连接ID 支持多个连接
    protected $links = [];
    // 当前连接ID
    protected $linkID = null;
    // 查询结果类型
    protected $fetchType = PDO::FETCH_ASSOC;
    // 监听回调
    protected static $event = [];

    // 数据库连接参数配置
    protected $config = [
        // 数据库类型
        'type'          => '',
        // 服务器地址
        'hostname'      => '',
        // 数据库名
        'database'      => '',
        // 用户名
        'username'      => '',
        // 密码
        'password'      => '',
        // 端口
        'hostport'      => '',
        'dsn'           => '',
        // 数据库连接参数
        'params'        => [],
        // 数据库编码默认采用utf8
        'charset'       => 'utf8',
        // 数据库表前缀
        'prefix'        => '',
        // 数据库调试模式
        'debug'         => false,
        // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
        'deploy'        => 0,
        // 数据库读写是否分离 主从式有效
        'rw_separate'   => false,
        // 读写分离后 主服务器数量
        'master_num'    => 1,
        // 指定从服务器序号
        'slave_no'      => '',
        // like字段自动替换为%%包裹
        'like_fields'   => '',
        // 是否严格检查字段是否存在
        'fields_strict' => true,
    ];

    // PDO连接参数
    protected $params = [
        PDO::ATTR_CASE              => PDO::CASE_LOWER,
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS      => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES  => false,
    ];

    /**
     * 架构函数 读取数据库配置信息
     * @access public
     * @param array $config 数据库配置数组
     */
    public function __construct($config = '')
    {
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
            if (is_array($this->config['params'])) {
                $this->params = $this->config['params'] + $this->params;
            }
        }
        $this->query = new Query($this);
    }

    /**
     * 调用Query类的查询方法
     * @access public
     * @param string $method 方法名称
     * @param array $args 调用参数
     * @return mixed
     */
    public function __call($method, $args)
    {
        return call_user_func_array([$this->query, $method], $args);
    }

    /**
     * 指定当前数据表
     * @access public
     */
    public function setTable($table)
    {
        $this->table = $table;
    }

    public function getAttribute($config)
    {
        return $this->config[$config];
    }

    /**
     * 连接数据库方法
     * @access public
     */
    public function connect($config = '', $linkNum = 0, $autoConnection = false)
    {
        if (!isset($this->links[$linkNum])) {
            if (empty($config)) {
                $config = $this->config;
            }

            try {
                if (empty($config['dsn'])) {
                    $config['dsn'] = $this->parseDsn($config);
                }
                $this->links[$linkNum] = new PDO($config['dsn'], $config['username'], $config['password'], $this->params);
                // 记录数据库连接信息
                APP_DEBUG && Log::record('[ DB ] CONNECT: ' . $config['dsn'], 'info');
            } catch (\PDOException $e) {
                if ($autoConnection) {
                    Log::record($e->getMessage(), 'error');
                    return $this->connect($autoConnection, $linkNum);
                } else {
                    throw new Exception($e->getMessage());
                }
            }
        }
        return $this->links[$linkNum];
    }

    public function getDriverName()
    {
        if ($this->linkID) {
            return $this->linkID->getAttribute(PDO::ATTR_DRIVER_NAME);
        } else {
            return $this->config['type'];
        }
    }

    /**
     * 解析pdo连接的dsn信息
     * @access public
     * @param array $config 连接信息
     * @return string
     */
    protected function parseDsn($config)
    {}

    /**
     * 释放查询结果
     * @access public
     */
    public function free()
    {
        $this->PDOStatement = null;
    }

    /**
     * 获取PDO对象
     * @access public
     */
    public function getPdo()
    {
        if (!$this->linkID) {
            return false;
        } else {
            return $this->linkID;
        }
    }

    /**
     * 执行查询 返回数据集
     * @access public
     * @param string $sql  sql指令
     * @param array $bind 参数绑定
     * @param boolean $fetch  不执行只是获取SQL
     * @param boolean $master  是否在主服务器读操作
     * @param bool $returnPdo  是否返回 PDOStatement 对象
     * @return mixed
     */
    public function query($sql, $bind = [], $fetch = false, $master = false, $returnPdo = false)
    {
        $this->initConnect($master);
        if (!$this->linkID) {
            return false;
        }

        // 根据参数绑定组装最终的SQL语句
        $this->queryStr = $this->getBindSql($sql, $bind);

        if ($fetch) {
            return $this->queryStr;
        }
        //释放前次的查询结果
        if (!empty($this->PDOStatement)) {
            $this->free();
        }

        Db::$queryTimes++;
        try {
            // 调试开始
            $this->debug(true);
            // 预处理
            $this->PDOStatement = $this->linkID->prepare($sql);
            // 参数绑定
            $this->bindValue($bind);
            // 执行查询
            $result = $this->PDOStatement->execute();
            // 调试结束
            $this->debug(false);
            return $returnPdo ? $this->PDOStatement : $this->getResult();
        } catch (\PDOException $e) {
            throw new PDOException($e, $this->config, $this->queryStr);
        }
    }

    /**
     * 执行语句
     * @access public
     * @param string $sql  sql指令
     * @param array $bind 参数绑定
     * @param boolean $fetch  不执行只是获取SQL
     * @return integer
     */
    public function execute($sql, $bind = [], $fetch = false)
    {
        $this->initConnect(true);
        if (!$this->linkID) {
            return false;
        }
        // 根据参数绑定组装最终的SQL语句
        $this->queryStr = $this->getBindSql($sql, $bind);

        if ($fetch) {
            return $this->queryStr;
        }
        //释放前次的查询结果
        if (!empty($this->PDOStatement)) {
            $this->free();
        }

        Db::$executeTimes++;
        try {
            // 调试开始
            $this->debug(true);
            // 预处理
            $this->PDOStatement = $this->linkID->prepare($sql);
            // 参数绑定操作
            $this->bindValue($bind);
            // 执行语句
            $result = $this->PDOStatement->execute();
            // 调试结束
            $this->debug(false);

            $this->numRows = $this->PDOStatement->rowCount();
            if (preg_match("/^\s*(INSERT\s+INTO|REPLACE\s+INTO)\s+/i", $sql)) {
                $this->lastInsID = $this->linkID->lastInsertId();
            }
            return $this->numRows;
        } catch (\PDOException $e) {
            throw new PDOException($e, $this->config, $this->queryStr);
        }
    }

    /**
     * 组装最终的SQL语句 便于调试
     * @access public
     * @param string $sql 带参数绑定的sql语句
     * @param array $bind 参数绑定列表
     * @return string
     */
    protected function getBindSql($sql, array $bind = [])
    {
        if ($bind) {
            foreach ($bind as $key => $val) {
                $val = $this->quote(is_array($val) ? $val[0] : $val);
                // 判断占位符
                $sql = is_numeric($key) ?
                substr_replace($sql, $val, strpos($sql, '?'), 1) :
                str_replace([':' . $key . ')', ':' . $key . ' '], [$val . ')', $val . ' '], $sql . ' ');
            }
        }
        return $sql;
    }

    /**
     * 参数绑定
     * 支持 ['name'=>'value','id'=>123] 对应命名占位符
     * 或者 ['value',123] 对应问号占位符
     * @access public
     * @param array $bind 要绑定的参数列表
     * @return void
     * @throws \think\Exception
     */
    protected function bindValue(array $bind = [])
    {
        foreach ($bind as $key => $val) {
            // 占位符
            $param = is_numeric($key) ? $key + 1 : ':' . $key;
            if (is_array($val)) {
                $result = $this->PDOStatement->bindValue($param, $val[0], $val[1]);
            } else {
                $result = $this->PDOStatement->bindValue($param, $val);
            }
            if (!$result) {
                throw new DbBindParamException(
                    "Error occurred  when binding parameters '{$param}'",
                    $this->config,
                    $this->queryStr,
                    $bind
                );
            }
        }
    }

    /**
     * 获得数据集
     * @access private
     * @return array
     */
    private function getResult()
    {
        $result        = $this->PDOStatement->fetchAll($this->fetchType);
        $this->numRows = count($result);
        return $result;
    }

    /**
     * 执行数据库事务
     * @access public
     * @param callable $callback 数据操作方法回调
     * @return void
     */
    public function transaction($callback)
    {
        $this->startTrans();
        try {
            if (is_callable($callback)) {
                call_user_func_array($callback, []);
            }
            $this->commit();
        } catch (\PDOException $e) {
            $this->rollback();
        }
    }

    /**
     * 启动事务
     * @access public
     * @return void
     */
    public function startTrans()
    {
        $this->initConnect(true);
        if (!$this->linkID) {
            return false;
        }

        //数据rollback 支持
        if (0 == $this->transTimes) {
            $this->linkID->beginTransaction();
        }
        $this->transTimes++;
        return;
    }

    /**
     * 用于非自动提交状态下面的查询提交
     * @access public
     * @return boolen
     */
    public function commit()
    {
        if ($this->transTimes > 0) {
            try {
                $this->linkID->commit();
                $this->transTimes = 0;
            } catch (\PDOException $e) {
                throw new PDOException($e, $this->config, $this->queryStr);
            }
        }
        return true;
    }

    /**
     * 事务回滚
     * @access public
     * @return boolen
     */
    public function rollback()
    {
        if ($this->transTimes > 0) {
            try {
                $this->linkID->rollback();
                $this->transTimes = 0;
            } catch (\PDOException $e) {
                throw new PDOException($e, $this->config, $this->queryStr);
            }
        }
        return true;
    }

    /**
     * 批处理执行SQL语句
     * 批处理的指令都认为是execute操作
     * @access public
     * @param array $sql  SQL批处理指令
     * @return boolean
     */
    public function batchQuery($sql = [])
    {
        if (!is_array($sql)) {
            return false;
        }
        // 自动启动事务支持
        $this->startTrans();
        try {
            foreach ($sql as $_sql) {
                $result = $this->execute($_sql);
            }
            // 提交事务
            $this->commit();
        } catch (\PDOException $e) {
            $this->rollback();
            return false;
        }
        return true;
    }

    /**
     * 将SQL语句中的__TABLE_NAME__字符串替换成带前缀的表名（小写）
     * @access public
     * @param string $sql sql语句
     * @return string
     */
    public function parseSqlTable($sql)
    {
        if (false !== strpos($sql, '__')) {
            $prefix = $this->tablePrefix;
            $sql    = preg_replace_callback("/__([A-Z0-9_-]+)__/sU", function ($match) use ($prefix) {
                return $prefix . strtolower($match[1]);
            }, $sql);
        }
        return $sql;
    }

    /**
     * 得到完整的数据表名
     * @access public
     * @return string
     */
    public function getTableName()
    {
        if (!$this->table) {
            $tableName = $this->config['prefix'];
            $tableName .= Loader::parseName($this->name);
        } else {
            $tableName = $this->table;
        }
        return $tableName;
    }

    /**
     * 设置当前name
     * @access public
     * @param string $name
     * @return $this
     */
    public function name($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 获得查询次数
     * @access public
     * @param boolean $execute 是否包含所有查询
     * @return integer
     */
    public function getQueryTimes($execute = false)
    {
        return $execute ? Db::$queryTimes + Db::$executeTimes : Db::$queryTimes;
    }

    /**
     * 获得执行次数
     * @access public
     * @return integer
     */
    public function getExecuteTimes()
    {
        return Db::$executeTimes;
    }

    /**
     * 关闭数据库
     * @access public
     */
    public function close()
    {
        $this->linkID = null;
    }

    /**
     * 获取数据表信息
     * @access public
     * @param string $fetch 获取信息类型 包括 fields type bind pk
     * @param string $tableName  数据表名 留空自动获取
     * @return mixed
     */
    public function getTableInfo($tableName = '', $fetch = '')
    {
        static $_info = [];
        if (!$tableName) {
            $tableName = $this->getTableName();
        }
        if (is_array($tableName)) {
            $tableName = key($tableName) ?: current($tableName);
        }
        if (strpos($tableName, ',')) {
            // 多表不获取字段信息
            return false;
        }
        $guid = md5($tableName);
        if (!isset($_info[$guid])) {
            $info = $this->getFields($tableName);
            // 字段大小写转换
            switch ($this->params[PDO::ATTR_CASE]) {
                case PDO::CASE_LOWER:
                    $info = array_change_key_case($info);
                    break;
                case PDO::CASE_UPPER:
                    $info = array_change_key_case($info, CASE_UPPER);
                    break;
                case PDO::CASE_NATURAL:
                default:
                    // 不做转换
            }

            $fields = array_keys($info);
            $bind   = $type   = [];
            foreach ($info as $key => $val) {
                // 记录字段类型
                $type[$key] = $val['type'];
                if (preg_match('/(int|double|float|decimal|real|numeric|serial)/is', $val['type'])) {
                    $bind[$key] = PDO::PARAM_INT;
                } elseif (preg_match('/bool/is', $val['type'])) {
                    $bind[$key] = PDO::PARAM_BOOL;
                } else {
                    $bind[$key] = PDO::PARAM_STR;
                }
                if (!empty($val['primary'])) {
                    $pk[] = $key;
                }
            }
            if (isset($pk)) {
                // 设置主键
                $pk = count($pk) > 1 ? $pk : $pk[0];
            } else {
                $pk = null;
            }
            $result       = ['fields' => $fields, 'type' => $type, 'bind' => $bind, 'pk' => $pk];
            $_info[$guid] = $result;
        }
        return $fetch ? $_info[$guid][$fetch] : $_info[$guid];
    }

    /**
     * 获取最近一次查询的sql语句
     * @access public
     * @return string
     */
    public function getLastSql()
    {
        return $this->queryStr;
    }

    /**
     * 获取最近插入的ID
     * @access public
     * @return string
     */
    public function getLastInsID()
    {
        return $this->lastInsID;
    }

    /**
     * 获取最近的错误信息
     * @access public
     * @return string
     */
    public function getError()
    {
        if ($this->PDOStatement) {
            $error = $this->PDOStatement->errorInfo();
            $error = $error[1] . ':' . $error[2];
        } else {
            $error = '';
        }
        if ('' != $this->queryStr) {
            $error .= "\n [ SQL语句 ] : " . $this->queryStr;
        }
        return $error;
    }

    /**
     * SQL指令安全过滤
     * @access public
     * @param string $str  SQL字符串
     * @return string
     */
    public function quote($str)
    {
        $this->initConnect();
        return $this->linkID ? $this->linkID->quote($str) : $str;
    }

    /**
     * 数据库调试 记录当前SQL
     * @access protected
     * @param boolean $start  调试开始标记 true 开始 false 结束
     */
    protected function debug($start)
    {
        if (!empty($this->config['debug'])) {
            // 开启数据库调试模式
            if ($start) {
                Debug::remark('queryStartTime', 'time');
            } else {
                // 记录操作结束时间
                Debug::remark('queryEndTime', 'time');
                $runtime = Debug::getRangeTime('queryStartTime', 'queryEndTime');
                $log     = $this->queryStr . ' [ RunTime:' . $runtime . 's ]';
                $result  = [];
                // SQL性能分析
                if (0 === stripos(trim($this->queryStr), 'select')) {
                    $result = $this->getExplain($this->queryStr);
                }
                // SQL监听
                $this->trigger($this->queryStr, $runtime, $result);
            }
        }
    }

    /**
     * 监听SQL执行
     * @access public
     * @param callable $callback 回调方法
     * @return void
     */
    public function listen($callback)
    {
        self::$event[] = $callback;
    }

    /**
     * 触发SQL事件
     * @access protected
     * @param string $sql SQL语句
     * @param float $runtime SQL运行时间
     * @param mixed $explain SQL分析
     * @return bool
     */
    protected function trigger($sql, $runtime, $explain = [])
    {
        if (!empty(self::$event)) {
            foreach (self::$event as $callback) {
                if (is_callable($callback)) {
                    call_user_func_array($callback, [$sql, $runtime, $explain]);
                }
            }
        } else {
            // 未注册监听则记录到日志中
            Log::record('[ SQL ] ' . $this->queryStr . ' [ RunTime:' . $runtime . 's ]', 'sql');
            if (!empty($explain)) {
                Log::record('[ EXPLAIN : ' . var_export($explain, true) . ' ]', 'sql');
            }
        }
    }

    /**
     * 初始化数据库连接
     * @access protected
     * @param boolean $master 主服务器
     * @return void
     */
    protected function initConnect($master = true)
    {
        if (!empty($this->config['deploy'])) {
            // 采用分布式数据库
            $this->linkID = $this->multiConnect($master);
        } elseif (!$this->linkID) {
            // 默认单数据库
            $this->linkID = $this->connect();
        }
    }

    /**
     * 连接分布式服务器
     * @access protected
     * @param boolean $master 主服务器
     * @return void
     */
    protected function multiConnect($master = false)
    {
        // 分布式数据库配置解析
        $_config['username'] = explode(',', $this->config['username']);
        $_config['password'] = explode(',', $this->config['password']);
        $_config['hostname'] = explode(',', $this->config['hostname']);
        $_config['hostport'] = explode(',', $this->config['hostport']);
        $_config['database'] = explode(',', $this->config['database']);
        $_config['dsn']      = explode(',', $this->config['dsn']);
        $_config['charset']  = explode(',', $this->config['charset']);

        $m = floor(mt_rand(0, $this->config['master_num'] - 1));
        // 数据库读写是否分离
        if ($this->config['rw_separate']) {
            // 主从式采用读写分离
            if ($master)
            // 主服务器写入
            {
                $r = $m;
            } else {
                if (is_numeric($this->config['slave_no'])) {
                    // 指定服务器读
                    $r = $this->config['slave_no'];
                } else {
                    // 读操作连接从服务器
                    $r = floor(mt_rand($this->config['master_num'], count($_config['hostname']) - 1)); // 每次随机连接的数据库
                }
            }
        } else {
            // 读写操作不区分服务器
            $r = floor(mt_rand(0, count($_config['hostname']) - 1)); // 每次随机连接的数据库
        }

        if ($m != $r) {
            $db_master = [
                'username' => isset($_config['username'][$m]) ? $_config['username'][$m] : $_config['username'][0],
                'password' => isset($_config['password'][$m]) ? $_config['password'][$m] : $_config['password'][0],
                'hostname' => isset($_config['hostname'][$m]) ? $_config['hostname'][$m] : $_config['hostname'][0],
                'hostport' => isset($_config['hostport'][$m]) ? $_config['hostport'][$m] : $_config['hostport'][0],
                'database' => isset($_config['database'][$m]) ? $_config['database'][$m] : $_config['database'][0],
                'dsn'      => isset($_config['dsn'][$m]) ? $_config['dsn'][$m] : $_config['dsn'][0],
                'charset'  => isset($_config['charset'][$m]) ? $_config['charset'][$m] : $_config['charset'][0],
            ];
        }
        $db_config = [
            'username' => isset($_config['username'][$r]) ? $_config['username'][$r] : $_config['username'][0],
            'password' => isset($_config['password'][$r]) ? $_config['password'][$r] : $_config['password'][0],
            'hostname' => isset($_config['hostname'][$r]) ? $_config['hostname'][$r] : $_config['hostname'][0],
            'hostport' => isset($_config['hostport'][$r]) ? $_config['hostport'][$r] : $_config['hostport'][0],
            'database' => isset($_config['database'][$r]) ? $_config['database'][$r] : $_config['database'][0],
            'dsn'      => isset($_config['dsn'][$r]) ? $_config['dsn'][$r] : $_config['dsn'][0],
            'charset'  => isset($_config['charset'][$r]) ? $_config['charset'][$r] : $_config['charset'][0],
        ];
        return $this->connect($db_config, $r, $r == $m ? false : $db_master);
    }

    /**
     * 析构方法
     * @access public
     */
    public function __destruct()
    {
        // 释放查询
        if ($this->PDOStatement) {
            $this->free();
        }
        // 关闭连接
        $this->close();
    }
}
