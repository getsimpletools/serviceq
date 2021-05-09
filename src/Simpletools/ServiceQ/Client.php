<?php

namespace Simpletools\ServiceQ;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Simpletools\ServiceQ\Driver\QDriver;

class Client
{
	protected static $_driver;
	protected static $_queueTypeMapping;
	protected static $_events = array();

	protected static $_isRunning;
	protected static $_signal;

	protected $_isServeService= false;
	protected static $_lastInstanceQueue ='';

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

	protected $_collectionChannels = array();
	protected $_collectionResponses = array();

	protected $_packages = array();

	/*
	 * Times are in seconds
	 * set to null to keep them till delivered - no expiration
	 */
	protected $_perMessageTtl                       = null;
	protected static $_S_perMessageTtl              = null;

	/*
	 * Times are in seconds
	 * set to null to keep them till delivered - no expiration
	 */
	protected $_perPublishMessageTtl                = null;
	protected static $_S_perPublishMessageTtl       = null;

	/*
	 * In theory should be instant but might take couple microseconds before sending message and starting to listen
	 * hence default is being set as 1sec.
	 */
	protected $_perReplyMessageTtl                  = 1;
	protected static $_S_perReplyMessageTtl         = 1;

	/*
	 * Dispatch / Collect assumes work in the mean time but channel is open almost immediately after sending
	 * message therefore virtually making it always delivered but in future we might move it to only open channel after
	 * collect method is triggered() hence defaults to 60sec.
	 */
	protected $_perDispatchReplyMessageTtl          = 60;
	protected static $_S_perDispatchReplyMessageTtl = 60;

	public static function expires($key,$value=null)
	{
		if($value!==null) {
			if($key=='call')
			{
				if($value===0) {
					$value = null;
				}

				return self::$_S_perMessageTtl = $value;
			}
			elseif($key=='publish')
			{
				if(!$value) {
					$value = null;
				}

				return self::$_S_perPublishMessageTtl = $value;
			}
			else if($key=='reply')
			{
				if(!$value)
				{
					throw new Exception('Reply expires can\'t be set to 0');
				}

				return self::$_S_perReplyMessageTtl = $value;
			}
			else if($key=='collect'){

				if(!$value)
				{
					throw new Exception('Collect expires can\'t be set to 0');
				}

				return self::$_S_perDispatchReplyMessageTtl = $value;
			}
			else if($key=='dispatch'){

				if(!$value)
				{
					throw new Exception('Dispatch expires can\'t be set to 0');
				}

				return self::$_S_perDispatchReplyMessageTtl = $value;
			}
		}
		else
		{
			if($key=='call')
			{
				return self::$_S_perMessageTtl;
			}
			elseif($key=='publish')
			{
				return self::$_S_perPublishMessageTtl;
			}
			elseif($key=='reply')
			{
				return self::$_S_perReplyMessageTtl;
			}
			elseif($key=='collect')
			{
				return self::$_S_perDispatchReplyMessageTtl;
			}
			elseif($key=='dispatch')
			{
				return self::$_S_perDispatchReplyMessageTtl;
			}
		}
	}


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

