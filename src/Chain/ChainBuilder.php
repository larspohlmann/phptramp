<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

use PhpTramp\Index\MethodIndex;
use PhpTramp\Resolve\CallResolver;

/**
 * Walks the forward graph of an index and emits every maximal tramp-data chain,
 * unfiltered — thresholding against `--limit` is the caller's job. Each call to
 * {@see build()} runs an independent {@see ChainTraversal} so the builder itself
 * stays stateless and re-usable.
 */
final class ChainBuilder
{
    public function __construct(private readonly CallResolver $resolver)
    {
    }

    /**
     * @return list<Finding>
     */
    public function build(MethodIndex $index): array
    {
        return (new ChainTraversal($index, $this->resolver))->run();
    }
}
