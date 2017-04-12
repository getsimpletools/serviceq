<?php

namespace Simpletools\ServiceQ;

use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Simpletools\ServiceQ\Driver\QDriver;

class Client
{
    protected static $_settings;
    protected static $_queueTypeMapping;

    protected $_connection;
    protected $_channel;
    protected $_queue;
    protected $_type;

    protected $_rpcResponse;
    protected $_rpcCorrId;

    protected $_callTimeout = 90;
    protected $_request;

    public function timeout($seconds)
    {
        $this->_callTimeout = $seconds;
        return $this;
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

        $msg = new AMQPMessage(
            json_encode($args[0]),
            array(
                'correlation_id' => $this->_rpcCorrId,
                'reply_to' => $callback_queue
            )
        );

        $channel->basic_publish($msg, '', $this->_queue);
        while(!$this->_rpcResponse)
        {
            $channel->wait(null,false,$this->_callTimeout);
        }

        return $this->_rpcResponse;
    }

    public function _onRpcResponse($rep)
    {
        if($rep->get('correlation_id') == $this->_rpcCorrId) {
            $this->_rpcResponse = json_decode($rep->body);
        }
    }

    public function publish()
    {
        $args = func_get_args();
        if(count($args)===1)
        {
            if(!$this->_type) {

                if(isset($args[1])) {
                    $msg = new AMQPMessage(json_encode($args[0]),$args[1]);
                }else{
                    $msg = new AMQPMessage(json_encode($args[0]));
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
    }

    public function serve(Callable $callback)
    {
        $this->_channel->queue_declare($this->_queue, false, true, false, false);

        $this->_channel->basic_qos(null, 1, null);

        $response = new CallResponse($callback,$this);

        $this->_channel->basic_consume($this->_queue, '', false, false, false, false, array($response,'respond'));

        while(count($this->_channel->callbacks)) {
            $this->_channel->wait();
        }
    }

    public function setRequest($request)
    {
        if($this->_request) unset($this->_request);
        $this->_request = $request;
    }

    public function reply($msg)
    {
        $req = $this->_request;
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
        $msg = new AMQPMessage(json_encode($msg), $options);
        $req->delivery_info['channel']->basic_publish($msg, '', $req->get('reply_to'));

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
        $msg = new AMQPMessage($msg,$properties);

        $this->_channel->exchange_declare($this->_queue,'fanout',false,false,false);
        $this->_channel->basic_publish($msg, $this->_queue);
    }

    public function publishTopic($topic,$msg,$properties=null)
    {
        $msg = new AMQPMessage($msg,$properties);

        $this->_channel->exchange_declare($this->_queue,'topic',false,false,false);
        $this->_channel->basic_publish($msg, $this->_queue, $topic);
    }

    public function publishDirect($key,$msg,$properties=null)
    {
        $msg = new AMQPMessage($msg,$properties);

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

        $this->_connection = new AMQPStreamConnection(
            $settings['host'],
            $settings['port'],
            $settings['username'],
            $settings['password']
        );

        $this->_channel = $this->_connection->channel();
        $this->_queue = $queue;

        if(isset(self::$_queueTypeMapping[$queue]))
        {
            $this->type(self::$_queueTypeMapping[$queue]);
        }
    }

    public static function settings($settings)
    {
        self::$_settings = $settings;
    }

    public static function queueTypeMapping($queueTypeMapping)
    {
        self::$_queueTypeMapping = $queueTypeMapping;
    }

    public static function service($queue)
    {
        return new static(self::$_settings,$queue);
    }

    public function type($type)
    {
        $this->_type = $type;

        return $this;
    }

    public function __destruct()
    {
        $this->_channel->close();
        $this->_connection->close();
    }
}