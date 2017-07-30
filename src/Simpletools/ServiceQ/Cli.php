<?php

namespace Simpletools\ServiceQ;

class Cli
{
    const INFO                  = "\e[0m"; //default colors
    const ERROR                 = "\e[41m\e[37m\e[1m"; //red background, white font
    const WARNING               = "\e[43m\e[30m"; //yellow background, black font
    const SUCCESS               = "\e[42m\e[37m\e[1m"; //green background, white font
    const NOTE                  = "\e[44m\e[37m"; //blue background, white font

    const TEXT_COLOR_BLACK      = "\e[30m";
    const TEXT_COLOR_CYAN       = "\e[36m";
    const TEXT_COLOR_RED        = "\e[31m";
    const TEXT_COLOR_GREEN      = "\e[32m";
    const TEXT_COLOR_PURPLE     = "\e[35m";
    const TEXT_COLOR_YELLOW     = "\e[33m";
    const TEXT_COLOR_WHITE      = "\e[37m";
    const TEXT_COLOR_GREY       = "\e[0;37m";

    const TEXT_BOLD             = "\e[1m";

    const BG_COLOR_BLACK        = "\e[40m";
    const BG_COLOR_RED          = "\e[41m";
    const BG_COLOR_GREEN        = "\e[42m";
    const BG_COLOR_YELLOW       = "\e[43m";
    const BG_COLOR_BLUE         = "\e[44m";
    const BG_COLOR_MAGENTA      = "\e[45m";
    const BG_COLOR_CYAN         = "\e[46m";

    const COLOR_OFF             = "\e[0m";

    protected $_suffix = '';
    protected $_prefix = '';

    protected static $_S_syslog = false;
    protected $_syslog          = false;

    protected $_decorator;

    public function __construct()
    {
        $this->_syslog = self::$_S_syslog;
    }

    public static function enableSyslog()
    {
        self::$_S_syslog = true;
    }

    public static function disableSyslog()
    {
        self::$_S_syslog = false;
    }

    public function syslogOn()
    {
        $this->_syslog = true;
    }

    public function syslogOff()
    {
        $this->_syslog = false;
    }

