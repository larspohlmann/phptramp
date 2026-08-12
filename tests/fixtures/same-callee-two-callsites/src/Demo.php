<?php

namespace Demo;

class Cfg
{
}

class Origin
{
    public function run(Cfg $config): void
    {
        (new Middle())->relay($config);
    }
}

class Middle
{
    public function relay(Cfg $config): void
    {
        (new Divergent())->forwardTwice($config);
    }
}

class Divergent
{
    public function forwardTwice(Cfg $config): void
    {
        Terminal::first($config);
        Terminal::second($config);
    }
}

class Terminal
{
    public static function first(Cfg $config): void
    {
        $config->go();
    }

    public static function second(Cfg $config): void
    {
        $config->go();
    }
}
