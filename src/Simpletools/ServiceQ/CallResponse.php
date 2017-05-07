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
        $req                = json_decode($request->body);

        $this->_client
            ->setRequest($request)
            ->setServiceQRequest($req);


        $bindings = Client::getBindings('onRequestReceived');
        if($bindings)
        {
            foreach($bindings as $bind) {
                $bind($req, $this->_client);
            }
        }

        $callback = $this->_callback;
        $callback($req,$this->_client);
    }
}