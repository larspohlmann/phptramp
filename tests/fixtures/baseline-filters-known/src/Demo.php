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

class Origin
{
    public function run(Cfg $payload): void
    {
        (new Mid())->pass($payload);
    }
}

class Mid
{
    public function pass(Cfg $payload): void
    {
        (new Sink())->consume($payload);
    }
}

class Sink
{
    public function consume(Cfg $payload): void
    {
        $payload->go();
    }
}