    public function lion()
    {
        echo '777777777777777777777777777777,I+?7=7~..+. I??:+?7777777777777777777777777777777
77777777777777777777777777777=+=II77I I+=~7?7I7=?7?77777777777777777777777777777
7777777777777777777= 7~7.?? ?I7~I7+7I::II7 77I7 I7~+???7I7?7I7777777777777777777
777777777777777777, +.:?I.:=77+,I??7I +,.?I?,.++.I     . 7,.I?777777777777777777
7777777777777777=.7.7  = 7.:=.7.:I~?I?,+.:?..:,..7:7,II7~I I,=777777777777777777
77777777777777777.?.=?+??.?.?.:~,..:+I7, 777?I,,.=~?.II+?~??+,.7?777777777777777
77777777I=.:~,:.? +??:7+~.I:I..,...++,I?.I7I....,..?.~+=+I~+7~.,..:==:7777777777
77777+I?..I7?.... ??I=+??.,......I,+?:,,. ?7~~=.......++I++?+,,+:..~?.+7:?777777
77777?=~I+?=,++=?.?+I?~:......,?7,7.=+=,.I:~.7777?.,...I=?,,=+=+.:=~:::+=?777777
7777I:~?=:,I+~+7?I:.,?+:..?.?~777?7+,.?.,:..I7I77II:~...7:,:+,77=~.,?..,7.+77777
77777~?:+,I=~..,+7?I+.,..:,:.I,I ?I7I+7.I?77+77II,..=+...,.7+77?=..?.?:~?I=77777
7777+?~=:+.+.,?+:.7?,..,~,I~,.77=77+?7...77:7=7777  7:+:..=I7II~~?...~~?~=.77777
777 ~7=:=:~I=777? ...~.7I7~ :77I?I?I77+:.I7I77?I77:=.~I7.....  I7:7?I.=+~?++7777
7777+II~..I.?.I?+..,,.77=7I~7 7,,?II?:+..=??77===7 ?7=777~.....7I 7.7:.~:7.77777
7777?.?I=~,.=7++=.~7..........77:,,?77~7~.I ,:=  ,.......,.I:.=777=7. ???.,77777
7777+..7+I7,7I7?=~~:~.7I.=..:......?:?7.?I~I......:,.~..77.:==:I777,:~7I...77777
7777?,... 77777=+:,.::..77,.....,,?I+77+7777+~.......?7...7...,?I77777....=77777
777777 ,7++77=I..~+.? 7I+=...+:+.?7?.I??I~:.77~.~+?.,..+==...7.+7 ?,.I..:7777777
777777?..77?7I7=.~=+.:77+?,I7=....??+ 7I7 +I=?...=777~=77 ..=+..~7777=~.:7777777
77777:= 7 ~7777~......7 ..??7 77,.7=I,7+:~.7.~.:77.7+7.7 ..7I..,777?=. +~.777777
77777:~=.7,?77=+..:.=,.:777?777...II7~7~+~+ ?=..777.7777.:.:.I~, +7I. I=?=+77777
77777 I7I:.77+? .=....7= II7777..:.I:I...7?+=.~.7 77777+ 7...,II ,777.?.7. ~7777
777777777777I++?7,+~.?77....I777?....:?+I=~....?777,,:7777.::.~?I??I777777777777
77777777I777?I777II77I=I77777.?+77 ......,...7777.777777?=I7?+?I7+=777I777777777
77777777777I777,777I?77..?7777777 77~....:.7777777777 ..=7I7777?77I?77I+=7777777
7777777:7II?7I77???=7 777.7..+?777777?....7777777=,.?.I7777?II:+,=77I?7I77777777
77777777I.?77?.=I7~  7+7?7777777777777...777777777777777,,7=  7?777777=7I7777777
777777777777I?I77=7+7:+7 ..?+77777777?,..,I77777777.7: I+=7 ,I~I77?777777 777777
77777777.?7+77I7,?=777.?7,7 7+7I=..7777I7 I7~..++777?=7I.7 77I+7?7777I?~77777777
77777777=7777I=~??~?I.~+I=..+.I77.............777=..~I:=::I=I,7?7777777+77777777
77777777?77~7.I7I=??,==??7:...777.............777....+II=I. +7I77,:7777777777777
777777 7?7I=I777+II.,7=+I7I:..=77.............777...:~+77=+7+7??7777..7~=7777777
7777777?7?=7777I.7 ?7:,=?7,=...77.............77....= 7:~.7,I~777+77777777777777
7777777777 7?7I7 ++=7, 7=???.......................I~?7?:?:++I7III77777777777777
777777777+7777I7777?+I=?7, I~......................+77=,:=+77+77??7I7,7777777777
7777777777777,? 777+7???.~+7..........,..,.........7?~=,:77?7+I?II7777I777777777
77777777777777, ?77I7II?. ~7.....,,::~~=~:~+:...... I.?,~:777=77777 777777777777
77777777777777I7:7~77?~?~..?I....~+?7III7I7?=~,....:.77?7?+I7 +I7777777777777777
77777777777777I~?II7I???7 ,7:....~I77+777777I~,..:.I~,~~?=77??I 7777777777777777
777777777777777777?7I~7??~7=~.7.7.I77777777I=:77..., +?7??II7I?77777777777777777
77777777777777777777 ??++7~.~...77,?7777777?~777..?:=7~II7??7I777777777777777777
77777777777777777777=??=+????...7777.:+~=~..777?..I7~.~?I?+ 77777777777777777777
777777777777777777777==:I+.~77...?7777: 777.7I7.. 7I:I+=?+7=77777777777777777777
777777777777777777777 := :+.77~..................II7:~~7~:7777777777777777777777
7777777777777777777777?7=7~~+777..:=,I:I77. I~.7777?,+:+.77777777777777777777777
77777777777777777777777777,=~7777777=7~7I777777777.7,777777777777777777777777777
77777777777777777777777777,:=I777777?7   777 7777?, .777777777777777777777777777
777777777777777777777777777 ~:~777I77777 I777777,II:=777777777777777777777777777
777777777777777777777777777 .=,.7?777777I7++7 ~++=~7?777777777777777777777777777
77777777777777777777777777777I,: +..+ ++:+ =.7?::7777777777777777777777777777777
77777777777777777777777777777777I+,.?.,...:,~.?I77777777777777777777777777777777'
            .PHP_EOL;
    }

