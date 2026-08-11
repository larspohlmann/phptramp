<?php

namespace Demo;

class Cfg
{
}

interface Handler
{
    public function handle(Cfg $config): void;
}

class RealHandler implements Handler
{
    public function handle(Cfg $config): void
    {
        $config->send();
    }
}

class Controller
{
    public function run(Handler $handler, Cfg $config): void
    {
        $handler->handle($config);
    }
}
