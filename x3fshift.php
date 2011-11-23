<?php

/*

使い方

  バックアップしておく。
  
  ファイルの日付を確認
  
    php x3fshift.php 0 D:\DCIM\*.X3F
  
  ずれの時間を調整
  2009-01-11 13:18:22 のファイルを 2011-11-11 22:52:00 に変更する場合。
  結果を見てパラメータが合っているか確認。
  
    php x3fshift.php 20111122225200-20090111131822 D:\DCIM\*.X3F

  okなら -x オプションを追加して書き換え実行。

    php x3fshift.php 20111122225200-20090111131822 D:\DCIM\*.X3F -x


バイナリデータの扱いが怪しいから php のバージョンによってはだめかも。

*/

mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');
mb_http_output('CP932');

ob_start('mb_output_handler');

$DEBUG = false;

if (!main($argv)) {
    die('php '.$argv[0]." [option...] <shift_sec> <filename>\n"
        ."\n"
        ." option:\n"
        ."  -x 実行(このオプションを指定しない限り、ファイルは変更されない)\n"
        ."  -d Debug\n");
}


function main($args)
{
    array_shift($args);
    
    $params = array();
    $execute = false;
    foreach ($args as $arg) {
        if (substr($arg, 0, 1) == '-') {
            switch ($arg) {
            case '-d':
                global $DEBUG;
                $DEBUG = true;
                break;
            case '-x':
                $execute = true;
                break;
            default:
                return false;
            }
        }
        else {
            $params []= $arg;
        }
    }
    
    if (count($params) != 2) {
        return false;
    }
    
    $shift = array_shift($params);
    $file = array_shift($params);
    
    if (preg_match('/(\d{14,14})-(\d{14,14})/', $shift, $m)) {
        $shift = YmdHisToTime($m[1]) - YmdHisToTime($m[2]);
    }
    

    foreach (glob($file) as $filename) {
        printf("%s", $filename);
        x3f_shift($shift, $filename, $execute);
        echo "\n";
    }
    
    return true;
}

// yyyy mm dd HH ii ss
// 0123 45 67 89 01 23
function YmdHisToTime($str)
{
    return mktime(
        substr($str, 8, 2),
        substr($str, 10, 2),
        substr($str, 12, 2),
        substr($str, 4, 2),
        substr($str, 6, 2),
        substr($str, 0, 4));
}


function x3f_shift($shift, $filename, $execute)
{
    global $DEBUG;

    if ($execute) {
        $fp = fopen($filename, 'r+b');
    } else {
        $fp = fopen($filename, 'rb');
    }
    
    // Headerを読み込み
    if (fread($fp, 4) != 'FOVb') {
        throw new Exception('x3fじゃない');
    }

    fseek($fp, -4, SEEK_END);
    $x = unpack2('V', fread($fp, 4));
    //printf("%u(0x%X)\n", $x, $x);
    
    fseek($fp, $x, SEEK_SET);
    if (fread($fp, 4) != 'SECd') {
        throw new Exception('SECdが見つからない');
    }
    if (fread($fp, 4) != "\x00\x00\x02\x00") {
        throw new Exception('SECd の Versionが 2.0 じゃない');
    }
    
    // SECd
    
    $cnt = unpack2('V', fread($fp, 4));
    $dic = array();
    if ($DEBUG) echo "SECd\n";
    for ($j=0; $j<$cnt; $j++) {
        $sec = unpack('Voff/Vlen', fread($fp, 8));
        $name = fread($fp, 4);
        $dic[$name] = $sec;
        if ($DEBUG) {
            printf(" name:%s off:0x%08X len:0x%08X\n",
                $name, $sec['off'], $sec['len']);
        }
    }
    
    // SECp
    
    if (!isset($dic['PROP'])) {
        throw new Exception('PROPが見つからない');
    }
    
    fseek($fp, $dic['PROP']['off'], SEEK_SET);
    if (fread($fp, 4) != 'SECp') {
        throw new Exception('PROPが見つからない');
    }
    
    if (fread($fp, 4) != "\x00\x00\x02\x00") {
        throw new Exception('SECp の Versionが 2.0 じゃない');
    }
    
    $cnt = unpack2('V', fread($fp, 4));
    fseek($fp, 8, SEEK_CUR);
    $nameValueLen = unpack2('V', fread($fp, 4));
    $props = array();
    for ($j=0; $j<$cnt; $j++) {
        $props [] = unpack2('V', fread($fp, 4));
        $props [] = unpack2('V', fread($fp, 4));
    }
    //var_dump($props);
    //printf("ftell:0x%X\n", ftell($fp));
    
    $props [] = $nameValueLen;
    if ($DEBUG) echo "SECp\n";
    for ($j=0; $j<$cnt; $j++) {
        $idx = $j * 2;
        $name = freadUni($fp, ($props[$idx+1] - $props[$idx]));
        $value = freadUni($fp, ($props[$idx+2] - $props[$idx+1]));
        if ($DEBUG) {
            printf(" %s: %s\n", $name, $value);
        }
        if ($name == 'TIME') {
            $newValue = $value + $shift;
            printf("\t%s", gmdate('Y-m-d H:i:s', $value));
            printf(" => %s", gmdate('Y-m-d H:i:s', $newValue));
            if (strlen($newValue) != strlen($value)) {
                throw new Exception('TIME の長さが変わってしまうので書き換えられない');
            }
            if ($execute) {
                fseek($fp, -($props[$idx+2] - $props[$idx+1])*2, SEEK_CUR);
                $data = mb_convert_encoding($newValue, 'UTF-16LE', mb_internal_encoding());
                fwrite($fp, $data, strlen($data) * 2);
                fclose($fp);
                
                echo " 修正";
                return true;
            }
        }
    }
    fclose($fp);
}

function freadUni($fp, $len)
{
    $value = fread($fp, $len * 2);
    $str = "";
    foreach (unpack('v'.$len, $value) as $c) {
        if ($c == 0) break;
        $str .= chr($c);
    }
    return $str;
}


function unpack2($fmt, $str, $index = 0)
{
    $values = unpack($fmt, $str);
    return $values[$index + 1];
}

