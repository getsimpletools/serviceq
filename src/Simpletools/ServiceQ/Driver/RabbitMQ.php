<?php

namespace Simpletools\ServiceQ\Driver;

class RabbitMQ implements QDriver
{
    protected $_settings;

    public function __construct($settings)
    {
        $this->_settings = $settings;
    }

    public function getSettings()
    {
        return $this->_settings;
    }
}