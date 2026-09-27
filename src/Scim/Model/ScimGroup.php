<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim\Model;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;

/** A SCIM Group; `members` are SCIM user ids in this app. */
final class ScimGroup
{
    public const SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

    /** @param string[] $members */
    public function __construct(
        public ?string $id = null,
        public ?string $externalId = null,
        public string $displayName = '',
        public array $members = [],
        public ?\DateTimeInterface $created = null,
        public ?\DateTimeInterface $lastModified = null,
    ) {}

    /** @param array<string, mixed> $resource */
    public static function fromScim(array $resource): self
    {
        $resource = ScimUser::lowerKeys($resource);
        $group    = new self(
            externalId: ScimUser::string($resource['externalid'] ?? null),
            displayName: ScimUser::string($resource['displayname'] ?? null) ?? '',
            members: self::memberIds($resource['members'] ?? []),
        );
        if ($group->displayName === '') {
            throw ContractException::badRequest('"displayName" is required.', 'invalidValue');
        }

        return $group;
    }

    /** @return string[] */
    public static function memberIds(mixed $members): array
    {
        if (!is_array($members)) {
            throw ContractException::badRequest('"members" must be a list.', 'invalidValue');
        }
        $ids = [];
        foreach ($members as $member) {
            $value = is_array($member) ? ($member['value'] ?? null) : null;
            if (!is_string($value) || $value === '') {
                throw ContractException::badRequest('Every member needs a "value".', 'invalidValue');
            }
            $ids[] = $value;
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed> */
    public function toScim(string $location, string $usersLocation): array
    {
        return array_filter([
            'schemas'     => [self::SCHEMA],
            'id'          => $this->id,
            'externalId'  => $this->externalId,
            'displayName' => $this->displayName,
            'members'     => array_map(static fn (string $id): array => [
                'value' => $id,
                '$ref'  => $usersLocation . '/' . rawurlencode($id),
                'type'  => 'User',
            ], array_values($this->members)),
            'meta'        => array_filter([
                'resourceType' => 'Group',
                'created'      => $this->created?->format(\DATE_ATOM),
                'lastModified' => $this->lastModified?->format(\DATE_ATOM),
                'location'     => $location,
            ]),
        ], static fn ($value): bool => $value !== null);
    }
}
