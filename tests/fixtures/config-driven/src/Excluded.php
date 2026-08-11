<?php

namespace Demo;

class ExcludedCfg
{
}

class ExcludedOrigin
{
    public function run(ExcludedCfg $config): void
    {
        (new ExcludedTerminal())->consume($config);
    }
}

class ExcludedTerminal
{
    public function consume(ExcludedCfg $config): void
    {
        $config->go();
    }
}
