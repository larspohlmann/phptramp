<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The English singular/plural word choice shared by every reporter that
 * counts hops, classes, or findings. Centralised so the pluralization rule
 * (and any irregular form) is defined exactly once.
 */
final class Pluralizer
{
    public function of(int $count, string $singular, ?string $plural = null): string
    {
        if ($count === 1) {
            return $singular;
        }

        return $plural ?? $singular . 's';
    }
}
