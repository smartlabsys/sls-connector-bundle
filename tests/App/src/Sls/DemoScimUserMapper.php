<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Sls;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimListResult;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Smartlabsys\SlsConnectorBundle\Scim\ScimFilter;
use Smartlabsys\SlsConnectorBundle\Scim\ScimUserMapperInterface;
use Smartlabsys\SlsConnectorBundle\Tests\App\Store\JsonStore;

final class DemoScimUserMapper implements ScimUserMapperInterface
{
    public function __construct(private JsonStore $store) {}

    public function list(string $tenantId, ?ScimFilter $filter, int $startIndex, int $count): ScimListResult
    {
        $users = array_map(self::fromRow(...), array_values($this->store->read()['scim_users'][$tenantId] ?? []));
        if ($filter !== null) {
            $users = array_values(array_filter($users, $filter->matchesUser(...)));
        }

        return new ScimListResult(array_slice($users, $startIndex - 1, $count), count($users));
    }

    public function get(string $tenantId, string $id): ?ScimUser
    {
        $row = $this->store->read()['scim_users'][$tenantId][$id] ?? null;

        return $row !== null ? self::fromRow($row) : null;
    }

    public function create(string $tenantId, ScimUser $user): ScimUser
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $user): ScimUser {
            self::assertUnique($data['scim_users'][$tenantId] ?? [], $user);
            $user->id      = 'su_' . JsonStore::id();
            $user->created = $user->lastModified = new \DateTimeImmutable();
            // "Linked" when a local account with this e-mail already existed.
            foreach ($data['users'] ?? [] as $local) {
                if ($user->email !== null && strcasecmp($local['email'], $user->email) === 0) {
                    $user->linked = true;
                }
            }
            $data['scim_users'][$tenantId][$user->id] = self::toRow($user);

            return $user;
        });
    }

    public function replace(string $tenantId, ScimUser $user): ?ScimUser
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $user): ?ScimUser {
            if (!isset($data['scim_users'][$tenantId][$user->id])) {
                return null;
            }
            self::assertUnique($data['scim_users'][$tenantId], $user);
            $user->lastModified                       = new \DateTimeImmutable();
            $data['scim_users'][$tenantId][$user->id] = self::toRow($user);

            return $user;
        });
    }

    public function delete(string $tenantId, string $id): bool
    {
        return $this->store->update(static function (array &$data) use ($tenantId, $id): bool {
            if (!isset($data['scim_users'][$tenantId][$id])) {
                return false;
            }
            unset($data['scim_users'][$tenantId][$id]);

            return true;
        });
    }

    /** @param array<string, array<string, mixed>> $rows */
    private static function assertUnique(array $rows, ScimUser $user): void
    {
        foreach ($rows as $id => $row) {
            if ($id !== $user->id && strcasecmp($row['userName'], $user->userName) === 0) {
                throw ContractException::conflict(sprintf('userName "%s" is taken.', $user->userName));
            }
        }
    }

    /** @return array<string, mixed> */
    private static function toRow(ScimUser $user): array
    {
        $row                 = get_object_vars($user);
        $row['created']      = $user->created?->format(DATE_ATOM);
        $row['lastModified'] = $user->lastModified?->format(DATE_ATOM);

        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): ScimUser
    {
        $row['created']      = isset($row['created']) ? new \DateTimeImmutable($row['created']) : null;
        $row['lastModified'] = isset($row['lastModified']) ? new \DateTimeImmutable($row['lastModified']) : null;

        return new ScimUser(...$row);
    }
}
