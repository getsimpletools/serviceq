<?php

namespace Simpletools\ServiceQ;

class CallResponse
{
    protected $_callback;
    protected $_client;

    public function __construct(Callable $callback, Client $client)
    {
        $this->_callback    = $callback;
        $this->_client      = $client;
    }

    public function respond($request)
    {
        $this->_client->setRequest($request);

        $request->body = json_decode($request->body);
        $callback = $this->_callback;

        $callback($request,$this->_client);
    }
}