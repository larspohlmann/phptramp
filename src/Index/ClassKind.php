<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * The declaration shape of a class-like symbol. The resolver uses it to tell a
 * concrete class the chain can enter from an interface/abstract type that needs
 * an implementation lookup.
 */
enum ClassKind
{
    case ConcreteClass;
    case AbstractClass;
    // Trailing underscores mirror php-parser's node names (Interface_, Trait_, Enum_).
    case Interface_;
    case Trait_;
    case Enum_;
}
