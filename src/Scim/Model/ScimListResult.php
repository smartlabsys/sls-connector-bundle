<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim\Model;

/**
 * One page of a SCIM list.
 *
 * @template T of ScimUser|ScimGroup
 */
final class ScimListResult
{
    /**
     * @param list<T> $resources    the page (at most `count` items, starting at `startIndex`)
     * @param int     $totalResults total matching the filter
     */
    public function __construct(
        public readonly array $resources,
        public readonly int $totalResults,
    ) {}
}
