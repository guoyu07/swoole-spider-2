<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of db
 *
 * @author T
 */
class db {

    const CACHE_QUERY_DISABLE = '_not_cache_for_this_query_';
    const CACHE_QUERY_ENABLE = '_cache_this_query';

    /**
     * 正在使用的数据库链接名字
     * @var string
     */
    public $current_db_id = 'default';

    /**
     * 正在连接的是主库还是从库
     * @var string 
     */
    public $current_db_cluster = 'master';

    /**
     * 正在使用的数据库链接的句柄
     * @var resource
     * @return PDO
     */
    public $current_db_handle = null;

    /**
     * 刚执行过的数据库查询的句柄
     * @var resource
     */
    public $current_query_handle = null;

    /**
     * 当前连接数据库的表前缀
     * @var string
     */
    public $current_db_prefix = 'fu_';

    /**
     * 所有连结过的数据库句柄
     * @var array 
     */
    public $db_handle = array();

    /**
     * 数据库配置信息
     * @var type 
     */
    protected static $config = array();
    private $where = '';
    public $table = array();
    public $key = array();

    /**
     * 记录调用count 之后, 取得的当前查询的select count(*) 之后的数字
     * @var type 
     */
    public $count = false;
    public $count_key = '*';

    /**
     * 连接持续的时间,防止连接超时
     * @var boolean 
     */
    public $connect_time = false;

    /**
     * 是否缓存sql执行结果, 只有查询操作才会被缓存
     * @var boolean 
     */
    public $is_cache = false;

    /**
     * 缓存失效时间
     * @var boolean 
     */
    public $cache_time = 3600; //默认一小時
    public $current_cache_time = 3600;

    /**
     * 缓存文件名/唯一id名
     * @var string
     */
    public $cache_name = '';

    public function __toString() {
        return str_replace('@#', self::$config['pre'], $this->sql);
    }

    /**
     * 强制连接指定的数据库
     * @param type $db_id = db_config_name
     * @throws Exception
     */
    public function connect($db_id = null, $is_master = TRUE) {
        if (is_null($db_id)) {
            $db_id = $this->current_db_id = 'default';
        }
        if ($is_master == 'master' || $is_master == 'slave') {
            $this->current_db_cluster = $db_cluster = $is_master;
        } elseif ($is_master) {
            $this->current_db_cluster = $db_cluster = 'master';
        } else {
            $this->current_db_cluster = $db_cluster = 'slave';
        }
        $tmp_config = fu::config('system.db.' . $db_id . '.' . $db_cluster);
        if (count($tmp_config) > 1) {
            self::$config = $tmp_config[rand(0, count($tmp_config) - 1)];
        } else {
            self::$config = current($tmp_config);
        }
        if (is_array(self::$config)) {
            self::$config['port'] = intval(self::$config['port']) ? intval(self::$config['port']) : 3306;

            try {
                $this->db_handle[$db_id][$db_cluster] = $this->current_db_handle = new PDO('mysql:dbname=' . self::$config['db'] . ';host=' . self::$config['host'], self::$config['user'], self::$config['password']);
            } catch (PDOException $e) {
                debug::error('203');

                throw new Exception('[e203]connect to database' . $query_type . ' ' . $db_id . ':' . $e->getMessage());
            }
            if (isset(self::$config['charset'])) {
                $this->current_db_handle->exec("SET NAMES " . self::$config['charset']);
            }
            debug::log($this->current_db_id . ':' . $is_master, 'Connect db');
            $this->connect_time = time();
            return $this->current_db_handle;
        } else {

            debug::error('204');
            throw new Exception('[e204]do not have this database ' . $query_type . ' ' . $db_id);
        }
    }

    public function close($db_id = null) {
        if (isset($this->db_handle[$db_id]['master'])) {
            $this->db_handle[$db_id]['master'] = null;
        }
        if (isset($this->db_handle[$db_id]['slave'])) {
            $this->db_handle[$db_id]['slave'] = null;
        }
    }

