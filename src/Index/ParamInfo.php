<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * A single method parameter with its structural facts and classified fate.
 */
final class ParamInfo
{
    /**
     * @param list<ForwardSite> $forwards
     */
    public function __construct(
        public readonly string $name,
        public readonly int $position,
        public readonly ParamFate $fate,
        public readonly array $forwards,
        public readonly bool $byRef,
        public readonly bool $variadic,
        public readonly ?string $type,
    ) {
    }
}
