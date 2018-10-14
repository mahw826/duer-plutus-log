<?php

namespace Duer\Plutus;

use Phalcon\Logger\Adapter\File as FileLogger;
use Phalcon\Logger\Formatter\Line;

class Log {
    public $logid = '';
    public $arrParams = array();
    public $arrTimeTmp = array();
    public $arrTime = array();
    public $name = '';
    public $noticelog;
    public $warninglog;
    public $startTime;

    function __construct($logPath, $name) {
        $this->logid      = empty($_SERVER['HTTP_SAIYALOGID']) ? md5(json_encode($_REQUEST) . microtime()) : $_SERVER['HTTP_SAIYALOGID'];
        $this->name       = $name;
        $this->noticelog  = new FileLogger($logPath);
        $this->warninglog = new FileLogger($logPath . ".wf");
        $formatter        = new Line("%type% %date% %message%");
        $formatter->setDateFormat('Y-m-d H:i:s');
        $this->noticelog->setFormatter($formatter);
        $this->warninglog->setFormatter($formatter);

        $host    = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $url     = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $refer   = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $baiduId = isset($_COOKIE['BAIDUID']) ? $_COOKIE['BAIDUID'] : '';
        $ua      = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';


        if ($_SERVER['REQUEST_TIME_FLOAT'] > 0) {
            $this->startTime = $_SERVER['REQUEST_TIME_FLOAT'];
        } else {
            $this->startTime = microtime(true);
        }

        if (!empty($_SERVER['HTTP_X_BD_PRODUCT'])) {
            $product = $_SERVER['HTTP_X_BD_PRODUCT'];
        } else {
            $product = 'unknown';
        }

        $subsys = empty(APP_NAME) ? 'unknow' : APP_NAME;

        $ngxTime = $this->getNgxTime();
        $cgiWait = $ngxTime > 0 ? intval(1000 * ($this->startTime - $ngxTime)) : 0;

        $this->addField('logid', $this->logid);
        $this->addField('http_time', $ngxTime);
        $this->addField('cgi_time', $this->startTime);
        $this->addField('cgi_wait_ms', $cgiWait);
        $this->addField('user_ip', $this->getUserIp());
        $this->addField('from_ip', $_SERVER['REMOTE_ADDR']);
        $this->addField('local_ip', $this->getLocalIp());
        $this->addField('host', $host);
        $this->addField('http_method', $_SERVER['REQUEST_METHOD']);
        $this->addField('url', $url);
        $this->addField('refer', $refer);
        $this->addField('cookie', $_SERVER['HTTP_COOKIE']);
        $this->addField('baidu_id', $baiduId);
        $this->addField('ua', $ua);
        $this->addField('product', $product);
        $this->addField('subsys', $subsys);
        $this->addField('pv_lost', 0);
    }

    private function getNgxTime()
    {
        // 需要在nginx的php逻辑块fastcgi配置中，加个配置项
        // fastcgi_param  NGINX_REQ_TIME $msec;
        // 这个值用来计算请求在php队列中的排队时间
        if (isset($_SERVER['NGINX_REQ_TIME'])) {
            $ngxTime = $_SERVER['NGINX_REQ_TIME'];
        } else {
            $ngxTime = 0;
        }

        return $ngxTime;
    }

    public function boot(Event $event, Application $app) {
        $di = $app->getDI();

        if ($di) {
            $di->set($this->name, $this);
        }

        return true;
    }

    public function beforeSendResponse(Event $event, Application $app, Response $res) {
        foreach ($this->arrTime as $paramName => $arrParamTime) {
            $countParamTime = count($arrParamTime);
            if (1 == $countParamTime) {
                $this->addField($paramName, $arrParamTime[0]);
            } else {
                $totalTime = array_sum($arrParamTime);
                $this->addField($paramName . '_t', $totalTime);
                $this->addField($paramName . '_avg', $totalTime / $countParamTime);
                $this->addField($paramName . '_max', max($arrParamTime));
                $this->addField($paramName . '_min', min($arrParamTime));
            }
        }
        $cost = intval(1000 * (microtime(true) - $this->startTime));

        $this->addField('all_t', $cost);

        $this->flush();

        return true;
    }

    public function flush() {
        foreach ($this->arrTime as $paramName => $arrParamTime) {
            $countParamTime = count($arrParamTime);
            if (1 == $countParamTime) {
                $this->addField($paramName, $arrParamTime[0]);
            } else {
                $totalTime = array_sum($arrParamTime);
                $this->addField($paramName . '_t', $totalTime);
                $this->addField($paramName . '_avg', $totalTime / $countParamTime);
                $this->addField($paramName . '_max', max($arrParamTime));
                $this->addField($paramName . '_min', min($arrParamTime));
            }
        }
        $cost = intval(1000 * (microtime(true) - $this->startTime));

        $this->addField('all_t', $cost);


        $arrItem = array();
        foreach ($this->arrParams as $key => $value) {
            $arrItem[] = $key.':'.$value;
        }
        $content = implode(' ',$arrItem);
        var_dump($content);
        $this->noticelog->notice($content);
        if ($this->arrParams['pv_lost'] != 0) {
            $this->warninglog->warning($content);
        }
    }

    public function getLogId() {
        return $this->logid;
    }

    private function addField($paramName, $paramValue) {
        $this->arrParams[$paramName] = $paramValue;
    }

    public function addNotice($paramName, $paramValue) {
        $this->arrParams[$paramName] = $paramValue;
    }

    private function getUserIp() {
        $uip = '';

        if (isset($_SERVER['HTTP_X_BD_USERIP']) && $_SERVER['HTTP_X_BD_USERIP']
            && strcasecmp($_SERVER['HTTP_X_BD_USERIP'], 'unknown')) {
            $uip = $_SERVER['HTTP_X_BD_USERIP'];
        } else {

            $uip = $this->getClientIp();
        }

        return $uip;
    }

    private function getLocalIp() {
        $ret = '';
        if (isset($_SERVER['SERVER_ADDR'])) {
            $ret = $_SERVER['SERVER_ADDR'];
        }

        return $ret;
    }

    private function getClientIp()
    {
        $uip = '';

        if(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])
            && strcasecmp($_SERVER['HTTP_X_FORWARDED_FOR'], 'unknown')) {

            $uip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            strpos($uip, ',') && list($uip) = explode(',', $uip);
        } elseif(!empty($_SERVER['HTTP_CLIENT_IP'])
            && strcasecmp($_SERVER['HTTP_CLIENT_IP'], 'unknown')) {

            $uip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif(!empty($_SERVER['REMOTE_ADDR'])
            && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {

            $uip = $_SERVER['REMOTE_ADDR'];
        }

        return $uip;
    }

    public function timeStart($paramName) {
        $this->arrTimeTmp[$paramName] = 1000 * microtime(true);
        return true;
    }

    public function timeEnd($paramName) {
        $ret = false;
        if (isset($this->arrTimeTmp[$paramName])) {
            $this->arrTimeTmp[$paramName] = 1000 * microtime(true) - $this->arrTimeTmp[$paramName];
            $this->arrTime[$paramName][]  = $this->arrTimeTmp[$paramName];
            unset($this->arrTimeTmp[$paramName]);
            $ret = true;
        }
        return $ret;
    }
}