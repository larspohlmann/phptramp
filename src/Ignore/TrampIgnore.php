<?php

declare(strict_types=1);

namespace PhpTramp\Ignore;

/**
 * Marks a class, method, function, or parameter as exempt from tramp-data
 * reporting. Analyzed codebases are not required to autoload this class:
 * matching is by the attribute name's short name (see IndexingVisitor).
 */
#[\Attribute(
    \Attribute::TARGET_CLASS
    | \Attribute::TARGET_METHOD
    | \Attribute::TARGET_FUNCTION
    | \Attribute::TARGET_PARAMETER
)]
final class TrampIgnore
{
}
