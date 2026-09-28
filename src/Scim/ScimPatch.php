<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimGroup;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;

/**
 * Applies a SCIM PATCH request (RFC 7644 §3.5.2) to a user or group loaded by the mapper; the
 * controller then stores the result with the mapper's `replace()`. Supports the paths SLS sends
 * (doc 07) and the common ones other SCIM clients use; anything else is `invalidPath`.
 */
final class ScimPatch
{
    public const SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    private const EXT = ScimUser::EXTENSION_SCHEMA;

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array{op: string, path: ?string, value: mixed}>
     */
    public static function operations(array $body): array
    {
        $body = ScimUser::lowerKeys($body);
        if (!in_array(self::SCHEMA, (array) ($body['schemas'] ?? []), true)) {
            throw ContractException::badRequest('A PATCH body must use the PatchOp schema.', 'invalidSyntax');
        }
        $operations = $body['operations'] ?? null;
        if (!is_array($operations) || $operations === [] || !array_is_list($operations)) {
            throw ContractException::badRequest('"Operations" must be a non-empty list.', 'invalidSyntax');
        }

        $result = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                throw ContractException::badRequest('Every operation must be an object.', 'invalidSyntax');
            }
            $operation = ScimUser::lowerKeys($operation);
            $op        = strtolower((string) ($operation['op'] ?? ''));
            if (!in_array($op, ['add', 'replace', 'remove'], true)) {
                throw ContractException::badRequest(sprintf('Unsupported op "%s".', $op), 'invalidSyntax');
            }
            $path = $operation['path'] ?? null;
            if ($path !== null && (!is_string($path) || trim($path) === '')) {
                throw ContractException::badRequest('"path" must be a string.', 'invalidPath');
            }
            if ($op === 'remove' && $path === null) {
                throw ContractException::badRequest('"remove" needs a path.', 'noTarget');
            }
            if ($op !== 'remove' && !array_key_exists('value', $operation)) {
                throw ContractException::badRequest(sprintf('"%s" needs a value.', $op), 'invalidValue');
            }
            $result[] = ['op' => $op, 'path' => $path !== null ? trim($path) : null, 'value' => $operation['value'] ?? null];
        }

        return $result;
    }

    /** @param list<array{op: string, path: ?string, value: mixed}> $operations */
    public static function applyToUser(ScimUser $user, array $operations): ScimUser
    {
        foreach ($operations as ['op' => $op, 'path' => $path, 'value' => $value]) {
            if ($path === null) {
                // No path: the value is a partial resource whose attributes are set one by one.
                if (!is_array($value) || array_is_list($value)) {
                    throw ContractException::badRequest('Without a path the value must be an object.', 'invalidValue');
                }
                foreach (self::flatten($value) as $attribute => $attributeValue) {
                    self::setUserAttribute($user, $op, $attribute, $attributeValue);
                }
                continue;
            }
            self::setUserAttribute($user, $op, $path, $value);
        }

        if ($user->userName === '') {
            throw ContractException::badRequest('"userName" cannot be removed.', 'mutability');
        }

        return $user;
    }

    /** @param list<array{op: string, path: ?string, value: mixed}> $operations */
    public static function applyToGroup(ScimGroup $group, array $operations): ScimGroup
    {
        foreach ($operations as ['op' => $op, 'path' => $path, 'value' => $value]) {
            if ($path === null) {
                if (!is_array($value) || array_is_list($value)) {
                    throw ContractException::badRequest('Without a path the value must be an object.', 'invalidValue');
                }
                foreach (ScimUser::lowerKeys($value) as $attribute => $attributeValue) {
                    self::setGroupAttribute($group, $op, (string) $attribute, $attributeValue);
                }
                continue;
            }
            self::setGroupAttribute($group, $op, $path, $value);
        }

        if ($group->displayName === '') {
            throw ContractException::badRequest('"displayName" cannot be removed.', 'mutability');
        }

        return $group;
    }

    private static function setUserAttribute(ScimUser $user, string $op, string $path, mixed $value): void
    {
        $remove = $op === 'remove';
        $path   = strtolower($path);
        $ext    = strtolower(self::EXT) . ':';
        if (str_starts_with($path, $ext)) {
            $path = 'ext.' . substr($path, strlen($ext));
        } elseif ($path === strtolower(self::EXT)) {
            if ($remove) {
                $user->slsUserId = null;
                $user->roles     = [];

                return;
            }
            if (!is_array($value)) {
                throw ContractException::badRequest('The extension value must be an object.', 'invalidValue');
            }
            foreach (ScimUser::lowerKeys($value) as $attribute => $attributeValue) {
                self::setUserAttribute($user, $op, 'ext.' . $attribute, $attributeValue);
            }

            return;
        }

        $enterprise = strtolower(ScimUser::ENTERPRISE_SCHEMA);
        if ($path === $enterprise) {
            if ($remove) {
                $user->enterprise = [];

                return;
            }
            if (!is_array($value) || array_is_list($value)) {
                throw ContractException::badRequest('The enterprise extension value must be an object.', 'invalidValue');
            }
            foreach ($value as $attribute => $attributeValue) {
                $user->setEnterprise((string) $attribute, $attributeValue);
            }

            return;
        }
        if (str_starts_with($path, $enterprise . ':')) {
            $user->setEnterprise(substr($path, strlen($enterprise) + 1), $remove ? null : $value);

            return;
        }

        switch ($path) {
            case 'title':
                $user->title = $remove ? null : ScimUser::string($value);
                break;
            case 'active':
                $user->active = $remove ? false : ScimUser::bool($value, 'active');
                break;
            case 'username':
                $user->userName = $remove ? '' : (ScimUser::string($value) ?? '');
                break;
            case 'externalid':
                $user->externalId = $remove ? null : ScimUser::string($value);
                break;
            case 'displayname':
                $user->displayName = $remove ? null : ScimUser::string($value);
                break;
            case 'locale':
                $user->locale = $remove ? null : ScimUser::string($value);
                break;
            case 'name':
                if ($remove) {
                    $user->givenName = $user->familyName = null;
                    break;
                }
                if (!is_array($value)) {
                    throw ContractException::badRequest('"name" must be an object.', 'invalidValue');
                }
                $name = ScimUser::lowerKeys($value);
                if (array_key_exists('givenname', $name) || $op === 'replace') {
                    $user->givenName = ScimUser::string($name['givenname'] ?? null);
                }
                if (array_key_exists('familyname', $name) || $op === 'replace') {
                    $user->familyName = ScimUser::string($name['familyname'] ?? null);
                }
                break;
            case 'name.givenname':
                $user->givenName = $remove ? null : ScimUser::string($value);
                break;
            case 'name.familyname':
                $user->familyName = $remove ? null : ScimUser::string($value);
                break;
            case 'emails':
            case 'emails[type eq "work"].value':
            case 'emails[primary eq true].value':
            case 'emails.value':
                if ($remove) {
                    $user->email = null;
                } elseif (is_string($value)) {
                    $user->email = ScimUser::string($value);
                } else {
                    $user->email = ScimUser::primaryEmail($value) ?? $user->email;
                }
                break;
            case 'ext.slsuserid':
                $user->slsUserId = $remove ? null : ScimUser::string($value);
                break;
            case 'ext.roles':
                if ($remove) {
                    // `remove` with a value removes just those roles; without, all of them.
                    $user->roles = $value === null ? [] : array_values(array_diff($user->roles, ScimUser::roles($value)));
                } elseif ($op === 'add') {
                    $user->roles = array_values(array_unique([...$user->roles, ...ScimUser::roles($value)]));
                } else {
                    $user->roles = ScimUser::roles($value);
                }
                break;
            case 'ext.linked':
                // Read-only; set by the app. Ignore rather than reject so full-object replaces pass.
                break;
            default:
                throw ContractException::badRequest(sprintf('Unsupported path "%s".', $path), 'invalidPath');
        }
    }

    private static function setGroupAttribute(ScimGroup $group, string $op, string $path, mixed $value): void
    {
        $remove = $op === 'remove';
        $lower  = strtolower($path);

        if (preg_match('/^members\[value eq "([^"]+)"\]$/i', $path, $m)) {
            if (!$remove) {
                throw ContractException::badRequest('A member filter path only supports "remove".', 'invalidPath');
            }
            $group->members = array_values(array_diff($group->members, [$m[1]]));

            return;
        }

        switch ($lower) {
            case 'displayname':
                $group->displayName = $remove ? '' : (ScimUser::string($value) ?? '');
                break;
            case 'externalid':
                $group->externalId = $remove ? null : ScimUser::string($value);
                break;
            case 'members':
                if ($remove) {
                    $group->members = $value === null ? [] : array_values(array_diff($group->members, ScimGroup::memberIds($value)));
                } elseif ($op === 'add') {
                    $group->members = array_values(array_unique([...$group->members, ...ScimGroup::memberIds($value)]));
                } else {
                    $group->members = ScimGroup::memberIds($value);
                }
                break;
            default:
                throw ContractException::badRequest(sprintf('Unsupported path "%s".', $path), 'invalidPath');
        }
    }

    /**
     * `{"name": {"givenName": "A"}, "active": false}` → `["name.givenName" => "A", "active" => false]`,
     * keeping the extension object and multi-valued attributes whole.
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $value): array
    {
        $result = [];
        foreach ($value as $attribute => $attributeValue) {
            $attribute = (string) $attribute;
            if (in_array(strtolower($attribute), ['schemas', 'id', 'meta'], true)) {
                continue;
            }
            if (strtolower($attribute) === 'name' && is_array($attributeValue) && !array_is_list($attributeValue)) {
                foreach ($attributeValue as $sub => $subValue) {
                    $result['name.' . $sub] = $subValue;
                }
                continue;
            }
            $result[$attribute] = $attributeValue;
        }

        return $result;
    }
}