    public function clear()
    {
        if (preg_match('/^win/i', PHP_OS)) {
            system('cls');
        }
        else{
            system('clear');
        }
    }

    public function msg($msg,$status=self::INFO)
    {
        $line = array();


        if($this->_prefix)
        {
            $line[] = $this->_prefix;
        }

        $line[] = $status;

        if(is_callable($this->_decorator))
        {
            $msg = call_user_func($this->_decorator,$msg);
        }

        $line[] = $msg;
        $line[] = "\e[0m";

        if($this->_suffix)
        {
            $line[] = $this->_suffix;
        }


        $line[] = PHP_EOL;

        print implode('',$line);

        return $this;
    }

    public function info($msg)
    {
        $this->syslog(LOG_INFO,$msg);
        return $this->msg($msg);
    }

    public function debug($msg)
    {
        $this->syslog(LOG_DEBUG,$msg);
        return $this->msg($msg,self::NOTE);
    }

    public function error($msg)
    {
        $this->syslog(LOG_ERR,$msg);
        return $this->msg($msg,self::ERROR);
    }

    public function warning($msg)
    {
        $this->syslog(LOG_WARNING,$msg);
        return $this->msg($msg,self::WARNING);
    }

    public function success($msg)
    {
        $this->syslog(LOG_INFO,$msg);
        return $this->msg($msg,self::SUCCESS);
    }

    public function prefix($prefix)
    {
        $args       = func_get_args();
        $colors     = self::COLOR_OFF;

        if(count($args)>1) {
            $colors = array_slice($args, 1);
            $colors = implode('', $colors);
        }
        $this->_prefix = $colors.$prefix.self::COLOR_OFF.' ';

        return $this;
    }

    public function suffix($suffix)
    {
        $args       = func_get_args();
        $colors     = self::COLOR_OFF;

        if(count($args)>1) {
            $colors = array_slice($args, 1);
            $colors = implode('', $colors);
        }

        $this->_suffix = self::COLOR_OFF.' '.$colors.$suffix.self::COLOR_OFF;

        return $this;
    }

    public function decorator(callable $decorator)
    {
        $this->_decorator = $decorator;
    }

    public function line($text="")
    {
        $string     = "-";
        $width      = exec('tput cols');
       // $colors     = self::TEXT_COLOR_YELLOW;
        $colors     = "";

        if($text)
        {
            $length = strlen($text)+2;
            $parts = floor(($width - $length)/2);
            if($parts<0) $parts = 0;

            print $colors.str_repeat($string,$parts).' '.$text.' '.str_repeat($string,$parts).self::COLOR_OFF.PHP_EOL;
        } else
            print $colors.str_repeat($string,$width).self::COLOR_OFF.PHP_EOL;

        return $this;
    }

    public function timeLine($text='')
    {
        if($text) $text.= ' ';
        return $this->line($text.date(DATE_ISO8601));
    }

    public function newline()
    {
        print PHP_EOL;
        return $this;
    }

    public function syslog($level,$msg)
    {
        if($this->_syslog)
            syslog($level,$msg);

        return $this;
    }
}