    public function fetch_one($query = NULL, $call_back = NULL) {
        if (!is_null($call_back) and ! strpos($call_back, ';')) {
            $call_back = $call_back . ';';
        }
        $cache_name = '';
        if ($this->is_cache) {
            if ($this->cache_name == '') {
                $cache_name = 'cache/db_fetch_one/' . $this->current_db_id . '/' . str_replace('@#', self::$config['pre'], implode('_', $this->table)) . md5(serialize(func_get_args()) . $this->sql);
            } else {
                $cache_name = $this->cache_name;
            }
            debug::info($cache_name . '--' . $this->sql, 'db_cache');
            $ret = cache::o()->get($cache_name, $this->current_cache_time);
            if ($ret['query'] == $this->sql) {
                if (!empty($ret['data'])) {
                    return $ret['data'];
                } else {
                    debug::info('cache_is_empty,start check db', 'db_cache');
                }
            }
        }

        if (empty($query)) {
            eval($call_back);
            $query = $this->sql;
        }

        if (!strpos($query, 'LIMIT')) {
            $query = $query . ' LIMIT 1';
        }

        $result = $this->query($query);

        if (!$result) {
            debug::warn($query, 'fetch one query failed');
            return FALSE;
        }

        $content = $result->fetch();
        if (isset($content['name'])) {
            $safe = htmlspecialchars($content['name'], ENT_QUOTES);
            if (empty($content['name_safe'])) {
                $content['name_safe'] = $safe;
            }
            if (empty($content['name_short'])) {
                $content['name_short'] = $safe;
            }
        }
        if ($this->is_cache && !empty($content)) {
            cache::o()->put($cache_name, array('query' => $this->sql, 'data' => $content));
        }
        return $content;
    }

    public function fetch_all($primary_key = null, $call_back = null, $name_length = 0) {
        $cache_name = '';
        if ($this->is_cache) {
            if ($this->cache_name == '') {
                $cache_name = 'cache/db_fetch_all/' . $this->current_db_id . '/' . str_replace('@#', self::$config['pre'], implode('_', $this->table)) . md5(serialize(func_get_args()) . $this->sql);
            } else {
                $cache_name = $this->cache_name;
            }
            debug::log($cache_name . '---' . $this->sql, 'db_cache');
            $ret = cache::o()->get($cache_name, $this->current_cache_time);
            if ($ret['query'] == $this->sql) {
                if (!empty($ret['data']) && $ret['query'] == $this->sql) {
                    return $ret['data'];
                } else {
                    debug::info('cache_is_empty_or_query_is_changed,start check db', 'db_cache');
                }
            }
        }
        $result = $this->query();
        if (!$result) {
            return $result;
        }
        if (!is_null($call_back) and ! strpos($call_back, ';')) {
            $call_back = $call_back . ';';
        }
        $return = array();
        $list = $result->fetchAll();
        if ($list) {
            foreach ($list as $k => $row) {
                if (!empty($row['name'])) {
                    $safe = htmlspecialchars($row['name'], ENT_QUOTES);

                    if (empty($row['name_safe'])) {
                        $row['name_safe'] = $safe;
                    }
                    if (empty($row['name_short'])) {
                        $row['name_short'] = $safe;
                    }
                    if ($name_length > 0) {
                        $row['name_short'] = kit::substr(strip_tags($row['name']), 0, $name_length);
                    }
                    unset($safe);
                }
                eval($call_back);
                if (isset($row[$primary_key])) {
                    $return[$row[$primary_key]] = $row;
                } else {
                    $return[] = $row;
                }
            }
            if ($this->is_cache && !empty($content)) {
                cache::o()->put($cache_name, array('query' => $this->sql, 'data' => $return));
            }
            if ($this->count === TRUE) {
                $tmp_ret = $this->query($this->sql_count);
                $this->count = $this->result(0);
            }
            return $return;
        } else {
            if ($this->count === TRUE) {
                $tmp_ret = $this->query($this->sql_count);
                $this->count = 0;
            }
            return $list;
        }
    }

    /**
     * 设定取得总数, 并将总数返回到 \db::o()->count 上
     * 执行效果为  select count($count_key)
     * @param type $count_key  
     */
    public function count($count_key = '*') {
        $this->count_key = $count_key;
        $this->count = TRUE;
        return $this;
    }

