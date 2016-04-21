<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */


set_time_limit(0);

function _parse_cli_argv() {
    if (count($GLOBALS['argv']) > 2) {
        $arr['query'] = $GLOBALS['argv'];
        unset($arr['query'][0]);
    } else {
        $arr['query'] = @$GLOBALS['argv'][1];
    }
    if (is_array($arr['query'])) {

        foreach ($arr['query'] as $k => $v) {
            if (strpos($v, '=')) {
                $tmp_arr = explode('=', $v);
                $arg[$tmp_arr[0]] = $tmp_arr[1];
            } else {
                $arg[$v] = '';
            }
        }
    } elseif ($arr['query']) {
        if (strpos($arr['query'], '&')) {
            $query = explode('&', html_entity_decode($arr['query']));
        } else {
            $query[0] = $arr['query'];
        }
        foreach ($query as $k => $v) {
            if (strpos($v, '=')) {
                $tmp_arr = explode('=', $v);
                $arg[$tmp_arr[0]] = $tmp_arr[1];
            } else {
                $arg[$v] = '';
            }
        }
    }
    $_GET = @$arg;
}

define('PATH_ROOT', dirname(__FILE__));
_parse_cli_argv();

class very_simple_swoole_spider {

    CONST TYPE_PAGE_LIST = 1;
    CONST TYPE_PAGE_CONTENT = 2;
    CONST TYPE_NEXT_PAGE_GET = 1;
    CONST TYPE_NEXT_PAGE_POST = 2;

    public $config = array();
    public $worker_pid = array();
    public $cookie = array();
    public $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:45.0) Gecko/20100101 Firefox/45.0';

    public function __construct() {
        if (!strlen($_GET['conf'])) {
            die("need arg: conf\n demo:\n php spider.php conf=list_demo");
        }
        $conf_file = PATH_ROOT . 'conf/' . $_GET['conf'] . '.php';
        if (!file_exists($conf_file)) {
            echo "can't find config file: " . $conf_file;
            die();
        }
        $this->config = include $conf_file;
        if (is_array($this->config['cookie_file'])) {
            foreach ($this->config['cookie_file'] as $k => $v) {
                if (!is_readable($this->config['cookie_file'][$k])) {
                    echo "can't read cookie file:" . $this->config['cookie_file'][$k] . "\n";
                    die();
                }
            }
        }
        if ($_GET['cookie_id'] < 1) {
            $_GET['cookie_id'] = rand(0, count($this->config['cookie_file']) - 1);
        }
        if (isset($this->config['cookie_file'][$_GET['cookie_id']])) {
            $this->config['cookie'] = file_get_contents($this->config['cookie_file'][$k]);
            $this->config['cookie_id'] = $_GET['cookie_id'];
        } else {
            die("cookie id not exists:" . $_GET['cookie_id']."\n");
        }
    }

    public function run() {
        if ($this->config['work_num'] > 1) {
            for ($i = 0; $i < $worker_num; $i++) {
                $process = new swoole_process(array('this', 'get_html'), true);
                $pid = $process->start();
                $this->worker_pid[$pid] = $process;
                echo "create worker:[" . $pid . "]\n";
            }
        }
    }

    public function get_html() {
        $url = $this->config['url'][1];
        $content = '';
    }

    public function do_get($url, $data, $pid = 0) {
        //初始化
        $ch = curl_init();
        if (is_array($this->config['cookie_file']) && is_file($this->config['cookie_file'][0])) {
            
        }

        //设置选项，包括URL
        curl_setopt($ch, CURLOPT_URL, "http://www.nettuts.com");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($ch, CURLOPT_COOKIEFILE, PATH_ROOT . '/data/cookie/cookie.txt');
        //执行并获取HTML文档内容
        $output = curl_exec($ch);

        //释放curl句柄
        curl_close($ch);
    }

    public function do_post($url, $data) {
        
    }

}

$spider = new very_simple_swoole_spider();

$spider->run();
