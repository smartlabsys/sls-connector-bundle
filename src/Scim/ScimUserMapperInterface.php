<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim;

use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimListResult;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;

/**
 * What an app implements for SCIM `/Users` (doc 07). The bundle handles the protocol; the mapper
 * only stores users of one tenant (from the token's `tenant_id`). Throw
 * {@see \Smartlabsys\SlsConnectorBundle\Exception\ContractException::conflict()} when a create or
 * replace would clash with another user (→ 409 `uniqueness`).
 *
 * `create()` may link to an existing local user with the same e-mail instead of creating one —
 * set `linked = true` on the returned user so SLS can show it.
 */
interface ScimUserMapperInterface
{
    /**
     * @param int $startIndex 1-based
     */
    public function list(string $tenantId, ?ScimFilter $filter, int $startIndex, int $count): ScimListResult;

    public function get(string $tenantId, string $id): ?ScimUser;

    /** @return ScimUser the stored user, with `id` set */
    public function create(string $tenantId, ScimUser $user): ScimUser;

    /** Stores the user (`id` is set). Return null when it no longer exists. */
    public function replace(string $tenantId, ScimUser $user): ?ScimUser;

    /** Deprovision: false when the user does not exist. */
    public function delete(string $tenantId, string $id): bool;
}
