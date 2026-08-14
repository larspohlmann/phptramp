<?php

namespace Demo;

class BaseException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

class MiddleException extends BaseException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct($detail, $previous);
    }
}

class SpecificException extends MiddleException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('specific failure', $previous);
    }
}
