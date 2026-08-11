<?php

namespace Demo;

class Cfg
{
}

class IncludedOrigin
{
    public function run(Cfg $config): void
    {
        (new IncludedTerminal())->consume($config);
    }
}

class IncludedTerminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}
