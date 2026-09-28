<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Scim;

use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimGroup;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;

/** The SCIM discovery resources: `/ServiceProviderConfig`, `/ResourceTypes`, `/Schemas`. */
final class ScimSchemas
{
    public const LIST_RESPONSE          = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    public const ERROR                  = 'urn:ietf:params:scim:api:messages:2.0:Error';
    public const SERVICE_PROVIDER_CONFIG = 'urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig';
    public const RESOURCE_TYPE          = 'urn:ietf:params:scim:schemas:core:2.0:ResourceType';
    public const SCHEMA                 = 'urn:ietf:params:scim:schemas:core:2.0:Schema';

    public const MAX_RESULTS = 200;

    /** @return array<string, mixed> */
    public static function serviceProviderConfig(string $base): array
    {
        return [
            'schemas'               => [self::SERVICE_PROVIDER_CONFIG],
            'patch'                 => ['supported' => true],
            'bulk'                  => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter'                => ['supported' => true, 'maxResults' => self::MAX_RESULTS],
            'changePassword'        => ['supported' => false],
            'sort'                  => ['supported' => false],
            'etag'                  => ['supported' => false],
            'authenticationSchemes' => [[
                'type'        => 'oauthbearertoken',
                'name'        => 'SLS service token',
                'description' => 'A JWT issued by SLS with the sls:scim scope and a tenant_id claim.',
                'primary'     => true,
            ]],
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => $base . '/ServiceProviderConfig'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function resourceTypes(string $base, bool $groups): array
    {
        $types = [[
            'schemas'          => [self::RESOURCE_TYPE],
            'id'               => 'User',
            'name'             => 'User',
            'endpoint'         => '/Users',
            'schema'           => ScimUser::SCHEMA,
            'schemaExtensions' => [
                ['schema' => ScimUser::EXTENSION_SCHEMA, 'required' => false],
                ['schema' => ScimUser::ENTERPRISE_SCHEMA, 'required' => false],
            ],
            'meta'             => ['resourceType' => 'ResourceType', 'location' => $base . '/ResourceTypes/User'],
        ]];
        if ($groups) {
            $types[] = [
                'schemas'  => [self::RESOURCE_TYPE],
                'id'       => 'Group',
                'name'     => 'Group',
                'endpoint' => '/Groups',
                'schema'   => ScimGroup::SCHEMA,
                'meta'     => ['resourceType' => 'ResourceType', 'location' => $base . '/ResourceTypes/Group'],
            ];
        }

        return $types;
    }

    /** @return list<array<string, mixed>> */
    public static function schemas(string $base, bool $groups): array
    {
        $schemas = [
            self::schema($base, ScimUser::SCHEMA, 'User', [
                self::attribute('userName', uniqueness: 'server', required: true),
                self::attribute('externalId'),
                self::attribute('displayName'),
                self::attribute('title'),
                self::attribute('name', 'complex', subAttributes: [self::attribute('givenName'), self::attribute('familyName')]),
                self::attribute('emails', 'complex', multiValued: true, subAttributes: [self::attribute('value'), self::attribute('primary', 'boolean')]),
                self::attribute('locale'),
                self::attribute('active', 'boolean'),
            ]),
            self::schema($base, ScimUser::EXTENSION_SCHEMA, 'SLS user', [
                self::attribute('slsUserId'),
                self::attribute('roles', multiValued: true),
                self::attribute('linked', 'boolean', mutability: 'readOnly'),
            ]),
            self::schema($base, ScimUser::ENTERPRISE_SCHEMA, 'Enterprise user', [
                self::attribute('employeeNumber'),
                self::attribute('costCenter'),
                self::attribute('organization'),
                self::attribute('division'),
                self::attribute('department'),
                self::attribute('manager', 'complex', subAttributes: [self::attribute('value'), self::attribute('displayName')]),
            ]),
        ];
        if ($groups) {
            $schemas[] = self::schema($base, ScimGroup::SCHEMA, 'Group', [
                self::attribute('displayName', required: true),
                self::attribute('externalId'),
                self::attribute('members', 'complex', multiValued: true, subAttributes: [self::attribute('value')]),
            ]);
        }

        return $schemas;
    }

    /**
     * @param list<array<string, mixed>> $attributes
     *
     * @return array<string, mixed>
     */
    private static function schema(string $base, string $id, string $name, array $attributes): array
    {
        return [
            'schemas'    => [self::SCHEMA],
            'id'         => $id,
            'name'       => $name,
            'attributes' => $attributes,
            'meta'       => ['resourceType' => 'Schema', 'location' => $base . '/Schemas/' . $id],
        ];
    }

    /**
     * @param list<array<string, mixed>> $subAttributes
     *
     * @return array<string, mixed>
     */
    private static function attribute(
        string $name,
        string $type = 'string',
        bool $multiValued = false,
        bool $required = false,
        string $mutability = 'readWrite',
        string $uniqueness = 'none',
        array $subAttributes = [],
    ): array {
        return array_filter([
            'name'          => $name,
            'type'          => $type,
            'multiValued'   => $multiValued,
            'required'      => $required,
            'caseExact'     => false,
            'mutability'    => $mutability,
            'returned'      => 'default',
            'uniqueness'    => $uniqueness,
            'subAttributes' => $subAttributes ?: null,
        ], static fn ($v): bool => $v !== null);
    }
}
