<?php

use Ext\Url;
use Ext\X\PhpRedis;
use model\Glob;
use model\stat\VarServer;

class Stat
{
    const VERSION = 225.1350;
    public static $unique = null;

    public function __constrcut()
    {

    }

    // 弃用：记录 SERVER 变量
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

    // 优化后记录 SERVER 变量
    public static function srv($vars = null)
    {
        $arr = null === $vars ? $_SERVER : $vars;
        $session_var_str = 'PHP_AUTH_USER,PHP_AUTH_PW';
        // bat 变量
        $hvar = 'LOCALAPPDATA,TMP,SESSIONNAME,windir,FCGI_ROLE,APPDATA,PROMPT,FP_NO_HOST_CHECK,LOGONSERVER,OS,TEMP,PUBLIC,Path,NUMBER_OF_PROCESSORS,ComSpec,HOMEDRIVE,PATHEXT,COMPUTERNAME,CLIENTNAME,HOMEPATH,ALLUSERSPROFILE,PSModulePath,GATEWAY_INTERFACE,';
        $hvar .= 'soft_dir,soft2_dir,php7_dir,';
        // web 服务器
        $hvar .= 'PATH_INFO,PATH_TRANSLATED,QUERY_STRING,REDIRECT_STATUS,';
        # 前缀开始
        // http
        $hvar .= 'HTTP_=CONNECTION|CACHE_CONTROL|UPGRADE_INSECURE_REQUESTS|IF_MODIFIED_SINCE|PRAGMA|AUTHORIZATION|PURPOSE|HOST|USER_AGENT|ACCEPT|ACCEPT_ENCODING|ACCEPT_LANGUAGE|COOKIE|REFERER,';
        $hvar .= 'HTTP_SEC_FETCH_=SITE|MODE|USER,';
        $hvar .= 'REQUEST_=SCHEME|METHOD|TIME|TIME_FLOAT|URI,';
        $hvar .= 'REMOTE_=ADDR|PORT,';
        // web 服务器
        $hvar .= 'SERVER_=PROTOCOL|NAME|ADDR|PORT|SOFTWARE,';
        $hvar .= 'SCRIPT_=NAME|FILENAME,';
        $hvar .= 'PHP_=SELF,';
        $hvar .= 'DOCUMENT_=ROOT|URI,';
        $hvar .= 'CONTENT_=TYPE|LENGTH,';
        // bat 变量
        $hvar .= 'Common=ProgramFiles|ProgramFiles(x86)|ProgramW6432,';
        $hvar .= 'System=Drive|Root,';
        $hvar .= 'Program=Files|Files(x86)|Data|W6432,';
        $hvar .= 'PROCESSOR_=ARCHITECTURE|IDENTIFIER|REVISION|LEVEL,';
        $hvar .= 'USER=NAME|PROFILE|DOMAIN,';
        $session_var = explode(',', $session_var_str);
        $hvars = explode(',', $hvar);
        // 移除无用键
        foreach ($hvars as $key) {
            $sec = explode('=', $key);
            $len = count($sec);
            if (1 < $len) {
                list($prefix, $names) = $sec;
                $nm = explode('|', $names);
                foreach ($nm as $v) {
                    $keyname = "$prefix$v";
                    unset($arr[$keyname]);
                }
            } else {
                unset($arr[$key]);
            }
        }
        $result = [];
        foreach ($arr as $key => $value) {
            // 会话作为唯一
            if (in_array($key, $session_var)) {
                $json = json_encode([$value, 0, Glob::$sid]);
                $k = PhpRedis::sAdd("stat_y_key", $key);
                $r = PhpRedis::sAdd("STAT:$key", $json);
            } else { // 请求唯一
                $json = json_encode([$value, self::$unique]);
                $k = PhpRedis::sAdd("stat_x_key", $key);
                $r = PhpRedis::sAdd("statis_$key", $json);
            }
            $result[] = [$k, $r];
        }
        return $result;
    }

    // 访问日志
    public static function record()
    {
        new \Func\Variable;
        $scheme = \Func\request_scheme();
        $float = $_SERVER['REQUEST_TIME_FLOAT'] ?? -0.1;
        $host = $_SERVER['HTTP_HOST'] ?? '<unknown>';
        $uri = $_SERVER['REQUEST_URI'] ?? '<err>';
        $url = "$scheme://$host$uri";
        $addr = $_SERVER['REMOTE_ADDR'] ?? '<err>';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '<err>';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '<err>';
        $accept_encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '<err>';
        $accept_language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '<err>';
        $cookie = $_SERVER['HTTP_COOKIE'] ?? '<err>';
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        $log = json_encode([$addr, $float, $accept_encoding, md5($referer) .','. md5($url) .','. md5($ua) .','. md5($accept) .','. md5($accept_language) .','. md5($cookie) .','. self::$unique .','. Glob::$sid]);

        #PhpRedis::conn('127.0.0.1', 6379, 0, null, 0, 0, ['auth' => 'redis3.2.100']);
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
    public static function cookie($redirect = true, $name = 'ENABLE_COOKIE_127_0_0_1', $https = null, $sid = null)
    {
        $queryKey = 'sid';
        #new \Func\X\Crypto;
        new \Func\Variable;
        $disabled = $_GET[$queryKey] ?? null;
        $time = time();
        $URL = \Func\request_url($_SERVER, true);
        parse_str($URL['query'] ?? null, $queryData);
        $path = $URL['path'];
        $scheme = \Func\request_scheme();
        $secure = 'https' === $scheme ? true : false;
        if (false === $https) {
            $secure = false;
        }

        // 键名
        #$secret = 'test';
        #$key = \Func\X\separator_encrypt($name, $secret);
        $key = 'SID';

        // 获取并校验
        $value = $_COOKIE[$key] ?? null;
        #$verify = \Func\X\separator_verify($key, $secret);
        if (null === $value) {
            setcookie($key, $sid, time() + 864000000, '/', '', $secure, true);
            if (null === $disabled) {
                $queryData[$queryKey] = 0;
                self::redirect($path, $queryData, $redirect);
            } elseif ('0' === $disabled) { // 禁用了 Cookie
                $queryData[$queryKey] = $sid;
                self::redirect($path, $queryData, $redirect);
            }
        } elseif (null !== $disabled) { // Cookie 可用，取消 sid
            unset($queryData[$queryKey]);
            self::redirect($path, $queryData, $redirect);
        }
        return $value;
    }

    // 拼接路径和查询字符串后转向
    public static function redirect($path, $queryData, $redirect = null)
    {
        if (!$redirect) {
            return false;
        }
        $query = Url::buildQuery($queryData);
        header("Location: $path$query");
        print_r(array($path, $query, __FILE__, __LINE__));exit;
    }
}