	protected function _preparePayload($msg,$method,$etc=array())
	{
		$payload                = array();
		$mTime                  = microtime(true);

		//https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
		$payload['status']          = (int) $this->_payloadStatus;
		$payload['statusMsg']       = self::getStatusText($this->_payloadStatus);

		$payload['meta']        = array(
			'creator'               => $this->_getTopmostScript(),
			'queue'                 => ['name'=>$this->_queue],
			'method'                => $method,
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

		if(isset($etc['expires']))
		{
			$payload['meta']['expires'] = (float) $etc['expires'];
		}

		if($this->_serviceQRequest)
		{
			$payload['meta']['servingTimeSec'] = $mTime - $this->_serviceQRequest->meta->date->mtimestamp;
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

	protected function _getExpiresFromProps($props,$defaultSec)
	{
		$expires = $defaultSec*1000;
		$expires = (isset($props['expires']) && $props['expires']) ? $props['expires']*1000 : $expires;
		$expires = (isset($props['expiration']) && $props['expiration']) ? $props['expiration'] : $expires; //legacy
		$expires = (int) $expires;

		return $expires;
	}

	protected function _preparePayloadProperties($properties,$correlationId=null,$replyTo=null,$method='CALL')
	{
		$props = array();

		if($correlationId)
		{
			$props['correlation_id'] = $correlationId;
		}

		if($replyTo)
		{
			$props['reply_to'] = $replyTo;
		}

		if(is_array($properties))
		{
			if(isset($properties['correlation_id']))
				unset($properties['correlation_id']);

			if(isset($properties['reply_to']))
				unset($properties['reply_to']);

			$props = array_merge($props,$properties);
		}

		$dExpires = $this->_perMessageTtl;
		if($method=='DISPATCH' OR $method=='COLLECT')
		{
			$dExpires = $this->_perDispatchReplyMessageTtl;
		}
		elseif($method=='PUBLISH')
		{
			$dExpires = $this->_perPublishMessageTtl;
		}
		elseif($method=='REPLY')
		{
			$dExpires = $this->_perReplyMessageTtl;
		}

		$props['expiration'] = $this->_getExpiresFromProps($properties,$dExpires);
		if(!$props['expiration'])
		{
			unset($props['expiration']);
		}

		if(isset($props['expires']))
		{
			unset($props['expires']);
		}

		return $props;
	}

	public function dispatch()
	{
		$args = func_get_args();

		$channelId = 'SQC'.uniqid();
		$this->_collectionChannels[$channelId] = 1;

		$payload    = $this->_preparePayload($args[0],'DISPATCH',[
			'expires'   => $this->_getExpiresFromProps(@$args[1],$this->_perDispatchReplyMessageTtl)/1000 //in sec
		]);
		$properties = $this->_preparePayloadProperties(@$args[1],$channelId,$channelId,'DISPATCH');

		$this->_channel->queue_declare($channelId, false, true, false, true, false, new AMQPTable(array(
			"x-expires" => $this->_getExpiresFromProps(@$args[1],$this->_perDispatchReplyMessageTtl) //in milliseconds
		)));

		$this->_channel->basic_publish(new AMQPMessage(
			$payload, $properties
		), '', $this->_queue);

		return (string) $channelId;
	}

	public function _collector($res)
	{
		$id = $res->get('correlation_id');

		$response               = json_decode($res->body);
		$this->_collectionResponses[$id] =  $response;

		$res->delivery_info['channel']->basic_ack($res->delivery_info['delivery_tag']);

		$this->_checkResponse($response);
	}


	public function collectNoWait()
	{
		$args = func_get_args();
		$packageId=@$args[0];

		if(isset($this->_packages[$packageId]))
		{
			if($this->_packages[$packageId]==410) {
				throw new Exception('Gone',410);
			}
			else {
				throw new Exception('This packageId has been already collected', 404);
			}
		}

		if(!$packageId)
		{
			$packageId = key($this->_collectionChannels);
			if(!$packageId)
			{
				throw new Exception('Please specify packageId',400);
			}
		}

		$this
			->status(200)
			->meta(null);

		try {

			$res = $this->_channel->basic_get($packageId,true);
			if(!$res) return null;

			$response               = json_decode($res->body);
			$this->_checkResponse($response);

			unset($this->_collectionChannels[$packageId]);

			$this->_packages[$packageId] = 1;

			$this->_channel->queue_delete($packageId);

			return $response;
		}
		catch(\Exception $e)
		{
			$this->_channel->close();
			$this->_channel = $this->_connection->channel();

			$this->_packages[$packageId] = 410;

			throw new Exception('Gone',410);
		}
	}

	public function collect()
	{
		$args = func_get_args();
		$packageId=@$args[0];

		if(isset($this->_packages[$packageId]))
		{
			throw new Exception('This packageId has been already collected',404);
		}

		if(!$packageId)
		{
			$packageId = key($this->_collectionChannels);
			if(!$packageId)
			{
				throw new Exception('Please specify packageId',400);
			}
		}

		try {
			$this->_channel->basic_consume(
				$packageId, $packageId, false, false, false, false,
				array($this, '_collector')
			);
		}
		catch(\Exception $e)
		{
			$this->_channel->close();
			$this->_channel = $this->_connection->channel();

			throw new Exception('Gone',410);
		}

		$this
			->status(200)
			->meta(null);

		$exception = null;

		try {
			$this->_channel->wait(null, false, $this->_callTimeout);
		}
		catch(\Exception $e)
		{
			$exception = $e;
		}

		if($exception) throw $exception;

		unset($this->_collectionChannels[$packageId]);
		$response = isset($this->_collectionResponses[$packageId]) ? $this->_collectionResponses[$packageId] : null;
		unset($this->_collectionResponses[$packageId]);

		if($response)
		{
			$this->_packages[$packageId] = 1;

			$this->_channel->basic_cancel($packageId);
			$this->_channel->queue_delete($packageId);
		}

		return $response;
	}

	public function call()
	{
		$this->_rpcResponse = null;

		$args = func_get_args();

		list($callback_queue, ,) = $this->_channel->queue_declare("", false, false, true, true);

		$this->_channel->basic_consume(
			$callback_queue, '', false, false, false, false,
			array($this, '_onRpcResponse')
		);

		$this->_rpcResponse = null;
		$this->_rpcCorrId   = uniqid();

		$properties = $this->_preparePayloadProperties(@$args[1],$this->_rpcCorrId,$callback_queue,'CALL');

		$msg = new AMQPMessage(
			$this->_preparePayload($args[0],'CALL'),
			$properties
		);

		$this->_channel->basic_publish($msg, '', $this->_queue);

		$this
			->status(200)
			->meta(null);

		try {
			while (!$this->_rpcResponse) {
				$this->_channel->wait(null, false, $this->_callTimeout);
			}
		}
		catch(\Exception $e)
		{
			throw $e;
		}

		return $this->_rpcResponse;
	}

	public function _onRpcResponse($response)
	{
		if($response->get('correlation_id') == $this->_rpcCorrId)
		{
			$this->_rpcResponse = json_decode($response->body);
			//$response->delivery_info['channel']->basic_ack($response->delivery_info['delivery_tag']);

			//$response->delivery_info['channel']->queue_delete($response->get('routing_key'));
			//$response->delivery_info['channel']->queue_delete($response->get('routing_key'),null);

			$response->delivery_info['channel']->close();
			$this->_channel = $this->_connection->channel();

			$this->_checkResponse($this->_rpcResponse);
		}
	}

	protected function _checkResponse($response)
	{
		if(substr(@$response->status,0,1)!=2)
		{
			$e = new ResponseException("",@$response->status);
			$e->setResponse($response);

			$bindings = Client::getBindings('onResponseException');

			foreach($bindings as $bind)
			{
				$bind($e,$this);
			}

			throw $e;
		}
	}

	public function publish()
	{
		$args = func_get_args();
		if(count($args)===1)
		{
			if(!$this->_type)
			{
				$msg = new AMQPMessage(
					$this->_preparePayload($args[0],'PUBLISH'),
					$this->_preparePayloadProperties(@$args[1],null,null,'PUBLISH')
				);

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

	public static function isRunning()
	{
		return self::$_isRunning;
	}


	public static function getShutdownSignal()
	{
		return self::$_signal;
	}

	public function serve(Callable $callback)
	{
		$this->_isServeService = true;

		$response = new CallResponse($callback,$this);

		if($this->_topic)
		{
			$this->_channel->exchange_declare($this->_queue, 'topic', false, false, false);
			$res = $this->_channel->queue_declare("", false, false, true, true);
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

				self::$_isRunning = false;
				$this->_runServiceCompletedBinders($this);

			}
		}
		catch(\Exception $e)
		{
			self::$_isRunning = false;
			$this->_runExceptionBinders($e);
			throw $e;
		}
	}

	protected function _runExceptionBinders($e)
	{
		$bindings = Client::getBindings('onException');
		if($bindings)
		{
			foreach($bindings as $bind) {
				$bind($e, $this);
			}
		}
	}

	protected function _runServiceStartBinders($service)
	{
		$bindings = Client::getBindings('onServiceStart');
		if($bindings)
		{
			foreach($bindings as $bind) {
				$bind($service);
			}
		}
	}

	protected function _runServiceCompletedBinders($service)
	{
		$bindings = Client::getBindings('onServiceCompleted');
		if($bindings)
		{
			foreach($bindings as $bind) {
				$bind($service);
			}
		}
	}

	public static function runServiceShutdownBinders($signal=null)
	{
		if($signal)
			self::$_signal = $signal;

		$bindings = Client::getBindings('onServiceShutdown');
		if($bindings)
		{
			foreach($bindings as $bind) {
				$bind($signal);
			}
		}
	}

	public function setRequest($request)
	{
		if($this->_request) unset($this->_request);
		$this->_request = $request;


		if($this->_isServeService)
		{
			self::$_isRunning = true;
			$this->_runServiceStartBinders($this);
		}

		return $this;
	}

	public function setServiceQRequest($serviceQRequest)
	{
		if($this->_serviceQRequest) unset($this->_serviceQRequest);
		$this->_serviceQRequest = $serviceQRequest;

		return $this;
	}

	public function reply($msg,$properties=array())
	{
		$req = $this->_request;
		if(!$req) return $this;

		try {
			$req->get('reply_to');
			$req->get('correlation_id');
		}
		catch(\Exception $e){return $this;}

		$correlation_id = null;

		if($req->get('correlation_id'))
		{
			$correlation_id = $req->get('correlation_id');
		}

		$body = json_decode($req->body);

		$method = "REPLY";
		if(@$body->meta->method=="DISPATCH")
		{
			$method                 = "DISPATCH";

			if(isset($body->meta->expires))
				$properties['expires']  = $body->meta->expires;
		}

		$properties = $this->_preparePayloadProperties($properties,$correlation_id,null,$method);

		$msg = new AMQPMessage(
			$this->_preparePayload($msg,'REPLY'),
			$properties
		);

		$req->delivery_info['channel']->basic_publish($msg, '', $req->get('reply_to'));

		$this
			->status(200)
			->meta(null);

		return $this;
	}

	public function acknowledge()
	{
		if(!$this->_request) throw new Exception("This message has been already acknowledged",409);

		$req = $this->_request;
		$req->delivery_info['channel']->basic_ack($req->delivery_info['delivery_tag']);

		unset($this->_request);
		$this->_request = null;

		return $this;
	}

	public function publishFanout($msg,$properties=null)
	{
		$msg = new AMQPMessage(
			$this->_preparePayload($msg,'PUBLISH_FANOUT'),
			$this->_preparePayloadProperties($properties)
		);

		$this->_channel->exchange_declare($this->_queue,'fanout',false,false,false);
		$this->_channel->basic_publish($msg, $this->_queue);
	}

	public function publishTopic($topic,$msg,$properties=null)
	{
		$msg = new AMQPMessage(
			$this->_preparePayload($msg,'PUBLISH_TOPIC',['topic'=>$topic]),
			$this->_preparePayloadProperties($properties)
		);

		$this->_channel->exchange_declare($this->_queue,'topic',false,false,false);
		$this->_channel->basic_publish($msg, $this->_queue, $topic);
	}

	public function publishDirect($key,$msg,$properties=null)
	{
		$msg = new AMQPMessage(
			$this->_preparePayload($msg,'PUBLISH_DIRECT'),
			$this->_preparePayloadProperties($properties)
		);

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

		try {
			if (isset($host['sslOptions']) && $host['proto'] == 'ampqs') {
				if (!is_array($host['sslOptions'])) {
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
			} else {
				$this->_connection = new AMQPStreamConnection(
					$host['host'],
					@$settings['port'],
					@$settings['username'],
					@$settings['password'],
					isset($settings['vhost']) ? $settings['vhost'] : '/'
				);
			}
		}
		catch(\Exception $e)
		{
			$this->_runExceptionBinders($e);

			throw $e;
		}

		$this->_channel = $this->_connection->channel();
		$this->_queue = $queue;

        self::$_lastInstanceQueue   = $this->_queue;

		if(isset(self::$_queueTypeMapping[$queue]))
		{
			$this->type(self::$_queueTypeMapping[$queue]);
		}

		$this->_perMessageTtl               = &self::$_S_perMessageTtl;
		$this->_perReplyMessageTtl          = &self::$_S_perReplyMessageTtl;
		$this->_perDispatchReplyMessageTtl  = &self::$_S_perDispatchReplyMessageTtl;
		$this->_perPublishMessageTtl        = &self::$_S_perPublishMessageTtl;
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

	protected $_cli;

	public function cli()
	{
		if(!$this->_cli)
		{
			$this->_cli = new Cli();
		}

		return $this->_cli;
	}

	public static function getStatusText($code)
	{
		$code = (int) $code;

		switch ($code) {
			case 100: $text = 'Continue'; break;
			case 101: $text = 'Switching Protocols'; break;
			case 200: $text = 'OK'; break;
			case 201: $text = 'Created'; break;
			case 202: $text = 'Accepted'; break;
			case 203: $text = 'Non-Authoritative Information'; break;
			case 204: $text = 'No Content'; break;
			case 205: $text = 'Reset Content'; break;
			case 206: $text = 'Partial Content'; break;
			case 300: $text = 'Multiple Choices'; break;
			case 301: $text = 'Moved Permanently'; break;
			case 302: $text = 'Moved Temporarily'; break;
			case 303: $text = 'See Other'; break;
			case 304: $text = 'Not Modified'; break;
			case 305: $text = 'Use Proxy'; break;
			case 400: $text = 'Bad Request'; break;
			case 401: $text = 'Unauthorized'; break;
			case 402: $text = 'Payment Required'; break;
			case 403: $text = 'Forbidden'; break;
			case 404: $text = 'Not Found'; break;
			case 405: $text = 'Method Not Allowed'; break;
			case 406: $text = 'Not Acceptable'; break;
			case 407: $text = 'Proxy Authentication Required'; break;
			case 408: $text = 'Request Time-out'; break;
			case 409: $text = 'Conflict'; break;
			case 410: $text = 'Gone'; break;
			case 411: $text = 'Length Required'; break;
			case 412: $text = 'Precondition Failed'; break;
			case 413: $text = 'Request Entity Too Large'; break;
			case 414: $text = 'Request-URI Too Large'; break;
			case 415: $text = 'Unsupported Media Type'; break;
			case 500: $text = 'Internal Server Error'; break;
			case 501: $text = 'Not Implemented'; break;
			case 502: $text = 'Bad Gateway'; break;
			case 503: $text = 'Service Unavailable'; break;
			case 504: $text = 'Gateway Time-out'; break;
			case 505: $text = 'HTTP Version not supported'; break;
			default: $text = 'Unknown http status code'; break;
		}

		return $text;
	}

	public function __call($name, $arguments)
	{
		if($name ==='getShutdownSignal')
		{
			return self::getShutdownSignal();
		}
		elseif($name ==='isRunning')
		{
			return self::isRunning();
		}
		else
			throw new \Exception('Called undefined method ('.$name.')');

	}

	public function queue()
    {
        return $this->_queue;
    }

    public static function lastInstanceQueue()
    {
        return self::$_lastInstanceQueue;
    }
}