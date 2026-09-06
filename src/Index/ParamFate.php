<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * How a single parameter is treated inside the body of one method.
 *
 * - PureForward: every occurrence is a whole-argument forward to another call.
 * - FanOut: every occurrence is a whole-argument forward, but to two or more
 *   distinct callees on one execution path — the method reads the value by
 *   combining what the callees return, so it is a terminal use, not a hop
 *   (issue #26).
 * - Used: at least one occurrence is a real use (read, write, return, ...).
 * - ByRefTerminated: declared `&$p`; the method may write to it, so never a hop.
 * - Unused: the parameter is never mentioned in the body.
 */
enum ParamFate
{
    case PureForward;
    case FanOut;
    case Used;
    case ByRefTerminated;
    case Unused;
}
