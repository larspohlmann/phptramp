<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * Thrown when one or more input files could not be parsed. The message lists
 * every offending file so the caller can report them and exit 2.
 */
final class ParseException extends \RuntimeException
{
}
