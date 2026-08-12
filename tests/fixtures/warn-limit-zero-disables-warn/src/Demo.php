<?php

namespace Demo;

class Cfg
{
}

class Origin
{
    public function run(Cfg $config): void
    {
        (new Hop1())->step($config);
    }
}

class Hop1
{
    public function step(Cfg $config): void
    {
        (new Hop2())->step($config);
    }
}

class Hop2
{
    public function step(Cfg $config): void
    {
        (new Hop3())->step($config);
    }
}

class Hop3
{
    public function step(Cfg $config): void
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
