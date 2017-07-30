<?php

namespace Simpletools\ServiceQ\Terminal;

use Simpletools\ServiceQ\Cli;
use Simpletools\ServiceQ;
use Simpletools\ServiceQ\Driver\QDriver;

class Client
{
    protected $_prompt = 'ServiceQ<$ ';
    protected $_driver;
    protected $_cli;

    protected $_servicesDir;
    protected $_services = array();

    protected $_historyFile;
    protected $_ranCommands = array();

    protected $_commands = array();
    protected $_longCommands = array(
        'service',
        'timeout',

        'call',
        'publish',
        'dispatch',
        'collect',

        'clear',
        'quit',

        'clear-history'
    );

    protected $_exitCommands = array(
        'quit','exit','bye','aloha'
    );

    protected $_aliasCommands = array(
        'q','s',
        't',

        'c',
        'p',
        'dp',
        'co',

        'queue','use',
        'clean'
    );

    public function servicesDir($dir)
    {
        $this->_servicesDir = $dir;
    }

    public function __construct($driver=null)
    {
        $this->_commands = array_merge($this->_longCommands, $this->_aliasCommands, $this->_exitCommands, array('roar'));
        $this->_commands = array_unique($this->_commands);

        if($driver && !$driver instanceof QDriver)
        {
            throw new \Exception('Please specify a driver implementing QDriver interface');
        }
        elseif($driver)
        {
            $this->_driver = $driver;
        }
    }

    public function run()
    {
        $cli    = $this->_cli = new Cli();
        $cli->decorator(function($msg){
            return ''.$msg.'';
        });

        $options = getopt('',[
            'host:',
            'vhost:',
            'username:',
            'port:'
        ]);

        if($this->_driver)
            $settings   = $this->_driver->getSettings();
        else
        {
            $settings = array();

            $settings['host']   = 'localhost';
            $settings['port']   = 5672;
            $settings['vhost']  = '/';
        }

        
        $host       = isset($options['host']) ? $options['host'] : $settings['host'];
        $port       = isset($options['port']) ? $options['port'] : $settings['port'];
        $vhost      = isset($options['vhost']) ? $options['vhost'] : $settings['vhost'];

        $username   = @$options['username'];
        if(!$username)
        {
            $username = isset($settings['username']) ? $settings['username'] : '';
        }
        else
        {
            $settings['password'] = '';
        }

        if(!$username)
        {
            return $cli->error("ERROR 403: Please specify your username");
        }

        $password = '';
        if(isset($settings['username']))
            $password = isset($settings['password']) ? $settings['password'] : '';

        if(!$password)
            $password   = $this->readLineSilent('Enter password: ');

        if(stripos($host,'://')===false)
        {
            $host = "ampqs://$host";
        }

        $settings = array(
            "host"      => $host,
            "port"      => $port,
            "username"  => $username,
            "password"  => $password,
            "vhost"     => $vhost
        );

        ServiceQ\Client::driver(
            new ServiceQ\Driver\RabbitMQ($settings)
        );

        try {
            $service = ServiceQ\Client::service('dummyqueueueuue');
        }
        catch(\Throwable $e)
        {
            //return $cli->error("ERROR 403: Access denied for $username@$host");
            return $cli->error("ERROR: ".$e->getMessage());
        }

        unset($service);

        $cli->logo();
        $cli->success("Connected to $username@$host/$vhost");
        echo PHP_EOL;

        $this->_historyFile = sys_get_temp_dir().'/'.'serviceq-terminal-'.$username.'-'.hash('sha256',($host.$password)).'.log';
        @readline_read_history($this->_historyFile);

        readline_completion_function(array($this,'_completion'));

        while(true)
        {
            $cmd = $this->readLine();
            if(in_array($cmd,['exit','quit','aloha','bye']))
            {
                return $cli->debug('See ya');
            }

            try {
                $this->_evalCommand($cmd);
            }
            catch(\Throwable $e)
            {
                $cli->error($e->getMessage());
            }
        }
    }

    protected function _getMatchedPaths($haystack,$needle,$prefix='')
    {
        $return = array();

        foreach($haystack as $l)
        {
            if(!$needle)
            {
                if($l=='.' OR $l=='..') continue;

                $return[] = $prefix.$l;
            }
            elseif(stripos($l,$needle)===0)
            {
                $return[] = $prefix.$l;
            }
        }

        return $return;
    }

