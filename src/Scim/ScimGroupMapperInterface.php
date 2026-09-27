<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim;

use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimGroup;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimListResult;

/** Optional: SCIM `/Groups`. Without an implementation `/Groups` answers 501. */
interface ScimGroupMapperInterface
{
    public function list(string $tenantId, ?ScimFilter $filter, int $startIndex, int $count): ScimListResult;

    public function get(string $tenantId, string $id): ?ScimGroup;

    public function create(string $tenantId, ScimGroup $group): ScimGroup;

    public function replace(string $tenantId, ScimGroup $group): ?ScimGroup;

    public function delete(string $tenantId, string $id): bool;
}
