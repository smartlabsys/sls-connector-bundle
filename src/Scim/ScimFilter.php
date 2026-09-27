<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimGroup;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;

/**
 * The part of the SCIM filter grammar (RFC 7644 §3.4.2.2) SLS uses: comparisons joined by `and`
 * — `attr eq|ne|co|sw "value"` and `attr pr`. `or`, `not` and grouping are refused with
 * `invalidFilter`. Attribute names are canonicalised (case-insensitive) to the constants below;
 * mappers translate {@see $conditions} to their query, or call {@see matchesUser()} /
 * {@see matchesGroup()} to filter in memory.
 */
final class ScimFilter
{
    public const SLS_USER_ID = ScimUser::EXTENSION_SCHEMA . ':slsUserId';
    public const SLS_ROLES   = ScimUser::EXTENSION_SCHEMA . ':roles';

    public const USER_ATTRIBUTES = [
        'id', 'externalId', 'userName', 'displayName', 'active', 'locale', 'emails.value',
        'name.givenName', 'name.familyName', self::SLS_USER_ID, self::SLS_ROLES,
    ];
    public const GROUP_ATTRIBUTES = ['id', 'externalId', 'displayName', 'members.value'];

    private const OPERATORS = ['eq', 'ne', 'co', 'sw', 'pr'];
    private const ALIASES   = [
        'emails'  => 'emails.value',
        'members' => 'members.value',
        'slsuserid' => self::SLS_USER_ID,
    ];

    /** @param list<array{attribute: string, operator: string, value: string|bool|int|float|null}> $conditions */
    private function __construct(public readonly array $conditions) {}

    /** @param string[] $attributes the resource type's filterable attributes */
    public static function parse(string $filter, array $attributes): self
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($filter);
        while ($offset < $length) {
            if (!preg_match('/\G\s*("(?:[^"\\\\]|\\\\.)*"|[^\s"()\[\]]+)\s*/A', $filter, $m, 0, $offset)) {
                throw self::invalid('Could not parse the filter (grouping and complex filters are not supported).');
            }
            $tokens[] = $m[1];
            $offset  += strlen($m[0]);
        }

        $canonical = [];
        foreach ($attributes as $attribute) {
            $canonical[strtolower($attribute)] = $attribute;
        }

        $conditions = [];
        $i          = 0;
        $count      = count($tokens);
        while ($i < $count) {
            $name = strtolower($tokens[$i] ?? '');
            $name = strtolower(self::ALIASES[$name] ?? $name);
            if (!isset($canonical[$name])) {
                throw self::invalid(sprintf('Filtering on "%s" is not supported.', $tokens[$i]));
            }
            $operator = strtolower($tokens[$i + 1] ?? '');
            if (!in_array($operator, self::OPERATORS, true)) {
                throw self::invalid(sprintf('Operator "%s" is not supported.', $tokens[$i + 1] ?? ''));
            }
            $value = null;
            $i += 2;
            if ($operator !== 'pr') {
                if (!isset($tokens[$i])) {
                    throw self::invalid('A comparison value is missing.');
                }
                $value = self::value($tokens[$i]);
                ++$i;
            }
            $conditions[] = ['attribute' => $canonical[$name], 'operator' => $operator, 'value' => $value];

            if ($i < $count) {
                if (strtolower($tokens[$i]) !== 'and') {
                    throw self::invalid('Only "and" can join filter expressions.');
                }
                ++$i;
                if ($i === $count) {
                    throw self::invalid('The filter ends with "and".');
                }
            }
        }
        if ($conditions === []) {
            throw self::invalid('The filter is empty.');
        }

        return new self($conditions);
    }

    /** The value of an `attr eq "…"` condition, if the filter has one — handy for lookups. */
    public function equalityValue(string $attribute): string|bool|int|float|null
    {
        foreach ($this->conditions as $condition) {
            if ($condition['attribute'] === $attribute && $condition['operator'] === 'eq') {
                return $condition['value'];
            }
        }

        return null;
    }

    public function matchesUser(ScimUser $user): bool
    {
        return $this->matches(static fn (string $attribute): array => match ($attribute) {
            'id'              => [$user->id],
            'externalId'      => [$user->externalId],
            'userName'        => [$user->userName],
            'displayName'     => [$user->displayName],
            'active'          => [$user->active],
            'locale'          => [$user->locale],
            'emails.value'    => [$user->email],
            'name.givenName'  => [$user->givenName],
            'name.familyName' => [$user->familyName],
            self::SLS_USER_ID => [$user->slsUserId],
            self::SLS_ROLES   => $user->roles,
            default           => [],
        });
    }

    public function matchesGroup(ScimGroup $group): bool
    {
        return $this->matches(static fn (string $attribute): array => match ($attribute) {
            'id'            => [$group->id],
            'externalId'    => [$group->externalId],
            'displayName'   => [$group->displayName],
            'members.value' => $group->members,
            default         => [],
        });
    }

    /** @param callable(string): list<mixed> $valuesOf */
    private function matches(callable $valuesOf): bool
    {
        foreach ($this->conditions as $condition) {
            $values = array_filter($valuesOf($condition['attribute']), static fn ($v): bool => $v !== null && $v !== '');
            $ok     = match ($condition['operator']) {
                'pr'    => $values !== [],
                'ne'    => !self::any($values, $condition['value'], 'eq'),
                default => self::any($values, $condition['value'], $condition['operator']),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** @param array<mixed> $values */
    private static function any(array $values, mixed $expected, string $operator): bool
    {
        foreach ($values as $value) {
            if (is_bool($value) || is_bool($expected)) {
                if ($operator === 'eq' && $value === $expected) {
                    return true;
                }
                continue;
            }
            // String comparisons are case-insensitive for the attributes SLS filters on (RFC 7643 caseExact=false).
            $a = mb_strtolower((string) $value);
            $b = mb_strtolower((string) $expected);
            $hit = match ($operator) {
                'eq'    => $a === $b,
                'co'    => str_contains($a, $b),
                'sw'    => str_starts_with($a, $b),
                default => false,
            };
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    private static function value(string $token): string|bool|int|float|null
    {
        if ($token[0] === '"') {
            $decoded = json_decode($token);
            if (!is_string($decoded)) {
                throw self::invalid('Invalid string value.');
            }

            return $decoded;
        }

        return match (strtolower($token)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => is_numeric($token) ? $token + 0 : throw self::invalid(sprintf('Invalid value "%s".', $token)),
        };
    }

    private static function invalid(string $message): ContractException
    {
        return ContractException::badRequest($message, 'invalidFilter');
    }
}