    protected function _scandir($needle,$haystack,$recursive=false,$prefix='')
    {
        $files = scandir($haystack);

        if($needle)
        {
            if(is_dir($haystack.'/'.$needle))
            {
                $files = scandir($haystack.'/'.$needle);
                if($files)
                {
                    return $this->_getMatchedPaths($files,'',$needle.'/');
                }
            }
            elseif(stripos($needle,'/')===false)
            {
                $res = $this->_getMatchedPaths($files,$needle,$prefix);
                return $res;
            }
            elseif(!$recursive)
            {
                $needle     = explode('/',$needle);
                $prefix2    = array_pop($needle);

                $prefix     = implode('/',$needle);

                $haystack     .=  '/'.implode('/',$needle);

                return $this->_scandir($prefix2,$haystack,true,$prefix.'/');
            }
        }

        return $this->_getMatchedPaths($files,$needle);
    }

    protected function _completion($cmd)
    {
        $line       = readline_info();
        $linebuffer = $line['line_buffer'];

        $linebuffer = explode(' ',$linebuffer);
        if(in_array(trim($linebuffer[0]), array('service','use','queue','q','s')) && $this->_servicesDir)
        {
            $ret = $this->_scandir(trim(@$linebuffer[1],'/ '),$this->_servicesDir);
            return $ret;
        }
        elseif(!isset($linebuffer[1]))
        {
            return $this->_longCommands;
        }
    }

    protected $_timeout = 30;
    protected $_queue = '';

    public function _checkCallableCommandArgs($cmd,$body)
    {
        if(!$this->_queue)
        {
            throw new \Exception('Please specify your service queue first using `service` command');
        }

        if(!isset($cmd))
        {
            throw new \Exception('Missing request data');
        }

        if(!$body)
        {
            throw new \Exception('Malformed request data');
        }
    }

