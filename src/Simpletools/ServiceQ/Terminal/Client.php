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
    protected $_timeout = 30;
    protected $_queue = '';

    protected $_servicesDir;
    protected $_services = array();

    protected $_historyFile;
    protected $_ranCommands = array();

    protected $_settings;

    protected $_commands = array();
    protected $_longCommands = array(
        "reconnect",
        'service',
        'timeout',

        'call',
        'publish',
        'dispatch',
        'collect',

        'clear',
        'quit',

        'clear-history',
        'collect-nowait',

        'dispatch-expires',
        'call-expires',
        'publish-expires'
    );

    protected $_expires = array(
        'message'   => 0,
        'dispatch'  => 60,
        'reply'     => 1
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

    public function __construct(mixed $driver=null)
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

    public function connect(mixed $settings=null)
    {
        if($settings)
        {
            $this->_settings = $settings;
        }
        else
        {
            $settings = $this->_settings;
        }

        ServiceQ\Client::driver(
            new ServiceQ\Driver\RabbitMQ($settings)
        );

        ServiceQ\Client::service('dummyqueueueuue');
    }

    public function settings($settings)
    {
        $this->_settings = $settings;
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

        $this->settings($settings);

        try {
            $this->connect();
        }
        catch(\Exception $e)
        {
            return $this->_cli->error("ERROR: ".$e->getMessage());
        }
        catch(\Throwable $e)
        {
            return $this->_cli->error("ERROR: ".$e->getMessage());
        }

        unset($service);

        $cli->logo();
        $cli->success("Connected to $username@$host/".trim($vhost," /\t\n"));
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

            try
            {
                $this->_evalCommand($cmd);
            }
            catch(\Exception $e)
            {
                $cli->error($e->getCode().' '.$e->getMessage());
            }
            catch(\Throwable $e)
            {
                $cli->error($e->getCode().' '.$e->getMessage());
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

    protected function _completion($cmd,$position)
    {

        $argument = true;

        if(strlen($cmd)>=$position && stripos($cmd,'/')===false)
        {
            $argument = false;
        }


        if($argument && $this->_servicesDir)
        {
            $ret = $this->_scandir(trim($cmd,'/ '),$this->_servicesDir);
            return $ret;
        }
        elseif(!$argument)
        {
            return $this->_longCommands;
        }
    }



    public function _checkCallableCommandArgs($cmd,$body,mixed $queue=null)
    {
        if(!$queue)
        {
            $queue = $this->_queue;
        }

        if(!$queue)
        {
            throw new \Exception('Please specify your service queue first using `service` command');
        }

        if(!isset($cmd))
        {
            throw new \Exception('Missing request data');
        }

        if(!$body && $body!==false)
        {
            throw new \Exception('Malformed request data');
        }
    }

    public function _getService(mixed $service=null)
    {
        if(!$service)
        {
            $service = $this->_queue;
        }

        if(!isset($this->_services[$service]))
            $this->_services[$service] = ServiceQ\Client::service($service);

        return $this->_services[$service];
    }

    /*
     * Supports:
     * -e
     * -e <value>
     * --long-param
     * --long-param=<value>
     * --long-param <value>
     * <value>
     */
    protected function _getOptions(array $args,$noopt=array())
    {
        $result = array();

        $params = array_filter($args);
        // could use getopt() here (since PHP 5.3.0), but it doesn't work relyingly
        reset($params);
        while (list($tmp, $p) = each($params)) {
            if ($p[0] == '-') {
                $pname = substr($p, 1);
                $value = true;
                if ($pname[0] == '-') {
                    // long-opt (--<param>)
                    $pname = substr($pname, 1);
                    if (strpos($p, '=') !== false) {
                        // value specified inline (--<param>=<value>)
                        list($pname, $value) = explode('=', substr($p, 2), 2);
                    }
                }
                // check if next parameter is a descriptor or a value
                $nextparm = current($params);
                if (!in_array($pname, $noopt) && $value === true && $nextparm !== false && $nextparm[0] != '-') list($tmp, $value) = each($params);
                $result[$pname] = $value;
            } else {
                // param doesn't belong to any option
                $result[] = $p;
            }
        }
        return $result;
    }

    public function _runCommand($cmd,$args=array())
    {
        $command = array();
        $command['cmd'] = '';

        $service = isset($args['s']) ? $args['s'] : $this->_queue;
        $timeout = isset($args['t']) ? $args['t'] : 90;

        preg_match('/[\{\[].*[\}\]]/',$cmd,$matches);
        $body = @$matches[0];
        if($body)
        {
            $body = json_decode($body);
        }

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

                    $this->_checkCallableCommandArgs(@$cmd[1],$body,$service);

                    $this->_getService($service)->timeout($timeout)->publish($body);

                    $this->_cli->line();
                    $this->_cli->success('Published OK');
                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->line();

                    break;

                case ($cmd[0] == 'call' || $cmd[0] == 'c'):

                    $this->_checkCallableCommandArgs(@$cmd[1],$body,$service);

                    $exception = false;

                    try {
                        $res = $this->_getService($service)->timeout($timeout)->call($body);
                        $this->_ranCommands['call'] = 1;
                    }
                    catch(ServiceQ\ResponseException $e)
                    {
                        $exception = true;
                        $res = $e->getResponse();
                    }

                    $this->_cli->line();
                    if(!$exception)
                        $this->_cli->success('Response status: '.$res->status.' '.$res->statusMsg);
                    else
                        $this->_cli->error('Response status: '.$res->status.' '.$res->statusMsg);

                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->info('Time taken (sec): '.($res->meta->servingTimeSec));
                    $this->_cli->info('Body: ');
                    echo json_encode($res->body, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

                    $this->_cli->line();

                    break;

                case ($cmd[0] == 'dispatch' || $cmd[0] == 'dp'):

                    $this->_ranCommands['dispatch'] = 1;

                    $this->_checkCallableCommandArgs(@$cmd[1],$body,$service);

                    $id = $this->_getService($service)->timeout($timeout)->dispatch($body);

                    $this->_cli->line();
                    $this->_cli->success('Request ID: '.$id);
                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->line();

                   break;

                case ($cmd[0] == 'collect' || $cmd[0] == 'co'):

                    $this->_checkCallableCommandArgs(@$cmd[1],false,$service);

                    $requestId = isset($cmd[1]) ?  $cmd[1]: null;
                    $exception = false;

                    try {
                        $res = $this->_getService($service)->timeout($timeout)->collect($requestId);
                        $this->_ranCommands['collect'] = 1;
                    }
                    catch(ServiceQ\ResponseException $e)
                    {
                        $exception = true;
                        $res = $e->getResponse();
                    }

                    $this->_cli->line();
                    if(!$exception)
                        $this->_cli->success('Response status: '.$res->status.' '.$res->statusMsg);
                    else
                        $this->_cli->error('Response status: '.$res->status.' '.$res->statusMsg);

                    $this->_cli->info('Service queue: '.($this->_queue));
                    $this->_cli->info('Time taken (sec): '.($res->meta->servingTimeSec));
                    $this->_cli->info('Response: ');
                    echo json_encode($res->body, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

                    $this->_cli->line();

                    break;

                case 'collect-nowait':

                    $this->_checkCallableCommandArgs(@$cmd[1],false,$service);

                    $requestId = isset($cmd[1]) ?  $cmd[1]: null;

                    $exception = false;

                    try
                    {
                        $res = $this->_getService($service)->collectNoWait($requestId);
                    }
                    catch(ServiceQ\ResponseException $e)
                    {
                        $exception = true;
                        $res = $e->getResponse();
                    }

                    if($res) {
                        $this->_cli->line();
                        if (!$exception) {
                            $this->_cli->success('Response status: ' . $res->status . ' ' . $res->statusMsg);
                        } else {
                            $this->_cli->error('Response status: ' . $res->status . ' ' . $res->statusMsg);
                        }

                        $this->_cli->info('Service queue: ' . ($this->_queue));
                        $this->_cli->info('Time taken (sec): ' . ($res->meta->servingTimeSec));
                        $this->_cli->info('Response: ');
                        echo json_encode($res->body, JSON_PRETTY_PRINT) . PHP_EOL . PHP_EOL;

                        $this->_cli->line();
                    }
                    else
                    {
                        $this->_cli->debug('Not ready yet or already collected');
                    }

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

                case ($cmd[0] == 'reconnect'):

                    $this->connect();
                    $this->_cli->success('Reconnected');
                    break;

                case 'dispatch-expires':

                    if(!isset($cmd[1]) OR !trim($cmd[1]))
                    {
                        $this->_cli->debug('Default dispatch expires set to: '.ServiceQ\Client::expires('dispatch').' sec.');
                        break;
                    }

                    $expires = (float) $cmd[1];
                    if(!$expires)
                    {
                        throw new \Exception('Default dispatch expires can\'t be set to 0sec');
                        break;
                    }

                    $this->_cli->debug('Default dispatch expires has been changed to: '.(ServiceQ\Client::expires('dispatch',$expires)).' sec.');
                    break;

                case 'call-expires':

                    if(!isset($cmd[1]) OR $cmd[1]==='')
                    {
                        $this->_cli->debug('Default call expires set to: '.(float) ServiceQ\Client::expires('call').' sec.');
                        break;
                    }

                    $expires = (float) $cmd[1];

                    $this->_cli->debug('Default call expires has been changed to: '.(float) (ServiceQ\Client::expires('call',$expires)).' sec.');
                    break;

                case 'publish-expires':

                    if(!isset($cmd[1]) OR $cmd[1]==='')
                    {
                        $this->_cli->debug('Default publish expires set to: '.(float) ServiceQ\Client::expires('publish').' sec.');
                        break;
                    }

                    $expires = (float) $cmd[1];

                    $this->_cli->debug('Default publish expires has been changed to: '.(float) (ServiceQ\Client::expires('publish',$expires)).' sec.');
                    break;

                default:

                    $this->_cli->error('Support for command '.$cmd[0].' not released yet');

                    break;
            }
        }
    }

    public function _evalCommand($cmd)
    {
        $options = $this->_getOptions(explode(' ',$cmd));
        $this->_runCommand($cmd,$options);
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

            @touch($this->_historyFile);
            @readline_write_history($this->_historyFile);
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