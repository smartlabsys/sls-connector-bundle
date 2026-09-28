<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim\Model;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;

/**
 * A SCIM User as SLS pushes it (doc 07): core schema + the SLS extension
 * `urn:smartlabsys:scim:1.0:User` (`slsUserId`, `roles`, and the read-only `linked` the app sets
 * when it attached the push to an existing local user). Mutable, so PATCH can apply operations
 * before the mapper stores it.
 *
 * `title` and the enterprise extension (`employeeNumber`, `costCenter`, `organization`,
 * `division`, `department`, `manager.displayName`) are the org's directory data, sent only when
 * the org's identity provider supplies them to SLS. Apps may store or ignore them.
 */
final class ScimUser
{
    public const SCHEMA           = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const EXTENSION_SCHEMA = 'urn:smartlabsys:scim:1.0:User';
    public const ENTERPRISE_SCHEMA = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** The enterprise extension's string attributes, by lower-cased name. */
    public const ENTERPRISE_STRINGS = [
        'employeenumber' => 'employeeNumber',
        'costcenter'     => 'costCenter',
        'organization'   => 'organization',
        'division'       => 'division',
        'department'     => 'department',
    ];

    /**
     * @param string[] $roles app roles, e.g. ["qc:analyst"]
     * @param array{employeeNumber?: string, costCenter?: string, organization?: string, division?: string, department?: string, manager?: array{value?: string, displayName?: string}} $enterprise
     */
    public function __construct(
        public ?string $id = null,
        public ?string $externalId = null,
        public string $userName = '',
        public ?string $givenName = null,
        public ?string $familyName = null,
        public ?string $displayName = null,
        public ?string $email = null,
        public ?string $locale = null,
        public bool $active = true,
        public ?string $slsUserId = null,
        public array $roles = [],
        public bool $linked = false,
        public ?\DateTimeInterface $created = null,
        public ?\DateTimeInterface $lastModified = null,
        public ?string $title = null,
        public array $enterprise = [],
    ) {}

