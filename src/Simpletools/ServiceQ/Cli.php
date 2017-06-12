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

    public function msg($msg,$status=self::INFO)
    {
        $line = array();


        if($this->_prefix)
        {
            $line[] = $this->_prefix;
        }

        $line[] = $status;
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