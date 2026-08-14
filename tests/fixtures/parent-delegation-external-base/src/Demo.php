<?php

namespace Demo;

class ThinException extends \RuntimeException
{
    public function __construct(?\Throwable $previous)
    {
        parent::__construct('boom', 0, $previous);
    }
}

class Controller
{
    public function handle(?\Throwable $previous): void
    {
        throw new ThinException($previous);
    }
}
