<?php

namespace Simpletools\ServiceQ;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Simpletools\ServiceQ\Driver\QDriver;

class Client
{
    protected static $_driver;
    protected static $_queueTypeMapping;
    protected static $_events = array();

    protected $_connection;
    protected $_channel;
    protected $_queue;
    protected $_type;
    protected $_topic;

    protected $_rpcResponse;
    protected $_rpcCorrId;

    protected $_callTimeout = 90;
    protected $_request;

    protected $_serviceQRequest;

    protected $_payloadStatus = 200;
    protected $_payloadMeta;

    public function timeout($seconds)
    {
        $this->_callTimeout = $seconds;
        return $this;
    }

    public function status($code)
    {
        $this->_payloadStatus = $code;
        return $this;
    }

    public function meta($meta)
    {
        $this->_payloadMeta = $meta;
        return $this;
    }

    protected function _preparePayload($msg,$etc=array())
    {
        $payload                = array();
        $mTime                  = microtime(true);

        //https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
        $payload['status']      = (int) $this->_payloadStatus;

        $payload['meta']        = array(
            'creator'               => $this->_getTopmostScript(),
            'queue'                 => ['name'=>$this->_queue],
            'date'                  => [
                'atom'                  => date(DATE_ATOM),
                'mtimestamp'            => $mTime,
                'timestamp'             => time()
            ]
        );

        if(isset($etc['topic']))
        {
            $payload['meta']['queue']['topic'] = $etc['topic'];
        }

        if($this->_serviceQRequest)
        {
            $payload['meta']['servingTimeMicroSec'] = $mTime - $this->_serviceQRequest->meta->date->mtimestamp;
        }

        if($this->_payloadMeta)
            $payload['meta'] = array_merge($payload['meta'],$this->_payloadMeta);

        $payload['body']    = $msg;

        return json_encode($payload);
    }

    protected function _getTopmostScript()
    {
        $backtrace = debug_backtrace(
            defined("DEBUG_BACKTRACE_IGNORE_ARGS")
                ? DEBUG_BACKTRACE_IGNORE_ARGS
                : FALSE);
        $top_frame = array_pop($backtrace);
        return $top_frame['file'];
    }

    public function call()
    {
        $args = func_get_args();

        $channel = $this->_connection->channel();

        list($callback_queue, ,) = $channel->queue_declare("", false, false, true, false);

        $channel->basic_consume(
            $callback_queue, '', false, false, false, false,
            array($this, '_onRpcResponse')
        );

        $this->_rpcResponse = null;
        $this->_rpcCorrId   = uniqid();

        $properties = array(
            'correlation_id' => $this->_rpcCorrId,
            'reply_to' => $callback_queue
        );

        if(isset($args[1]) && is_array($args[1]))
        {
            $properties = array_merge($args[1],$properties);
        }

        $msg = new AMQPMessage(
            $this->_preparePayload($args[0]),
            $properties
        );

        $channel->basic_publish($msg, '', $this->_queue);

        $this
            ->status(200)
            ->meta(null);

        while(!$this->_rpcResponse)
        {
            $channel->wait(null,false,$this->_callTimeout);
        }

        return $this->_rpcResponse;
    }

    public function _onRpcResponse($rep)
    {
        if($rep->get('correlation_id') == $this->_rpcCorrId) {

            $response           = json_decode($rep->body);
            $this->_rpcResponse = $response;

            if(substr(@$this->_rpcResponse->status,0,1)!=2)
            {
                $e = new ResponseException("",@$this->_rpcResponse->status);
                $e->setResponse($response);

                $bindings = Client::getBindings('onResponseException');

                foreach($bindings as $bind)
                {
                    $bind($e,$this);
                }

                throw $e;
            }
        }
    }

    public function publish()
    {
        $args = func_get_args();
        if(count($args)===1)
        {
            if(!$this->_type) {

                $msg = $this->_preparePayload($args[0]);

                if(isset($args[1])) {
                    $msg = new AMQPMessage($msg,$args[1]);
                }else{
                    $msg = new AMQPMessage($msg);
                }

                $this->_channel->queue_declare($this->_queue, false, true, false, false);
                $this->_channel->basic_publish($msg, '', $this->_queue);
            }
            elseif($this->_type=='fanout')
            {
                $this->publishFanout($args[0],@$args[1]);
            }
        }
        elseif(count($args)===2)
        {
            if($this->_type=='topic')
            {
                $this->publishTopic($args[0],$args[1],@$args[2]);
            }
            elseif($this->_type=='direct')
            {
                $this->publishDirect($args[0],$args[1],@$args[2]);
            }
        }

        $this
            ->status(200)
            ->meta(null);
    }

    public function serve(Callable $callback)
    {
        $response = new CallResponse($callback,$this);

        if($this->_topic)
        {
            $this->_channel->exchange_declare($this->_queue, 'topic', false, false, false);
            $res = $this->_channel->queue_declare("", false, false, true, false);
            $queue_name = $res[0];

            foreach($this->_topic as $topic)
                $this->_channel->queue_bind($queue_name, $this->_queue, $topic);

            $this->_channel->basic_consume($queue_name, '', false, true, false, false, array($response,'respond'));
        }
        else
        {
            $this->_channel->queue_declare($this->_queue, false, true, false, false);
            $this->_channel->basic_qos(null, 1, null);
            $this->_channel->basic_consume($this->_queue, '', false, false, false, false, array($response,'respond'));
        }

        try {
            while (count($this->_channel->callbacks)) {
                $this->_channel->wait();
            }
        }
        catch(\Exception $e)
        {
            $bindings = Client::getBindings('onException');
            if($bindings)
            {
                foreach($bindings as $bind) {
                    $bind($e, $this);
                }
            }

            throw $e;
        }
    }

