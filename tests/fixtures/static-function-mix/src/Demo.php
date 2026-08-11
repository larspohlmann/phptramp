<?php

namespace Demo;

class Cfg
{
}

function entry(Cfg $config): void
{
    Middle::relay($config);
}

class Middle
{
    public static function relay(Cfg $config): void
    {
        new Sink($config);
    }
}

class Sink
{
    private Cfg $config;

    public function __construct(Cfg $config)
    {
        $this->config = $config;
    }
}
