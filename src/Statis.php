<?php

namespace Stat;

use model\stat\VarServer;

class Statis
{
    public function __constrcut()
    {

    }

    // 记录 SERVER 变量
    public static function server($vars = null)
    {
        $variable = $vars || $_SERVER;
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
                print_r([$key, $value, $up]);
            }
        }
        return $arr;
    }
}
