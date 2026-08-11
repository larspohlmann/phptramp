<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * One indexed function or method: its fully-qualified name, source location,
 * classified parameters, and (for methods) the declaring class FQCN.
 */
final class MethodInfo
{
    /**
     * @param list<ParamInfo> $params
     */
    public function __construct(
        public readonly string $fqmn,
        public readonly string $file,
        public readonly int $line,
        public readonly array $params,
        public readonly ?string $class = null,
    ) {
    }
}