    /**
     * 
     * @param type $query  支持直接输入sql, 但是直接输入sql的话会很危险,没有安全校验的步骤了
     * @param type $get_unbuffered
     * @return boolean
     */
    public function query($query = null, $get_unbuffered = false) {
        if (is_null($query)) {
            $query = $this->sql;
        }else {
            $this->sql = $query;
        }

        $is_master = (strpos(' ' . strtolower(trim($this->sql)), 'select') == 1) ? 'slave' : 'master';
        $this->current_db_handle = @$this->db_handle[$this->current_db_id][$is_master];

        //连接时间超过20秒,每次执行sql都要ping一下
        //ps:超过20秒,都是服务器脚本在执行,所以ping一下,速度慢点无所谓
        if (time() - $this->connect_time > 20 and $this->connect_time > 100) {
            $is_alive = $this->ping();
            if (!$is_alive) {
                $this->current_db_handle = null;
            }
        }

        if (!is_a($this->current_db_handle, 'PDO')) {
            \debug::info('query auto connect ' . $is_master . ' query:' . $this->sql, 'db');
            $this->connect($this->current_db_id, $is_master);
        }

        if (!is_a(@$this->db_handle[$this->current_db_id][$is_master], 'PDO')) {
            var_dump($this->db_handle[$this->current_db_id]);
            \debug::info('query auto reconnect ' . $is_master . ' query:' . $this->sql, 'db');
            $this->connect($this->current_db_id, $is_master);
        }
//        if ($query == $this->sql && !empty($this->table) && !empty($this->key)) {
//            if (!$this->validate($this->table, $this->key)) {
//                debug::warn('validate query fail:' . str_replace('@#', self::$config['pre'], $query), 'db');
//                return false;
//            }
//        }

        if (isset($this->sql_limit) && $this->sql_limit != '' && !strpos(strtolower($this->sql), ' limit ')) {
            $this->sql = $query = $this->sql . $this->sql_limit;
        }
        $this->table = $this->order = $this->limit = $this->where = $this->key = null;
        $this->sql = $query = str_replace('@#', self::$config['pre'], $query);
        if ($this->count === TRUE) {
            $this->sql_count = str_replace($this->sql_select, 'SELECT COUNT(' . $this->count_key . ') FROM ', $this->sql);
            $this->sql_count = str_replace($this->sql_limit, ' ', $this->sql_count);
            $this->sql_select = $this->sql_limit = '';
            $this->count_key = '*';
        }
        $this->current_db_handle->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $ret = $this->current_query_handle = $this->current_db_handle->query($query, PDO::FETCH_ASSOC);

        if (!$this->current_query_handle and RUN_MODE == 'debug') {
            debug::error('[handle lost][query:' . $query . ']   ' . var_export($this->error(), 1), 'sql_query_error');
        } else {
            debug::info($query . ' ', 'sql_query');
        }
        return $this->current_query_handle;
    }

    public function quote($string) {
        if (is_null($string)) {
            return '';
        }

        $is_master = (strpos(' ' . strtolower(trim($this->sql)), 'select') == 1) ? 'slave' : 'master';

        $this->current_db_handle = @$this->db_handle[$this->current_db_id][$is_master];
        //连接时间超过20秒,每次执行sql都要ping一下
        //ps:超过20秒,都是服务器脚本在执行,所以ping一下,速度慢点无所谓
        if (time() - $this->connect_time > 20 and $this->connect_time > 100) {
            $is_alive = $this->ping();
            if (!$is_alive) {
                $this->current_db_handle = null;
            }
        }

        if (!is_a(@$this->current_db_handle, 'PDO')) {
            \debug::info('quote auto connect ' . $is_master . ' string:' . $string, 'db');
            $this->current_db_handle = $this->connect($this->current_db_id, $is_master);
        }

        if (!is_a(@$this->db_handle[$this->current_db_id][$is_master], 'PDO')) {
            \debug::info('quote auto reconnect ' . $is_master . ' string:' . $string, 'db');
            $this->current_db_handle = $this->connect($this->current_db_id, $is_master);
        }

        return $this->current_db_handle->quote($string);
    }

