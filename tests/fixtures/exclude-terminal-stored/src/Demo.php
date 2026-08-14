<?php

namespace Demo;

class Cfg
{
}

class StoredController
{
    public function handle(Cfg $config): void
    {
        (new Mailer())->deliver($config);
    }
}

class Mailer
{
    private ?Cfg $config = null;

    public function deliver(Cfg $config): void
    {
        $this->config = $config;
    }
}

class UsedController
{
    public function handle(Cfg $config): void
    {
        (new Logger())->write($config);
    }
}

class Logger
{
    public function write(Cfg $config): void
    {
        $config->flush();
    }
}
