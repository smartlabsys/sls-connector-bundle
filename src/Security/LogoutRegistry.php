<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Remembers back-channel logouts (doc 03, "Back-channel") in the cache pool until any local
 * session they could refer to has expired: a logged-out SLS session id (`sid`), or — for a
 * logout token with `sub` only — "every session of this user that signed in before now".
 */
final class LogoutRegistry
{
    private const TTL = 30 * 86400;

    public function __construct(private CacheItemPoolInterface $cache) {}

    public function revokeSession(string $sid): void
    {
        $this->save('sid.' . $sid, time());
    }

    public function revokeSubject(string $sub, int $at): void
    {
        $this->save('sub.' . $sub, $at);
    }

    /** A local session that signed in via SLS at `$authAt` with this `sub`/`sid` — is it logged out? */
    public function isRevoked(?string $sub, ?string $sid, int $authAt): bool
    {
        if ($sid !== null && $this->cache->getItem($this->key('sid.' . $sid))->isHit()) {
            return true;
        }
        if ($sub !== null) {
            $item = $this->cache->getItem($this->key('sub.' . $sub));

            return $item->isHit() && $item->get() >= $authAt;
        }

        return false;
    }

    private function save(string $name, int $value): void
    {
        $item = $this->cache->getItem($this->key($name));
        $this->cache->save($item->set($value)->expiresAfter(self::TTL));
    }

    private function key(string $name): string
    {
        return 'sls_connector.logout.' . sha1($name);
    }
}
