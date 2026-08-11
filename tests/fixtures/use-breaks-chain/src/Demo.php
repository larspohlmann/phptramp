<?php

namespace Demo;

class Cfg
{
}

class Controller
{
    public function handle(Cfg $config): void
    {
        (new Service())->process($config);
    }
}

class Service
{
    public function process(Cfg $config): void
    {
        $config->send();
    }
}
