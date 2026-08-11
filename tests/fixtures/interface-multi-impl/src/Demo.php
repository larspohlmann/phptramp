<?php

namespace Demo;

class Cfg
{
}

interface Handler
{
    public function handle(Cfg $config): void;
}

class HandlerA implements Handler
{
    public function handle(Cfg $config): void
    {
        $config->a();
    }
}

class HandlerB implements Handler
{
    public function handle(Cfg $config): void
    {
        $config->b();
    }
}

class Controller
{
    public function run(Handler $handler, Cfg $config): void
    {
        $handler->handle($config);
    }
}
