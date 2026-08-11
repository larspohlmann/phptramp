<?php

namespace Demo;

class Cfg
{
}

class TwoHopOrigin
{
    public function run(Cfg $config): void
    {
        (new TwoHopMiddle())->relay($config);
    }
}

class TwoHopMiddle
{
    public function relay(Cfg $config): void
    {
        (new TwoHopTerminal())->consume($config);
    }
}

class TwoHopTerminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}

class ThreeHopOrigin
{
    public function run(Cfg $config): void
    {
        (new ThreeHopMiddleA())->relay($config);
    }
}

class ThreeHopMiddleA
{
    public function relay(Cfg $config): void
    {
        (new ThreeHopMiddleB())->relay($config);
    }
}

class ThreeHopMiddleB
{
    public function relay(Cfg $config): void
    {
        (new ThreeHopTerminal())->consume($config);
    }
}

class ThreeHopTerminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}
