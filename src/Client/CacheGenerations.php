<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Generation markers for groups of cache entries whose keys can't be listed (0.3.3): an entry's
 * key carries the current marker of its group, and {@see self::bump()} drops the marker, so every
 * entry stored under the old one is never read again and expires on its own. Works with any PSR-6
 * pool (no tags needed). A marker lost to eviction has the same effect as a bump.
 *
 * @internal
 */
final class CacheGenerations
{
    private const PREFIX = 'sls_connector.generation.';

    public function __construct(private CacheItemPoolInterface $cache) {}

    /** The group's current marker: a random string, made (and kept) on first use. */
    public function current(string $group): string
    {
        $item = $this->cache->getItem(self::PREFIX . sha1($group));
        if ($item->isHit() && is_string($item->get()) && $item->get() !== '') {
            return $item->get();
        }
        $marker = bin2hex(random_bytes(8));
        $this->cache->save($item->set($marker));

        return $marker;
    }

    /** Start a new generation: entries keyed with the old marker are unreachable from now on. */
    public function bump(string $group): void
    {
        $this->cache->deleteItem(self::PREFIX . sha1($group));
    }
}
