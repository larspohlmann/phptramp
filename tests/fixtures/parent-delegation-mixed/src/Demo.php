<?php

namespace Demo;

class Cfg
{
}

class BaseHandler
{
    public function __construct(Cfg $config)
    {
        (new Mailer())->send($config);
    }
}

class SpecificHandler extends BaseHandler
{
    public function __construct(Cfg $config)
    {
        parent::__construct($config);
    }
}

class Mailer
{
    private ?Cfg $config = null;

    public function send(Cfg $config): void
    {
        $this->config = $config;
    }
}