    public function select($table, $col = array()) {
//        $this->table = $this->order = $this->limit = $this->where = $this->key = null;
        $this->sql = ' SELECT ';
        if (is_array($col) and ! empty($col)) {
            $comma = '';
            if (is_array($table)) {
                foreach ($col as $k => $v) {
                    $this->key[$v] = $v;
                    if (strpos($v, '.')) {
                        if (!strpos($v, '`')) {
                            $v = str_replace('.', '.`', $v) . '`';
                        }
                        $this->sql .= $comma . ' ' . $v . ' ';
                    } else {
                        $this->sql .= $comma . '`' . $v . '`';
                    }

                    $comma = ',';
                }
            } else {
                foreach ($col as $k => $v) {
                    $this->key[$v] = $v;
                    $this->sql .= $comma . '`' . $v . '`';
                    $comma = ',';
                }
            }
        } else {
            if (empty($col)) {
                $col = ' * ';
                $this->key = array();
            }
            $this->sql .= $col;
        }
        $this->sql .= ' FROM ';
        $this->sql_select = $this->sql;
        $table_query = '';
        if (is_array($table)) {
            $comma = '';
            foreach ($table as $k => $v) {
                $this->table[] = $v;
                $table_query .=$comma . '`' . $v . '` ';
                $comma = ',';
            }
        } else {
            $this->table[] = $table;
            $table_query .= '`' . $table . '` ';
        }
        $this->sql .= $table_query;
        return $this;
    }

    /**
     *
     * @param type $table
     * @param type $col_value
     * @param type $type_tag  ignore |  ON DUPLICATE KEY UPDATE | delay
     * @return db 
     */
    public function insert($table, $col_value = array(), $type_tag = '') {
        $this->table = $this->order = $this->limit = $this->key = $this->where = null;

        $this->sql = ' INSERT ' . $type_tag . ' INTO ' . '`' . $table . '`';

        $this->table[] = $table;
        $insert_query_0 = $insert_query_1 = $insert_query_2 = '';
        if (is_array($col_value) and ! empty($col_value)) {
            $comma = '';
            foreach ($col_value as $k => $v) {
                if (is_numeric($v)) {
                    if (intval($v) == 0) {
                        $v = 0;
                    }
                } elseif (empty($v)) {
                    $v = "''";
                } else {
                    $v = $this->quote($v);
                }
                $this->key[$k] = $v;
                $insert_query_1 .= $comma . '`' . $k . '`';
                $insert_query_2 .= $comma . "" . $v . "";
                $comma = ',';
            }
        } else {
            $insert_query_0 = $col_value;
        }

        if ($insert_query_0) {
            $this->sql .= $insert_query_0;
        } else {
            $this->sql .= '(' . $insert_query_1 . ') VALUES (' . $insert_query_2 . ') ';
        }
        return $this;
    }

    public function replace($table, $col_value = array()) {
        $this->table = $this->order = $this->limit = $this->key = $this->where = null;
        $this->sql = 'REPLACE INTO  ' . $table;
        $this->table[] = $table;
        $insert_query_0 = $insert_query_1 = $insert_query_2 = $comma = '';
        if (is_array($col_value) and ! empty($col_value)) {
            $comma = '';
            foreach ($col_value as $k => $v) {
                $this->key[$k] = $v;
                if (is_numeric($v)) {
                    if (intval($v) == 0) {
                        $v = 0;
                    }
                } elseif (empty($v)) {
                    $v = "''";
                } else {
                    $v = $this->quote($v);
                }
                $insert_query_1 .= $comma . '`' . $k . '`';
                $insert_query_2 .= $comma . "" . $v . "";
                $comma = ',';
            }
        } else {
            $insert_query_0 = $col_value;
        }

        if ($insert_query_0) {
            $this->sql .= $insert_query_0;
        } else {
            $this->sql .= '(' . $insert_query_1 . ') VALUES (' . $insert_query_2 . ') ';
        }

        return $this;
    }

