<?php

namespace Demo;

class Cfg
{
}

class Controller
{
    public function handle(Cfg $config): void
    {
        (new ServiceA())->process($config);
    }
}

class ServiceA
{
    public function process(Cfg $config): void
    {
        (new Mailer())->send($config);
    }
}

class Mailer
{
    private Cfg $config;

    public function send(Cfg $config): void
    {
        $this->config = $config;
    }
}
