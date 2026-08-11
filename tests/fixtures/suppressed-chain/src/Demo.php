<?php

namespace Demo;

use PhpTramp\Ignore\TrampIgnore;

class Cfg
{
}

class AttributeSuppressedOrigin
{
    #[TrampIgnore]
    public function run(Cfg $config): void
    {
        (new AttributeSuppressedTerminal())->consume($config);
    }
}

class AttributeSuppressedTerminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}

class CommentSuppressedOrigin
{
    public function run(Cfg $config): void
    {
        (new CommentSuppressedTerminal())->consume($config); // phptramp-ignore
    }
}

class CommentSuppressedTerminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}
