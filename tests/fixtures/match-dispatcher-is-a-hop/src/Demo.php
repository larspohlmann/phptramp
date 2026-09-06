<?php

namespace Demo;

class Dispatcher
{
    public function toDto(string $kind, array $line): object
    {
        return match ($kind) {
            'foo' => FooLine::fromLine($line),
            'bar' => BarLine::fromLine($line),
        };
    }
}

class FooLine
{
    public function __construct(private string $x)
    {
    }

    public static function fromLine(array $line): self
    {
        return new self($line['x']);
    }
}

class BarLine
{
    public function __construct(private string $x)
    {
    }

    public static function fromLine(array $line): self
    {
        return new self($line['x']);
    }
}
