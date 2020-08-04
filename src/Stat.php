<?php

use Ext\Url;
use Ext\X\PhpRedis;
use model\stat\VarServer;

class Stat
{
    public function __constrcut()
    {

    }

    // 记录 SERVER 变量
    public static function server($vars = null)
    {
        $variable = $vars ?: $_SERVER;
        $VarServer = new VarServer;
        $db_table = $VarServer->from();

        // 获取
        $pieces = array_keys($variable);
        $imp = implode("','", $pieces);
        $in = "'$imp'";
        $sql = "SELECT name,value,vary FROM $db_table WHERE name IN ($in)";
        $all = VarServer::queryAll($sql);
        $check = $kv = [];
        foreach ($all as $val) {
            $check[$val->name] = $val->value;
            $kv[$val->name] = $val;
        }

        // 遍历
        $arr = [];
        foreach ($variable as $key => $value) {
            $val = $check[$key] ?? null;
            $vary = $kv[$key]->vary ?? null;
            $data = [
                'name' => $key,
                'value' => $value,
            ];
            $set = $VarServer->sqlSet($data);
            // 不存在
            if (null === $val) {
                $sql = "INSERT INTO $db_table SET $set";
                $arr[] = $id = $VarServer->insert($sql);
            } elseif ($val != $value && !$vary) { // 变动的值
                $sql = "UPDATE $db_table SET vary = 1 WHERE name = '$key'";
                $arr[] = $up = $VarServer::exec($sql);
                #print_r([$key, $value, $up]);
            }
        }
        return $arr;
    }

    // 访问日志
    public static function record()
    {
        $float = $_SERVER['REQUEST_TIME_FLOAT'] ?? -0.1;
        $host = $_SERVER['HTTP_HOST'] ?? '<unknown>';
        $uri = $_SERVER['REQUEST_URI'] ?? '<err>';
        $url = "http://$host$uri";
        $addr = $_SERVER['REMOTE_ADDR'] ?? '<err>';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '<err>';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '<err>';
        $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '<err>';
        $accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '<err>';
        $cookie = $_SERVER['HTTP_COOKIE'] ?? '<err>';
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        $log = json_encode([$addr, $float, $accept_encoding, md5($referer) .','. md5($url) .','. md5($ua) .','. md5($accept) .','. md5($accept_language) .','. md5($cookie)]);

        PhpRedis::conn('127.0.0.1', 6379, 0, null, 0, 0, ['auth' => 'redis3.2.100']);
        $sadd_host = PhpRedis::sAdd('stat_host', $host);
        $sadd_referer = PhpRedis::sAdd('stat_url', $referer);
        $sadd_url = PhpRedis::sAdd('stat_url', $url);
        $sadd_remote_addr = PhpRedis::sAdd('stat_remote_addr', $addr);
        $sadd_user_agent = PhpRedis::sAdd('stat_user_agent', $ua);
        $sadd_accept = PhpRedis::sAdd('stat_accept', $accept);
        $sadd_accept_encoding = PhpRedis::sAdd('stat_accept_encoding', $accept_encoding);
        $sadd_accept_language = PhpRedis::sAdd('stat_accept_language', $accept_language);
        $sadd_cookie = PhpRedis::sAdd('stat_cookie', $cookie);
        $lpush_log = PhpRedis::lPush('stat_log', $log);
        return get_defined_vars();
    }

    // 检测 Cookie 启用情况
    public static function cookie()
    {
        new \Func\X\Crypto;
        new \Func\Variable;
        $disabled = $_GET['disabled'] ?? null;
        $time = time();
        $URL = \Func\request_url($_SERVER, true);
        parse_str($URL['query'] ?? null, $queryData);
        $path = $URL['path'];

        // 键名
        $name = 'ENABLE_COOKIE_127_0_0_1';
        $secret = 'test';
        $key = \Func\X\separator_encrypt($name, $secret);

        // 获取并校验
        $value = $_COOKIE[$key] ?? null;
        $verify = \Func\X\separator_verify($key, $secret);
        if (null === $value || !$verify) {
            header("Set-Cookie: $key=$time");
            if (null === $disabled) {
                $queryData['disabled'] = 0;
                $query = Url::buildQuery($queryData);
                header("Location: $path$query");
            } elseif ('0' === $disabled) { // 禁用了 Cookie
                $queryData['disabled'] = 1;
                $query = Url::buildQuery($queryData);
                header("Location: $path$query");
            }
        } elseif (null !== $disabled) { // 取消
            unset($queryData['disabled']);
            $query = Url::buildQuery($queryData);
            header("Location: $path$query");
        }
        return $value;
    }
}
