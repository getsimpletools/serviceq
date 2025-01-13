<?php

namespace Simpletools\ServiceQ;

class ResponseException extends \Exception
{
    protected $_response;

    public function __construct($message = "", $code = 0, Throwable|null $previous = null)
    {
        if(!$message)
        {
            $message = Client::getStatusText($code);
        }

        parent::__construct($message, $code, $previous);
    }

    public function setResponse($response)
    {
        $this->_response = $response;
    }

    public function getResponse()
    {
        return $this->_response;
    }
}