    public function _parseCommand($cmd)
    {
        $command = array();
        $command['cmd'] = '';

        $cmd = explode(' ',$cmd);
        if(!in_array($cmd[0],$this->_commands))
        {
            throw new \Exception('Unrecognised method: '.$cmd[0]);
        }
        else
        {
            switch($cmd[0])
            {
                case ($cmd[0]=='service' || $cmd[0]=='s' || $cmd[0]=='use' || $cmd[0]=='queue' || $cmd[0]=='q'):

                    $this->_ranCommands['service'] = 1;

                    if(!isset($cmd[1]))
                    {
                        if(!$this->_queue)
                        {
                            throw new \Exception('Current service queue not set');
                        }

                        $this->_cli->debug('Current service queue: '.$this->_queue);
                        break;
                    }

                    $this->_queue = trim($cmd[1],' ;');
                    $this->_cli->debug('Service queue has been changed to: '.$this->_queue);
                    break;

                case ($cmd[0] == 'timeout' || $cmd[0] == 't'):

                    $this->_ranCommands['timeout'] = 1;

                    if(!isset($cmd[1]))
                    {
                        $this->_cli->debug('Default timeout set to: '.$this->_timeout.' sec.');
                        break;
                    }

                    $this->_timeout = (int) trim($cmd[1],' ;');

                    $this->_cli->debug('Default timeout has been changed to: '.$this->_timeout.' sec.');
                    break;

                case ($cmd[0] == 'publish' || $cmd[0] == 'p'):

                    $this->_ranCommands['publish'] = 1;

                    $body = json_decode(@$cmd[1]);
                    $this->_checkCallableCommandArgs(@$cmd[1],$body);

                    if(!isset($this->_services[$this->_queue]))
                        $this->_services[$this->_queue] = ServiceQ\Client::service($this->_queue);

                    $this->_services[$this->_queue]->timeout($this->_timeout)->publish($body);

                    $this->_cli->line();
                    $this->_cli->success('Published OK');
                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->line();

                    break;

                case ($cmd[0] == 'call' || $cmd[0] == 'c'):

                    $body = json_decode(@$cmd[1]);
                    $this->_checkCallableCommandArgs(@$cmd[1],$body);

                    if(!isset($this->_services[$this->_queue]))
                        $this->_services[$this->_queue] = ServiceQ\Client::service($this->_queue);

                    $exception = false;

                    try {
                        $res = $this->_services[$this->_queue]->timeout($this->_timeout)->call($body);
                        $this->_ranCommands['call'] = 1;
                    }
                    catch(ServiceQ\ResponseException $e)
                    {
                        $exception = true;
                        $res = $e->getResponse();
                    }

                    $this->_cli->line();
                    if(!$exception)
                        $this->_cli->success('Response status: '.$res->status);
                    else
                        $this->_cli->error('Response status: '.$res->status);

                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->info('Time taken (sec): '.($res->meta->servingTimeSec));
                    $this->_cli->info('Body: ');
                    echo json_encode($res->body,JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL;
                    $this->_cli->line();

                    break;

                case ($cmd[0] == 'dispatch' || $cmd[0] == 'dp'):

                    $this->_ranCommands['dispatch'] = 1;

                    $body = json_decode(@$cmd[1]);
                    $this->_checkCallableCommandArgs(@$cmd[1],$body);

                    if(!isset($this->_services[$this->_queue]))
                        $this->_services[$this->_queue] = ServiceQ\Client::service($this->_queue);

                    $id = $this->_services[$this->_queue]->timeout($this->_timeout)->dispatch($body);

                    $this->_cli->line();
                    $this->_cli->success('Request ID: '.$id);
                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->line();

                   break;

                case ($cmd[0] == 'collect' || $cmd[0] == 'co'):

                    if(!isset($this->_services[$this->_queue]))
                        $this->_services[$this->_queue] = ServiceQ\Client::service($this->_queue);

                    $requestId = isset($cmd[1]) ?  $cmd[1]: null;
                    $exception = false;

                    try {
                        $res = $this->_services[$this->_queue]->timeout($this->_timeout)->collect($requestId);
                        $this->_ranCommands['collect'] = 1;
                    }
                    catch(ServiceQ\ResponseException $e)
                    {
                        $exception = true;
                        $res = $e->getResponse();
                    }

                    $this->_cli->line();
                    if(!$exception)
                        $this->_cli->success('Response status: '.$res->status);
                    else
                        $this->_cli->error('Response status: '.$res->status);

                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->info('Time taken (sec): '.($res->meta->servingTimeSec));
                    $this->_cli->info('Response: ');
                    echo json_encode($res->body,JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL;
                    $this->_cli->line();

                    break;

                case ($cmd[0] == 'clear' || $cmd[0] == 'clean'):

                    $this->_cli->clear();
                    break;

                case ($cmd[0] == 'roar' && count($this->_ranCommands)>5):
                    $this->_cli->clear();
                    $this->_cli->lion();
                    break;

                case ($cmd[0] == 'roar'):
                    throw new \Exception('Unrecognised method: '.$cmd[0]);
                    break;

                case ($cmd[0] == 'clear-history'):

                    readline_clear_history();
                    $this->_cli->debug('History has been cleaned');
                    break;

                default:

                    $this->_cli->error('Support for command '.$cmd[0].' not released yet');

                    break;
            }
        }
    }

    public function _evalCommand($cmd)
    {
        $this->_parseCommand($cmd);
    }

    public function readLine($prompt='',$addHistory=true)
    {
        if($prompt)
        {
            $prompt = $prompt;
        }
        else
        {
            $prompt = $this->_prompt;
        }

        if (PHP_OS == 'WINNT') {
            $line = $prompt.stream_get_line(STDIN, 1024, PHP_EOL);
        } else {
            $line = readline($prompt);
        }

        if($addHistory && $line && !in_array($line,$this->_exitCommands)) {
                readline_add_history($line);
        }

        return $line;
    }

    function readLineSilent($prompt) {
        if (preg_match('/^win/i', PHP_OS)) {
            $vbscript = sys_get_temp_dir() . 'prompt_password.vbs';
            file_put_contents(
                $vbscript, 'wscript.echo(InputBox("'
                . addslashes($prompt)
                . '", "", "password here"))');
            $command = "cscript //nologo " . escapeshellarg($vbscript);
            $password = rtrim(shell_exec($command));
            unlink($vbscript);
            return $password;
        } else {
            $command = "/usr/bin/env bash -c 'echo OK'";
            if (rtrim(shell_exec($command)) !== 'OK') {
                trigger_error("Can't invoke bash");
                return;
            }
            $command = "/usr/bin/env bash -c 'read -s -p \""
                . addslashes($prompt)
                . "\" mypassword && echo \$mypassword'";
            $password = rtrim(shell_exec($command));
            echo "\n";
            return $password;
        }
    }

    public function __destruct()
    {
        @touch($this->_historyFile);
        @readline_write_history($this->_historyFile);
    }
}