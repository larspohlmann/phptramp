<?php

namespace Demo;

class Cfg
{
}

class Outer
{
    public function run(Cfg ...$args): void
    {
        (new Middle())->run(...$args);
    }
}

class Middle
{
    public function run(Cfg ...$args): void
    {
        (new Sink())->consume(...$args);
    }
}

class Sink
{
    public function consume(Cfg ...$args): void
    {
        foreach ($args as $arg) {
            $arg->go();
        }
    }
}
