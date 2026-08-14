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

class Controller2
{
    public function handle(Cfg $config): void
    {
        (new ServiceA2())->process($config);
    }
}

class ServiceA2
{
    public function process(Cfg $config): void
    {
        (new ServiceB2())->run($config);
    }
}

class ServiceB2
{
    public function run(Cfg $config): void
    {
        new Mailer2($config);
    }
}

class Mailer2
{
    private Cfg $config;

    public function __construct(Cfg $config)
    {
        $this->config = $config;
    }
}
