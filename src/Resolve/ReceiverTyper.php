<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

use PhpTramp\Index\MethodInfo;

/**
 * Turns a method call's receiver hint into the FQCN the receiver is typed as, or
 * null when it cannot be typed to a single class. Parameter names are matched
 * before class names on purpose: a hint is ambiguous, and a parameter in the
 * caller's own signature is the closer binding.
 */
final class ReceiverTyper
{
    private const BUILTIN_TYPES = [
        'int', 'float', 'string', 'bool', 'array', 'object', 'mixed',
        'callable', 'iterable', 'void', 'null', 'false', 'true', 'never',
        'self', 'static', 'parent',
    ];

    public function type(?string $hint, MethodInfo $caller): ?string
    {
        if ($hint === 'this') {
            return $caller->class;
        }

        if ($hint === null || $hint === 'raw') {
            return null;
        }

        $param = $caller->paramNamed($hint);
        if ($param !== null) {
            return $this->singleClassType($param->type);
        }

        return $hint;
    }

    private function singleClassType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if (in_array(strtolower($type), self::BUILTIN_TYPES, true)) {
            return null;
        }

        return $type;
    }
}