    /**
     * @example $col_value = array('a'=>123,'b'=>array('b+1',1));    'b'=>array('b+1',1)  最后一个参数，表示是否禁止加单引号，默认为0
     * 
     * @param string $table
     * @param array $col_value
     * @param <array> $where
     * @return mysql 
     */
    public function update($table, $col_value = array(), $where = array()) {
        $this->table = $this->order = $this->limit = $this->key = $this->where = null;
        $this->sql = 'UPDATE ' . $table . ' SET ';
        $this->table[] = $table;
        if (is_array($col_value) and ! empty($col_value)) {
            $comma = '';
            foreach ($col_value as $k => $v) {
                $this->key[$k] = $v;

                if (preg_match("/^\d*$/", $v) && !empty($v)) {
                    
                } elseif (empty($v)) {
                    $v = "''";
                } elseif (is_array($v)) {
                    if (!$v[1]) {
                        $tmp_v = $v[0];
                        unset($v);
                        $v = $this->quote($tmp_v);
                    } else {
                        $tmp_v = $v[0];
                        unset($v);
                        $v = $tmp_v;
                    }
                } elseif (is_string($v)) {
                    $v = trim($v);
                    if ((strpos(' ' . $v, '`') and ( strpos($v, '+') or strpos($v, '-') or strpos($v, '*') or strpos($v, '/')))
                            or strpos(' ' . $v, '`') == 1 and strrpos($v, '`') == (strlen($v) - 1)) {
                        $v = " " . $this->quote($v) . " ";
                    } else {
                        $v = $this->quote($v);
                    }
                }
                $this->sql .= $comma . '`' . $k . '` =' . $v;
                $comma = ',';
            }
        } else {
            $this->sql .= $col_value;
        }
        if (!empty($where)) {
            $this->where($where);
        }
        return $this;
    }

    public function delete($table, $where = null) {
        $this->table = $this->order = $this->limit = $this->key = $this->where = null;
        $this->table[] = $table;
        $this->sql = 'DELETE FROM `' . $table . '`';
        if (!empty($where)) {
            $this->where($where);
        }
        return $this;
    }