    public function setRequest($request)
    {
        if($this->_request) unset($this->_request);
        $this->_request = $request;

        return $this;
    }

    public function setServiceQRequest($serviceQRequest)
    {
        if($this->_serviceQRequest) unset($this->_serviceQRequest);
        $this->_serviceQRequest = $serviceQRequest;

        return $this;
    }

    public function reply($msg)
    {
        $req = $this->_request;
        if(!$req) return $this;

        try {
            $req->get('reply_to');
            $req->get('correlation_id');
        }
        catch(\Exception $e){return $this;}

        $options = [];


        if($req->get('correlation_id'))
        {
            $options['correlation_id'] = $req->get('correlation_id');
        }
        $msg = new AMQPMessage($this->_preparePayload($msg), $options);
        $req->delivery_info['channel']->basic_publish($msg, '', $req->get('reply_to'));

        $this
            ->status(200)
            ->meta(null);

        return $this;
    }

    public function acknowledge()
    {
        $req = $this->_request;
        $req->delivery_info['channel']->basic_ack($req->delivery_info['delivery_tag']);

        return $this;
    }

    public function publishFanout($msg,$properties=null)
    {
        $msg = new AMQPMessage($this->_preparePayload($msg),$properties);

        $this->_channel->exchange_declare($this->_queue,'fanout',false,false,false);
        $this->_channel->basic_publish($msg, $this->_queue);
    }

    public function publishTopic($topic,$msg,$properties=null)
    {
        $msg = new AMQPMessage($this->_preparePayload($msg,['topic'=>$topic]),$properties);

        $this->_channel->exchange_declare($this->_queue,'topic',false,false,false);
        $this->_channel->basic_publish($msg, $this->_queue, $topic);
    }

    public function publishDirect($key,$msg,$properties=null)
    {
        $msg = new AMQPMessage($this->_preparePayload($msg),$properties);

        $this->_channel->exchange_declare($this->_queue,'direct',false,false,false);
        $this->_channel->basic_publish($msg, $this->_queue, $key);
    }

    public function __construct($driver,$queue)
    {
        if(!$driver instanceof QDriver)
        {
            throw new Exception('Please specify a driver implementing QDriver interface');
        }

        $settings = $driver->getSettings();

        $host = explode('://',@$settings['host']);
        if(!isset($host[1]))
        {
            $host['proto']  = 'ampqs';
            $host['host']   = $host[0];
        }
        else
        {
            $host['proto']  = $host[0];
            if(!$host['proto']) $host['proto'] = 'ampqs';
            $host['host']   = $host[1];
        }

        /*
         * Default protocol - SSL
         */
        if(isset($host['sslOptions']) && $host['proto']=='ampqs')
        {
            if(!is_array($host['sslOptions']))
            {
                throw new Exception('sslOptions should be an array type');
            }

            $this->_connection = new AMQPSSLConnection(
                $host['host'],
                @$settings['port'],
                @$settings['username'],
                @$settings['password'],
                isset($settings['vhost']) ? $settings['vhost'] : '/',
                $host['sslOptions']
            );
        }
        else
        {
            $this->_connection = new AMQPStreamConnection(
                $host['host'],
                @$settings['port'],
                @$settings['username'],
                @$settings['password'],
                isset($settings['vhost']) ? $settings['vhost'] : '/'
            );
        }

        $this->_channel = $this->_connection->channel();
        $this->_queue = $queue;

        if(isset(self::$_queueTypeMapping[$queue]))
        {
            $this->type(self::$_queueTypeMapping[$queue]);
        }
    }

    public static function driver($driver)
    {
        if(!$driver instanceof QDriver)
        {
            throw new Exception('Please specify a driver implementing QDriver interface');
        }

        self::$_driver = $driver;
    }

    public static function queueTypeMapping($queueTypeMapping)
    {
        self::$_queueTypeMapping = $queueTypeMapping;
    }

    public static function onRequestReceived($callable)
    {
        self::bind('onRequestReceived',$callable);
    }

    public static function bind($event,$callable)
    {
        if(!is_callable($callable))
            throw new Exception('Your onRequestReceived function is not callable');

        self::$_events[$event][] = $callable;
        end(self::$_events[$event]);

        $key = key(self::$_events[$event]);
        reset(self::$_events[$event]);
        return $key;
    }

    public static function getBindings($event)
    {
        return isset(self::$_events[$event]) ? self::$_events[$event] : array();
    }

    public static function service($queue)
    {
        return new static(self::$_driver,$queue);
    }

    public function type($type)
    {
        $this->_type = $type;

        return $this;
    }

    public function topic($topic)
    {
        if(!is_array($topic)) $topic = [$topic];

        $this->_topic = $topic;

        return $this;
    }

    public function __destruct()
    {
        $this->_channel->close();
        $this->_connection->close();
    }
}