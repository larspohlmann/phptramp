<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * How a single parameter is treated inside the body of one method.
 *
 * - PureForward: every occurrence is a whole-argument forward to another call.
 * - Used: at least one occurrence is a real use (read, write, return, ...).
 * - ByRefTerminated: declared `&$p`; the method may write to it, so never a hop.
 * - Unused: the parameter is never mentioned in the body.
 */
enum ParamFate
{
    case PureForward;
    case Used;
    case ByRefTerminated;
    case Unused;
}
