<?php

$conf['interval'] = '0.1'; // 两次抓取间隔时间， 单位为秒
$conf['interval_random'] = 1; // 两次抓取间隔， 是否加入随机， 随机大小为 $conf['interval']+ $conf['interval']* rand(0,$conf['interval_random']);  0的时候为不加入
$conf['work_num'] = '2'; // 几线程同时抓取， 建议不要太大， 否则小站容易被爬死

$conf['proxy'] = 'off'; // 是否使用代理， 没做

$conf['type'] = very_simple_swoole_spider::TYPE_PAGE_LIST;
$conf['url'][0] = 'http://www.dygang.com'; //网站首页
$conf['url'][1] = 'http://www.dygang.com/ys/index.htm'; //列表首页
$conf['url'][2] = 'http://www.dygang.com/ys/index_2.htm'; //列表第二页
$conf['url'][3] = 'http://www.dygang.com/ys/index_3.htm'; //列表第三页
$conf['url']['template'] = 'http://www.dygang.com/ys/index_{page}.htm'; //列表翻页模板
$conf['url']['next_page'] = '下一页'; //翻页/下一页的文字 
$conf['url']['next_page_type'] = very_simple_swoole_spider::TYPE_NEXT_PAGE_GET;  //两种模式  GET/ POST
$conf['part']['start'] = '迅雷电影列表'; // 有效链接开始关键字 /html （必须本页唯一）
$conf['part']['end'] = '本类影片下载排行'; // 有效链接结束关键字 / html（必须本页唯一）
$conf['url']['cookie_file'][] = ''; //如果需要登陆， 这是登陆后的浏览器内的cookie, 支持多个cookie马甲




return $conf;
