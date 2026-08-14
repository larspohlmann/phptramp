<?php

namespace Demo;

class Cfg
{
}

class Origin
{
    public function run(Cfg $config): void
    {
        (new Terminal())->consume($config);
    }
}

class Terminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}
