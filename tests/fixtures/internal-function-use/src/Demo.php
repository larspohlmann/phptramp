<?php

namespace Demo;

class Controller
{
    public function handle(string $email): void
    {
        (new Normalizer())->normalize($email);
    }
}

class Normalizer
{
    public function normalize(string $email): string
    {
        return trim($email);
    }
}