    public function where($where_array_or_key, $value = '', $relation = NULL) {

        if (is_array($where_array_or_key) && (count($where_array_or_key) == 2 || count($where_array_or_key) == 3) && $relation === NULL) {
            $where_0 = current($where_array_or_key);
            $where_1 = next($where_array_or_key);
            if (FALSE !== $where_1) {
                $where_2 = next($where_array_or_key);
            } else {
                $where_2 = FALSE;
            }
        }
        if (is_null($value)) {
            $value = '';
        }
        if (is_string($where_array_or_key)) {

            if (is_array($this->where) && count($this->where) > 0) {
                if ($relation === NULL) {
                    $relation = 'and';
                }
                $patch = ' ' . $relation . ' ';
            } else {
                $patch = ' WHERE ';
                $this->where[] = func_get_args();
            }
            $trim_where_string = trim($where_array_or_key);
            $trim_where_string_lower = strtolower($trim_where_string);
            $trim_where_string_length = strlen($trim_where_string);


            if (($trim_where_string_length > 3 && (strpos($trim_where_string_lower, ' in') == ($trim_where_string_length - 3)))
                    or ( $trim_where_string_length > 6 && strpos($trim_where_string_lower, ' not in') == ($trim_where_string_length - 6))) {


                if (is_array($value)) {
                    $comma = ' ';
                    $v = '(';
                    foreach ($value as $k1 => $v1) {
                        if ((!is_numeric($v1) or empty($v1))
                                and ! (strpos(' ' . trim($v1), "'") == 1 and strrpos(trim($v1), "'") == strlen(trim($v1)))) {
                            $v .= $comma . $this->quote($v1);
                        } else {
                            $v .= $comma . $v1;
                        }
                        $comma = ' , ';
                    }
                    $v .= ')';
                    $value = $v;
                } else {
                    $value = "(" . $this->quote($value) . ")";
                }

                $this->key[substr($trim_where_string, 0, strpos($trim_where_string, ' '))] = $value;
                $this->sql .= $patch . ' ' . $where_array_or_key . ' ' . $value;
            } elseif (
                    (strrpos($trim_where_string, '=') == $trim_where_string_length - 1 && strrpos($trim_where_string, '=') > 0) ||
                    (strrpos($trim_where_string, '>') == $trim_where_string_length - 1 && strrpos($trim_where_string, '>') > 0) ||
                    (strrpos($trim_where_string, '<') == $trim_where_string_length - 1 && strrpos($trim_where_string, '<') > 0) ||
                    (strrpos($trim_where_string_lower, ' like') == $trim_where_string_length - 5 && strrpos($trim_where_string_lower, ' like') > 0)) {
                //有运算符 
                if ((!is_numeric($value) or empty($value))
                        and ! (strpos(' ' . trim($value), "'") == 1 and strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //单引号没有或者不完整的空数据或者非数字加上单引号

                    $value = $this->quote($value);
                } elseif ((strpos(' ' . trim($value), "'") == 1 and strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //value本身有完整的单引号去掉单引号quote一下
                    $value = $this->quote(substr(trim($value), 1, strlen($value) - 1));
                } else {
                    //这时应该只有数字, 无需处理
                }
                $this->key[substr($trim_where_string, 0, strpos($trim_where_string, ' '))] = $value;
                $this->sql .= $patch . ' ' . $where_array_or_key . ' ' . $value;
            } elseif (!(strpos(' ' . $trim_where_string, "`") == 1 and strrpos($trim_where_string, "`") == $trim_where_string) and ! strpos($trim_where_string, '.')) {

                //key 没有反引号,或者非法查询 强制加上
                if ((!is_numeric($value) or empty($value)) &&
                        !( strlen($value) > 2 && strpos(' ' . trim($value), "'") == 1 && strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //单引号没有或者不完整的空数据或者非数字加上单引号

                    $value = $this->quote($value);
                } elseif ((strpos(' ' . trim($value), "'") == 1 and strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //value本身有完整的单引号去掉单引号quote一下
                    $value = $this->quote(substr(trim($value), 1, strlen($value) - 1));
                } else {
                    //这时应该只有数字, 无需处理
                }
                $this->key[$where_array_or_key] = $value;
                $this->sql .= $patch . " `$where_array_or_key` = " . $value;
            } elseif (strpos(' ' . $trim_where_string, "`") == 1 and strrpos($trim_where_string, "`") == $trim_where_string) {

                //有反引号, 无需处理
                if ((!is_numeric($value) or empty($value))
                        and ! (strpos(' ' . trim($value), "'") == 1 and strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //单引号没有或者不完整的空数据或者非数字加上单引号
                    $value = $this->quote($value);
                } elseif ((strpos(' ' . trim($value), "'") == 1 and strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //value本身有完整的单引号去掉单引号quote一下
                    $value = $this->quote(substr(trim($value), 1, strlen($value) - 1));
                } else {
                    //这时应该只有数字, 无需处理
                }
                $this->key[substr($trim_where_string, 1, $trim_where_string_length - 2)] = $value;
                $this->sql .= $patch . " $where_array_or_key = " . $value;
            } elseif (strpos($where_array_or_key, '.')) {

                $tmp_arr = array();
                $tmp_arr = explode('.', $where_array_or_key);

                $comma = '.';
                $tmp_key = '';
                $v = ' ';
                foreach ($tmp_arr as $k1 => $v1) {
                    if (!(strpos(' ' . trim($v1), "`") == 1 and strrpos(trim($v1), "`") == (strlen(trim($v1)) - 1 ) )) {

                        $v .= $comma . "`" . $v1 . "`";
                        $tmp_key .= $comma . $v1;
                    } elseif (!empty($v1)) {
                        $v .= $comma . $v1;
                        $tmp_key = $comma . substr(trim($v1), 1, strlen(trim($v1)) - 2);
                    }
                }
                $where_array_or_key = $v;

                if ((!is_numeric($value) or empty($value))
                        and ! (strpos(' ' . trim($value), "'") == 1 and strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //单引号没有或者不完整的空数据或者非数字加上单引号
                    $value = $this->quote($value);
                } elseif ((strpos(' ' . trim($value), "'") == 1 and strrpos(trim($value), "'") == strlen(trim($value)))) {
                    //value本身有完整的单引号去掉单引号quote一下
                    $value = $this->quote(substr(trim($value), 1, strlen($value) - 1));
                } else {
                    //这时应该只有数字, 无需处理
                }


                $this->key[$tmp_key] = $value;
                $this->sql .= $patch . " $where_array_or_key = " . $value;
            } else {

                var_dump("db::where: 为string时未考虑到的其他情况, 需要增加判断");
                var_dump(func_get_args());
                die();
            }
        } elseif (is_array($where_array_or_key) && count($where_array_or_key) == 2 && isset($where_0) && is_string($where_0) && $where_2 === FALSE) {
            //对应 where(array(k,v))的情况
            //可以等价为  where(k,v);
            if ($relation === NULL) {
                $relation = 'and';
            }
            $this->where($where_0, $where_1, $relation);
        } elseif (is_array($where_array_or_key) && count($where_array_or_key) == 3 && isset($where_0) && is_string($where_0) && $where_1 !== FALSE) {
            //对应 where(array(k,v,r))的情况
            //可以等价为  where(k,v,r);

            $this->where($where_0, $where_1, $where_2);
        } elseif (is_array($where_array_or_key)) {
            //对应嵌套类的传递,
            //对应 where(array(k=>v)) 或者  where(array(k=>v,k1=>v1,k2=>v2))
            //对应  where(array(k,v,r),array(k,v,r))
            foreach ($where_array_or_key as $k => $v) {
                if (!isset($where_array_or_key[0]) && !is_array($v)) {

                    //对应 where(array(k=>v,k=>v))的情况
                    //可以等价为  where(where(k,v))->where(where(k,v));

                    $this->where($k, $v);
                } else {
                    //因为key 不可能为0, 所以这里对应 数组key的情况

                    $this->where($v);
                }
            }
        } else {
            var_dump($this->sql);
            var_dump("db::where: 为array时未考虑到的其他情况, 需要增加判断");
            var_dump(func_get_args());

            throw new Exception;

            die();
        }
        return $this;
    }

    public function join($table, $on, $left_right_inner = '') {
        $this->table[] = $table;
        $this->sql .= ' ' . $left_right_inner . ' join ' . $table . ' on ' . $on;
        return $this;
    }

    public function group($key) {
        $this->sql .= ' group by ' . $key . ' ';
        return $this;
    }

    public function order($order) {
        $this->sql .= ' ORDER BY ' . $order;
        return $this;
    }

    /**
     *
     * @param <int> $start_num
     * @param <int> $offset
     * @return db
     */
    public function limit($start_num = 0, $offset = 10) {
        $this->limit['start_num'] = intval($start_num);
        $this->limit['offset'] = intval($offset);
        if (strpos(strtolower(' ' . trim(@$this->sql)), 'delete') == 1 or strpos(strtolower(' ' . trim(@$this->sql)), 'update') == 1) {
            @$this->sql .= $this->sql_limit = ' LIMIT ' . $offset;
        } else {
            @$this->sql .= $this->sql_limit = ' LIMIT ' . $start_num . ',' . $offset;
        }

        return $this;
    }

    public function insert_id() {
        return $this->current_db_handle->lastInsertId();
    }

    public function row_count() {
        return $this->current_query_handle->rowCount();
    }

    public function column_count() {
        return $this->current_query_handle->columnCount();
    }

    /**
     * pdo已废弃
     * 请使用row_count代替 num_row,本方法弃用,随时会失效
     * @return type
     */
    public function num_row() {
        debug::warn('请使用row_count代替 num_row,本方法弃用,随时会失效', 'free_result');
        return $this->current_query_handle->rowCount();
    }

    /**
     * pdo已废弃
     * 请使用column_count代替 num_field,本方法弃用,随时会失效
     * @return type
     */
    public function num_field() {
        debug::warn('请使用column_count代替 num_field,本方法弃用,随时会失效', 'free_result');
        return $this->current_query_handle->columnCount();
    }

    /**
     * pdo已废弃
     * 请使用row_count代替 affected_row,本方法弃用,随时会失效
     * @return type
     */
    public function affected_row() {
        debug::warn('请使用row_count代替 affected_row,本方法弃用,随时会失效', 'free_result');
        return $this->current_query_handle->rowCount();
    }

    public function result($row) {
//        $re = $this->query();
        return $this->query()->fetchColumn($row);
    }

    public function begin_transaction() {
        //启用这个的都是重要的写操作, 强制连master
        $this->current_db_handle = @$this->db_handle[$this->current_db_id]['master'];
        //连接时间超过20秒,每次执行sql都要ping一下
        //ps:超过20秒,都是服务器脚本在执行,所以ping一下,速度慢点无所谓
        if (time() - $this->connect_time > 20 and $this->connect_time > 100) {
            $is_alive = $this->ping();
            if (!$is_alive) {
                $this->current_db_handle = null;
            }
        }

        if (!is_a($this->current_db_handle, 'PDO')) {
            \debug::info('transaction auto connect master db', 'db');
            $this->current_db_handle = $this->connect($this->current_db_id, 'master');
        }

        if (!is_a($this->db_handle[$this->current_db_id]['master'], 'PDO')) {
            \debug::info('transaction auto reconnect master db', 'db');
            $this->current_db_handle = $this->connect($this->current_db_id, 'master');
        }
        return $this->current_db_handle->beginTransaction();
    }

    public function roll_back() {
        \debug::info('transaction faild,roll back, error:' . var_export($this->error(), 1), 'db');

        return $this->current_db_handle->rollBack();
    }

    public function commit() {
        \debug::info('transaction successed', 'db');

        return $this->current_db_handle->commit();
    }

    /**
     * 是否给查询结果加缓存,只要在query之前加都可以生效
     * @param string $name
     * @param int $time
     * @return \db
     */
    public function cache($name = '', $time_or_cache_status = 3600) {
        if ($time_or_cache_status === self::CACHE_QUERY_ENABLE) {
            $this->is_cache = TRUE;
            $this->current_cache_time = $this->cache_time;
            $this->cache_name = $name;
            return $this;
        } elseif ($time_or_cache_status === self::CACHE_QUERY_DISABLE) {
            $this->is_cache = FALSE;
            return $this;
        }
        if ($time_or_cache_status > 0) {
            $this->current_cache_time = $time;
        } else {
            $this->current_cache_time = $time;
        }
        $this->cache_name = $name;
        return $this;
    }

    public function close_curser() {
        return $this->current_query_handle->closeCursor();
    }

    /**
     * 兼容老版代码/ 随时会删除
     * @return type
     */
    public function free_result() {
        debug::warn('请使用close_curser代替 free_result,本方法弃用,随时会失效', 'free_result');

        return $this->close_curser();
    }

    public function errno() {
        return $this->current_db_handle->errorCode();
    }

    public function error() {
        return $this->current_db_handle->errorInfo();
    }

    public function ping() {
        try {
            $this->current_db_handle->query('SELECT 1');
        } catch (PDOException $e) {
            return $this->connect();            // Don't catch exception here, so that re-connect fail will throw exception
        }

        $status = $this->current_db_handle->getAttribute(PDO::ATTR_SERVER_INFO);
        if ($status == 'MySQL server has gone away') {
            return FALSE;
        } else {
            return TRUE;
        }
    }

    /**
     * @return db
     */
    static public function o($class_name = null) {
        //  if (is_object(self::$current_db_handle) and ($class_name ==null or $class_name == self::$current_db_id)) {
        //    return self::$current_db;
        // }
        $p = fu::load(get_class(), 'db' . $class_name);

        if (is_string($class_name) and is_array(fu::config('system.db.' . $class_name))) {
            $p->current_db_id = $class_name;
        } else {
            $p->current_db_id = 'default';
        }
        $p->cache_time = fu::config('system.cache.interval');
        $p->table = $p->order = $p->limit = $p->where = $p->key = $p->sql_limit = null;
         
        return $p;
    }

}
