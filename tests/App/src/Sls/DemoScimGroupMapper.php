<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimGroup;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimListResult;
use Smartlabsys\SlsConnectorBundle\Scim\ScimFilter;
use Smartlabsys\SlsConnectorBundle\Scim\ScimGroupMapperInterface;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;

final class DemoScimGroupMapper implements ScimGroupMapperInterface
{
    public function __construct(private JsonStore $store) {}

    public function list(string $tenantId, ?ScimFilter $filter, int $startIndex, int $count): ScimListResult
    {
        $groups = array_map(self::fromRow(...), array_values($this->store->read()['scim_groups'][$tenantId] ?? []));
        if ($filter !== null) {
            $groups = array_values(array_filter($groups, $filter->matchesGroup(...)));
        }

        return new ScimListResult(array_slice($groups, $startIndex - 1, $count), count($groups));
    }

    public function get(string $tenantId, string $id): ?ScimGroup
    {
        $row = $this->store->read()['scim_groups'][$tenantId][$id] ?? null;

        return $row !== null ? self::fromRow($row) : null;
    }

    public function create(string $tenantId, ScimGroup $group): ScimGroup
    {
        $group->id = 'sg_' . JsonStore::id();

        return $this->replace($tenantId, $group, true);
    }

    public function replace(string $tenantId, ScimGroup $group, bool $create = false): ?ScimGroup
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $group, $create): ?ScimGroup {
            if (!$create && !isset($data['scim_groups'][$tenantId][$group->id])) {
                return null;
            }
            foreach ($group->members as $member) {
                if (!isset($data['scim_users'][$tenantId][$member])) {
                    throw ContractException::badRequest(sprintf('Unknown member "%s".', $member), 'invalidValue');
                }
            }
            $group->created ??= new \DateTimeImmutable();
            $group->lastModified = new \DateTimeImmutable();
            $data['scim_groups'][$tenantId][$group->id] = [
                'id'           => $group->id,
                'externalId'   => $group->externalId,
                'displayName'  => $group->displayName,
                'members'      => $group->members,
                'created'      => $group->created->format(DATE_ATOM),
                'lastModified' => $group->lastModified->format(DATE_ATOM),
            ];

            return $group;
        });
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $id): bool {
            if (!isset($data['scim_groups'][$tenantId][$id])) {
                return false;
            }
            unset($data['scim_groups'][$tenantId][$id]);

            return true;
        });
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): ScimGroup
    {
        return new ScimGroup($row['id'], $row['externalId'], $row['displayName'], $row['members'], new \DateTimeImmutable($row['created']), new \DateTimeImmutable($row['lastModified']));
    }
}