    /**
     * Builds a user from a SCIM resource (POST / PUT body). Read-only attributes (`id`, `meta`,
     * `linked`) are ignored.
     *
     * @param array<string, mixed> $resource
     */
    public static function fromScim(array $resource): self
    {
        $resource = self::lowerKeys($resource);
        $user     = new self();
        $user->externalId  = self::string($resource['externalid'] ?? null);
        $user->userName    = self::string($resource['username'] ?? null) ?? '';
        $user->displayName = self::string($resource['displayname'] ?? null);
        $user->title       = self::string($resource['title'] ?? null);
        $user->locale      = self::string($resource['locale'] ?? null);
        $user->active      = self::bool($resource['active'] ?? true, 'active');

        $name = $resource['name'] ?? [];
        if (is_array($name)) {
            $name = self::lowerKeys($name);
            $user->givenName  = self::string($name['givenname'] ?? null);
            $user->familyName = self::string($name['familyname'] ?? null);
        }
        $user->email = self::primaryEmail($resource['emails'] ?? []);

        $extension = $resource[strtolower(self::EXTENSION_SCHEMA)] ?? [];
        if (is_array($extension)) {
            $extension       = self::lowerKeys($extension);
            $user->slsUserId = self::string($extension['slsuserid'] ?? null);
            $user->roles     = self::roles($extension['roles'] ?? []);
        }

        $enterprise = $resource[strtolower(self::ENTERPRISE_SCHEMA)] ?? [];
        if (!is_array($enterprise)) {
            throw ContractException::badRequest('The enterprise extension must be an object.', 'invalidValue');
        }
        foreach ($enterprise as $attribute => $value) {
            $user->setEnterprise((string) $attribute, $value);
        }

        if ($user->userName === '') {
            throw ContractException::badRequest('"userName" is required.', 'invalidValue');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public function toScim(string $location): array
    {
        return array_filter([
            'schemas'     => $this->enterprise !== [] ? [self::SCHEMA, self::EXTENSION_SCHEMA, self::ENTERPRISE_SCHEMA] : [self::SCHEMA, self::EXTENSION_SCHEMA],
            'id'          => $this->id,
            'externalId'  => $this->externalId,
            'userName'    => $this->userName,
            'name'        => array_filter(['givenName' => $this->givenName, 'familyName' => $this->familyName], 'is_string') ?: null,
            'displayName' => $this->displayName,
            'title'       => $this->title,
            'emails'      => $this->email !== null ? [['value' => $this->email, 'primary' => true]] : null,
            'locale'      => $this->locale,
            'active'      => $this->active,
            self::EXTENSION_SCHEMA => [
                'slsUserId' => $this->slsUserId,
                'roles'     => array_values($this->roles),
                'linked'    => $this->linked,
            ],
            self::ENTERPRISE_SCHEMA => $this->enterprise ?: null,
            'meta'        => array_filter([
                'resourceType' => 'User',
                'created'      => $this->created?->format(\DATE_ATOM),
                'lastModified' => $this->lastModified?->format(\DATE_ATOM),
                'location'     => $location,
            ]),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Sets (null: removes) one enterprise attribute by case-insensitive name: a string attribute,
     * `manager` (an object or the manager's id), `manager.value` / `manager.displayName`.
     * Unknown attributes are ignored.
     */
    public function setEnterprise(string $attribute, mixed $value): void
    {
        $attribute = strtolower($attribute);
        if (isset(self::ENTERPRISE_STRINGS[$attribute])) {
            $this->putEnterprise(self::ENTERPRISE_STRINGS[$attribute], self::string($value));

            return;
        }
        $manager = $this->enterprise['manager'] ?? [];
        switch ($attribute) {
            case 'manager':
                if (is_array($value)) {
                    $value   = self::lowerKeys($value);
                    $manager = ['value' => self::string($value['value'] ?? null), 'displayName' => self::string($value['displayname'] ?? null)];
                } else {
                    $manager = ['value' => self::string($value)];
                }
                break;
            case 'manager.value':
                $manager['value'] = self::string($value);
                break;
            case 'manager.displayname':
                $manager['displayName'] = self::string($value);
                break;
            default:
                return;
        }
        $manager = array_filter($manager, static fn ($v): bool => $v !== null);
        $this->putEnterprise('manager', $manager ?: null);
    }

    private function putEnterprise(string $attribute, string|array|null $value): void
    {
        if ($value === null) {
            unset($this->enterprise[$attribute]);
        } else {
            $this->enterprise[$attribute] = $value;
        }
    }

    /** @param mixed $emails */
    public static function primaryEmail(mixed $emails): ?string
    {
        if (!is_array($emails)) {
            throw ContractException::badRequest('"emails" must be a list.', 'invalidValue');
        }
        $first = null;
        foreach ($emails as $email) {
            if (!is_array($email) || !is_string($email['value'] ?? null)) {
                continue;
            }
            if (($email['primary'] ?? false) === true) {
                return trim($email['value']);
            }
            $first ??= trim($email['value']);
        }

        return $first;
    }

    /** @return string[] */
    public static function roles(mixed $roles): array
    {
        if (!is_array($roles)) {
            throw ContractException::badRequest('"roles" must be a list.', 'invalidValue');
        }
        $result = [];
        foreach ($roles as $role) {
            // Accept plain strings and SCIM multi-valued objects ({"value": "qc:analyst"}).
            $value = is_array($role) ? ($role['value'] ?? null) : $role;
            if (!is_string($value) || $value === '') {
                throw ContractException::badRequest('Every role must be a string.', 'invalidValue');
            }
            $result[] = $value;
        }

        return array_values(array_unique($result));
    }

    public static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ContractException::badRequest('Expected a string value.', 'invalidValue');
        }

        return trim($value) !== '' ? trim($value) : null;
    }

    public static function bool(mixed $value, string $attribute): bool
    {
        // Some SCIM clients send booleans as strings.
        return match (true) {
            is_bool($value)                            => $value,
            is_string($value) && strtolower($value) === 'true'  => true,
            is_string($value) && strtolower($value) === 'false' => false,
            default => throw ContractException::badRequest(sprintf('"%s" must be a boolean.', $attribute), 'invalidValue'),
        };
    }

    /**
     * SCIM attribute names are case-insensitive.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function lowerKeys(array $data): array
    {
        return array_change_key_case($data, CASE_LOWER);
    }
}
