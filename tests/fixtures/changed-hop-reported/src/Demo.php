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
        (new ServiceB())->run($config);
    }
}

class ServiceB
{
    public function run(Cfg $config): void
    {
        new Mailer($config);
    }
}

class Mailer
{
    private Cfg $config;

    public function __construct(Cfg $config)
    {
        $this->config = $config;
    }
}
