<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Test;

use Smartlabsys\SlsConnectorBundle\Scim\ScimPatch;
use Smartlabsys\SlsConnectorBundle\Security\PartnerTokenIntrospector;
use Smartlabsys\SlsConnectorBundle\Scim\Model\ScimUser;
use Smartlabsys\SlsConnectorBundle\SlsConnectorBundle;
use Smartlabsys\SlsConnectorBundle\Webhook\WebhookSignature;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The SLS app contract (v1) as a test suite. An app extends it once and runs it in CI:
 *
 *     final class SlsContractTest extends SlsContractTestCase {}
 *
 * with, in the test environment, `sls_connector.jwks_file` pointing at a writable path (the suite
 * writes its own signing key there) and the app's real implementations of the bundle interfaces.
 * Override the `contract*()` hooks to adapt the fixtures (e.g. userName/e-mail rules) to the app.
 *
 * Tenant-independent checks run against a fresh tenant created through the provisioning API, so
 * the suite needs no fixtures.
 */
abstract class SlsContractTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected SlsTestTokens $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->tokens = SlsTestTokens::shared();

        $file = $this->param('sls_connector.jwks_file');
        if (!is_string($file) || $file === '') {
            self::fail('Set sls_connector.jwks_file in the test environment so the contract suite can install its signing key.');
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }
        file_put_contents($file, json_encode($this->tokens->jwks(), JSON_THROW_ON_ERROR));
    }

    // ── Hooks ────────────────────────────────────────────────────────────────

    /**
     * Body for `POST /tenants`: contract 2, for the org's company `$slsCompanyId` (the org's default
     * one when null); `$slsCompanyId === false` sends a contract 1 body, with no company.
     */
    protected function contractTenantRequest(string $slsOrgId, string|false|null $slsCompanyId = null): array
    {
        $body = [
            'sls_org_id'   => $slsOrgId,
            'organization' => ['name' => 'Contract Test ' . substr($slsOrgId, -6), 'slug' => 'contract-' . substr($slsOrgId, -6), 'locale' => 'en'],
        ];
        if ($slsCompanyId !== false) {
            $slsCompanyId ??= 'co-' . $slsOrgId;
            $body['sls_company_id'] = $slsCompanyId;
            $body['company']        = [
                'name'                => 'Contract Company ' . substr($slsCompanyId, -6),
                'tax_id'              => '10' . substr((string) crc32($slsCompanyId), 0, 7),
                'registration_number' => null,
                'public_funds_id'     => null,
                'address'             => 'Contract Street 1',
                'city'                => 'Novi Sad',
                'postal_code'         => '21000',
                'country'             => 'RS',
            ];
        }

        return $body;
    }

    /** A SCIM user resource SLS would push; `$suffix` keeps it unique. */
    protected function contractScimUser(string $suffix): array
    {
        return [
            'schemas'    => [ScimUser::SCHEMA, ScimUser::EXTENSION_SCHEMA],
            'externalId' => 'ext-' . $suffix,
            'userName'   => 'contract.' . $suffix . '@example.com',
            'name'       => ['givenName' => 'Contract', 'familyName' => 'User ' . $suffix],
            'displayName' => 'Contract User ' . $suffix,
            'emails'     => [['value' => 'contract.' . $suffix . '@example.com', 'primary' => true]],
            'locale'     => 'en',
            'active'     => true,
            ScimUser::EXTENSION_SCHEMA => ['slsUserId' => 'sls-user-' . $suffix, 'roles' => $this->contractRoles()],
        ];
    }

    /** Roles to assign in the SCIM tests — the first manifest role by default. */
    protected function contractRoles(): array
    {
        $roles = $this->config()['roles'];

        return $roles !== [] ? [$roles[0]['key']] : [];
    }

    /** Whether the app implements SCIM Groups (then the group tests run). */
    protected function contractSupportsGroups(): bool
    {
        return $this->scim('GET', '/scim/v2/ResourceTypes/Group', $this->newTenant())[0] === 200;
    }

    /** Whether the app runs seed templates (then the seed tests run). */
    protected function contractSupportsSeeds(): bool
    {
        return $this->config()['seed_templates'] !== [];
    }

    /** Seed parameters for the first manifest template. */
    protected function contractSeedParameters(): array
    {
        return [];
    }

    // ── Manifest & health ────────────────────────────────────────────────────

    public function testContractManifest(): void
    {
        $this->client->request('GET', '/.well-known/sls-app.json');
        self::assertResponseIsSuccessful();
        $manifest = $this->json();

        self::assertSame(SlsConnectorBundle::CONTRACT_VERSION, $manifest['contract_version']);
        self::assertSame($this->config()['app']['key'], $manifest['app']['key']);
        foreach (['health', 'provisioning', 'scim', 'webhooks', 'oidc_callback', 'backchannel_logout'] as $endpoint) {
            self::assertStringStartsWith('/', $manifest['endpoints'][$endpoint] ?? '', $endpoint);
        }
        foreach (['login', 'api', 'mcp'] as $endpoint) {
            if (isset($manifest['endpoints'][$endpoint])) {
                self::assertStringStartsWith('/', $manifest['endpoints'][$endpoint], $endpoint);
            }
        }
        foreach ($manifest['roles'] as $role) {
            self::assertStringStartsWith($manifest['app']['key'] . ':', $role['key']);
            self::assertNotEmpty($role['label']['en']);
        }
    }

    public function testContractHealth(): void
    {
        [$status, $body] = $this->call('GET', '/sls/health', $this->serviceToken(['sls:health']));
        self::assertSame(200, $status);
        self::assertContains($body['status'], ['ok', 'degraded']);
        self::assertSame(SlsConnectorBundle::CONTRACT_VERSION, $body['contract_version']);
    }

    /** RFC 9728 metadata for the app and, when `endpoints.mcp` is set, its MCP server (doc 09 §4). */
    public function testContractProtectedResource(): void
    {
        $mcp = $this->config()['endpoints']['mcp'] ?? null;
        $resources = ['' => $this->audience()];
        if ($mcp !== null) {
            $resources[$mcp] = $this->audience() . $mcp;
        }
        foreach ($resources as $suffix => $resource) {
            $this->client->request('GET', '/.well-known/oauth-protected-resource' . $suffix);
            self::assertResponseIsSuccessful();
            $metadata = $this->json();
            self::assertSame($resource, $metadata['resource']);
            self::assertSame([$this->issuer()], $metadata['authorization_servers']);
        }
        if ($mcp === null) {
            return;
        }

        [$status] = $this->call('POST', $mcp, null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        self::assertSame(401, $status);
        self::assertStringContainsString(
            'resource_metadata="' . $this->audience() . '/.well-known/oauth-protected-resource' . $mcp . '"',
            (string) $this->client->getResponse()->headers->get('WWW-Authenticate'),
            'Set Security\\SlsBearerChallenge as the MCP firewall\'s entry_point and failure_handler.',
        );
    }

    // ── Service authentication ───────────────────────────────────────────────

    public function testContractRejectsMissingOrBadTokens(): void
    {
        $issuer   = $this->issuer();
        $audience = $this->audience();
        $bad      = [
            'no token'      => null,
            'garbage'       => 'not-a-jwt',
            'wrong aud'     => $this->tokens->serviceToken($issuer, 'https://other.example.com', ['sls:health']),
            'wrong iss'     => $this->tokens->serviceToken('https://evil.example.com', $audience, ['sls:health']),
            'wrong typ'     => $this->tokens->serviceToken($issuer, $audience, ['sls:health'], null, [], ['typ' => 'JWT']),
            'expired'       => $this->tokens->serviceToken($issuer, $audience, ['sls:health'], null, ['exp' => time() - 120]),
            'not yet valid' => $this->tokens->serviceToken($issuer, $audience, ['sls:health'], null, ['nbf' => time() + 600]),
            'not sls'       => $this->tokens->serviceToken($issuer, $audience, ['sls:health'], null, ['sub' => 'someone', 'client_id' => 'someone']),
            'unknown key'   => SlsTestTokens::generate()->serviceToken($issuer, $audience, ['sls:health']),
        ];
        foreach ($bad as $case => $token) {
            [$status] = $this->call('GET', '/sls/health', $token);
            self::assertSame(401, $status, $case);
        }

        [$status, $body] = $this->call('GET', '/sls/health', $this->serviceToken(['sls:scim']));
        self::assertSame(403, $status);
        self::assertSame('insufficient_scope', $body['error']);

        [$status] = $this->call('POST', '/sls/provisioning/tenants', $this->serviceToken(['sls:health']), $this->contractTenantRequest($this->uid()));
        self::assertSame(403, $status);
    }

    // ── Provisioning ─────────────────────────────────────────────────────────

    public function testContractTenantLifecycle(): void
    {
        $token = $this->serviceToken(['sls:provision']);
        $orgId = $this->uid();

        [$status, $created] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($orgId));
        self::assertSame(201, $status);
        self::assertIsString($created['tenant_id']);
        self::assertSame('active', $created['status']);
        $tenantId = $created['tenant_id'];

        [$status, $again] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($orgId));
        self::assertSame(200, $status, 'Creating a tenant is idempotent on sls_company_id.');
        self::assertSame($tenantId, $again['tenant_id']);

        [$status, $body] = $this->call('GET', '/sls/provisioning/tenants/' . $tenantId, $token);
        self::assertSame(200, $status);
        self::assertSame($tenantId, $body['tenant_id']);

        [$status, $body] = $this->call('POST', '/sls/provisioning/tenants/' . $tenantId . '/suspend', $token);
        self::assertSame(200, $status);
        self::assertSame('suspended', $body['status']);

        [$status, $body] = $this->call('POST', '/sls/provisioning/tenants/' . $tenantId . '/resume', $token);
        self::assertSame(200, $status);
        self::assertSame('active', $body['status']);

        [$status, $body] = $this->call('GET', '/sls/provisioning/tenants/' . $tenantId, $this->serviceToken(['sls:provision'], 'another-tenant'));
        self::assertSame(403, $status);
        self::assertSame('tenant_mismatch', $body['error']);

        [$status, $body] = $this->call('GET', '/sls/provisioning/tenants/does-not-exist-' . $this->uid(), $token);
        self::assertSame(404, $status);
        self::assertSame('tenant_not_found', $body['error']);

        [$status] = $this->call('POST', '/sls/provisioning/tenants', $token, ['organization' => ['name' => 'x']]);
        self::assertSame(400, $status);

        [$status] = $this->call('DELETE', '/sls/provisioning/tenants/' . $tenantId, $token);
        self::assertSame(204, $status);
        [$status] = $this->call('GET', '/sls/provisioning/tenants/' . $tenantId, $token);
        self::assertSame(404, $status);
        // After a delete (an Owner's "Delete remote tenant"), connecting again makes a fresh tenant.
        [$status, $fresh] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($orgId));
        self::assertSame(201, $status);
        self::assertSame('active', $fresh['status']);
        [$status] = $this->call('DELETE', '/sls/provisioning/tenants/' . $fresh['tenant_id'], $token);
        self::assertSame(204, $status);
    }

    /** Contract 2: the company is the tenant; tenants from contract 1 move onto the first company. */
    public function testContractCompanyTenants(): void
    {
        $token = $this->serviceToken(['sls:provision']);
        $orgId = $this->uid();

        [$status, $labA] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($orgId, 'co-a-' . $orgId));
        self::assertSame(201, $status);
        [$status, $labB] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($orgId, 'co-b-' . $orgId));
        self::assertSame(201, $status, 'A second company of the same org gets its own tenant.');
        self::assertNotSame($labA['tenant_id'], $labB['tenant_id']);
        [$status, $again] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($orgId, 'co-b-' . $orgId));
        self::assertSame(200, $status, 'Creating a tenant is idempotent on sls_company_id.');
        self::assertSame($labB['tenant_id'], $again['tenant_id']);

        // A tenant made under contract 1 (org only) is the org's first company's once it is named.
        $legacyOrg = $this->uid();
        [$status, $legacy] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($legacyOrg, false));
        self::assertSame(201, $status);
        [$status, $adopted] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($legacyOrg));
        self::assertSame(200, $status, 'A contract 1 tenant is linked to the first company that asks for it.');
        self::assertSame($legacy['tenant_id'], $adopted['tenant_id']);
        [$status, $other] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($legacyOrg, 'co-x-' . $legacyOrg));
        self::assertSame(201, $status, 'Only one company takes over the contract 1 tenant.');
        self::assertNotSame($legacy['tenant_id'], $other['tenant_id']);

        $bad = $this->contractTenantRequest($this->uid());
        $bad['company'] = ['name' => ' '];
        [$status] = $this->call('POST', '/sls/provisioning/tenants', $token, $bad);
        self::assertSame(400, $status, 'A company without a name is refused.');

        foreach ([$labA, $labB, $legacy, $other] as $tenant) {
            [$status] = $this->call('DELETE', '/sls/provisioning/tenants/' . $tenant['tenant_id'], $token);
            self::assertSame(204, $status);
        }
    }

    public function testContractTenantPreview(): void
    {
        $token = $this->serviceToken(['sls:provision']);
        $orgId = $this->uid();

        [$status, $body] = $this->call('POST', '/sls/provisioning/tenants/preview', $token, $this->contractTenantRequest($orgId));
        if ($status === 501) {
            self::markTestSkipped('The app does not preview tenants.');
        }
        self::assertSame(200, $status);
        self::assertSame('create', $body['action'], 'A new org without a claim gets a new tenant.');
        self::assertNull($body['tenant']);

        [$status, $created] = $this->call('POST', '/sls/provisioning/tenants', $token, $this->contractTenantRequest($orgId));
        self::assertSame(201, $status, 'Previewing must not create the tenant.');

        [$status, $body] = $this->call('POST', '/sls/provisioning/tenants/preview', $token, $this->contractTenantRequest($orgId));
        self::assertSame(200, $status);
        self::assertSame('existing', $body['action']);
        self::assertSame($created['tenant_id'], $body['tenant']['tenant_id']);

        [$status] = $this->call('POST', '/sls/provisioning/tenants/preview', $token, ['organization' => ['name' => 'x']]);
        self::assertSame(400, $status);
        [$status] = $this->call('POST', '/sls/provisioning/tenants/preview', $this->serviceToken(['sls:health']), $this->contractTenantRequest($orgId));
        self::assertSame(403, $status);

        [$status] = $this->call('DELETE', '/sls/provisioning/tenants/' . $created['tenant_id'], $token);
        self::assertSame(204, $status);
    }

    public function testContractSeeds(): void
    {
        if (!$this->contractSupportsSeeds()) {
            self::markTestSkipped('The app declares no seed templates.');
        }
        $tenantId = $this->newTenant();
        $token    = $this->serviceToken(['sls:provision'], $tenantId);
        $template = $this->config()['seed_templates'][0];
        $url      = '/sls/provisioning/tenants/' . $tenantId . '/seeds';

        [$status, $body] = $this->call('POST', $url, $token, ['template' => 'no-such-template-' . $this->uid(), 'version' => 1]);
        self::assertSame(422, $status);
        self::assertSame('unknown_template', $body['error']);

        [$status, $body] = $this->call('POST', $url, $token, ['template' => $template['key'], 'version' => $template['version'] + 1]);
        self::assertSame(409, $status);
        self::assertSame('template_version_mismatch', $body['error']);

        [$status, $job] = $this->call('POST', $url, $token, [
            'template'        => $template['key'],
            'version'         => $template['version'],
            'parameters'      => $this->contractSeedParameters(),
            'idempotency_key' => $this->uid(),
        ]);
        self::assertSame(202, $status);
        self::assertIsString($job['job_id']);
        self::assertContains($job['status'], ['queued', 'running', 'succeeded', 'failed']);

        [$status, $body] = $this->call('GET', $url . '/' . $job['job_id'], $token);
        self::assertSame(200, $status);
        self::assertSame($job['job_id'], $body['job_id']);

        [$status] = $this->call('GET', $url . '/no-such-job', $token);
        self::assertSame(404, $status);

        // Cancelling is optional: 501 without it; with it, 204 (a finished job too) / 404.
        [$status] = $this->call('DELETE', $url . '/' . $job['job_id'], $token);
        self::assertContains($status, [204, 501]);
        if ($status === 204) {
            [$status] = $this->call('DELETE', $url . '/no-such-job', $token);
            self::assertSame(404, $status);
        }
    }

    // ── SCIM ─────────────────────────────────────────────────────────────────

    public function testContractScimDiscovery(): void
    {
        $tenantId = $this->newTenant();
        [$status, $body, $type] = $this->scim('GET', '/scim/v2/ServiceProviderConfig', $tenantId);
        self::assertSame(200, $status);
        self::assertStringStartsWith('application/scim+json', $type);
        self::assertTrue($body['patch']['supported']);
        self::assertTrue($body['filter']['supported']);

        [$status, $body] = $this->scim('GET', '/scim/v2/ResourceTypes', $tenantId);
        self::assertSame(200, $status);
        self::assertContains('User', array_column($body['Resources'], 'id'));

        [$status, $body] = $this->scim('GET', '/scim/v2/Schemas', $tenantId);
        self::assertSame(200, $status);
        self::assertContains(ScimUser::EXTENSION_SCHEMA, array_column($body['Resources'], 'id'));
    }

    public function testContractScimUsers(): void
    {
        $tenantId = $this->newTenant();
        $suffix   = $this->uid();
        $resource = $this->contractScimUser($suffix);

        [$status, $user] = $this->scim('POST', '/scim/v2/Users', $tenantId, $resource);
        self::assertSame(201, $status);
        self::assertIsString($user['id']);
        self::assertSame($resource['userName'], $user['userName']);
        self::assertSame($resource['externalId'], $user['externalId']);
        self::assertSame($resource[ScimUser::EXTENSION_SCHEMA]['slsUserId'], $user[ScimUser::EXTENSION_SCHEMA]['slsUserId']);
        self::assertEqualsCanonicalizing($this->contractRoles(), $user[ScimUser::EXTENSION_SCHEMA]['roles']);
        $id = $user['id'];

        [$status, $body] = $this->scim('POST', '/scim/v2/Users', $tenantId, $resource);
        self::assertSame(409, $status, 'A second user with the same userName is a conflict.');
        self::assertSame('uniqueness', $body['scimType']);
        self::assertSame('urn:ietf:params:scim:api:messages:2.0:Error', $body['schemas'][0]);

        [$status, $body] = $this->scim('GET', '/scim/v2/Users/' . $id, $tenantId);
        self::assertSame(200, $status);
        self::assertSame($id, $body['id']);

        [$status, $body] = $this->scim('GET', '/scim/v2/Users?filter=' . rawurlencode('externalId eq "' . $resource['externalId'] . '"'), $tenantId);
        self::assertSame(200, $status);
        self::assertSame(1, $body['totalResults']);
        self::assertSame($id, $body['Resources'][0]['id']);

        [$status, $body] = $this->scim('GET', '/scim/v2/Users?filter=' . rawurlencode(ScimUser::EXTENSION_SCHEMA . ':slsUserId eq "sls-user-' . $suffix . '"'), $tenantId);
        self::assertSame(200, $status);
        self::assertSame(1, $body['totalResults']);

        [$status, $body] = $this->scim('GET', '/scim/v2/Users?filter=' . rawurlencode('userName eq "nobody-' . $suffix . '@example.com"'), $tenantId);
        self::assertSame(200, $status);
        self::assertSame(0, $body['totalResults']);

        [$status, $body] = $this->scim('GET', '/scim/v2/Users?filter=' . rawurlencode('userName eq "a" or userName eq "b"'), $tenantId);
        self::assertSame(400, $status);
        self::assertSame('invalidFilter', $body['scimType']);

        [$status, $body] = $this->scim('PATCH', '/scim/v2/Users/' . $id, $tenantId, [
            'schemas'    => [ScimPatch::SCHEMA],
            'Operations' => [
                ['op' => 'replace', 'path' => 'active', 'value' => false],
                ['op' => 'replace', 'path' => ScimUser::EXTENSION_SCHEMA . ':roles', 'value' => []],
                ['op' => 'replace', 'path' => 'name.givenName', 'value' => 'Patched'],
            ],
        ]);
        self::assertSame(200, $status);
        self::assertFalse($body['active']);
        self::assertSame([], $body[ScimUser::EXTENSION_SCHEMA]['roles']);
        self::assertSame('Patched', $body['name']['givenName']);

        $resource['active']                                 = true;
        $resource['name']['familyName']                     = 'Replaced';
        [$status, $body] = $this->scim('PUT', '/scim/v2/Users/' . $id, $tenantId, $resource);
        self::assertSame(200, $status);
        self::assertTrue($body['active']);
        self::assertSame('Replaced', $body['name']['familyName']);
        self::assertEqualsCanonicalizing($this->contractRoles(), $body[ScimUser::EXTENSION_SCHEMA]['roles']);

        // Another tenant must not see the user.
        [$status] = $this->scim('GET', '/scim/v2/Users/' . $id, $this->newTenant());
        self::assertSame(404, $status);

        [$status] = $this->scim('DELETE', '/scim/v2/Users/' . $id, $tenantId);
        self::assertSame(204, $status);
        [$status, $body] = $this->scim('GET', '/scim/v2/Users/' . $id, $tenantId);
        self::assertSame(404, $status);
        self::assertSame('404', $body['status']);
    }

    public function testContractScimRequiresTenant(): void
    {
        [$status, $body] = $this->call('GET', '/scim/v2/Users', $this->serviceToken(['sls:scim']));
        self::assertSame(403, $status);
        self::assertSame('403', $body['status']);

        [$status] = $this->call('GET', '/scim/v2/Users', $this->serviceToken(['sls:provision'], $this->newTenant()));
        self::assertSame(403, $status);
    }

    public function testContractScimGroups(): void
    {
        if (!$this->contractSupportsGroups()) {
            self::markTestSkipped('The app does not implement SCIM Groups.');
        }
        $tenantId = $this->newTenant();
        [, $user] = $this->scim('POST', '/scim/v2/Users', $tenantId, $this->contractScimUser($this->uid()));

        [$status, $group] = $this->scim('POST', '/scim/v2/Groups', $tenantId, [
            'schemas'     => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
            'displayName' => 'Contract group',
            'members'     => [],
        ]);
        self::assertSame(201, $status);

        [$status, $body] = $this->scim('PATCH', '/scim/v2/Groups/' . $group['id'], $tenantId, [
            'schemas'    => [ScimPatch::SCHEMA],
            'Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => $user['id']]]]],
        ]);
        self::assertSame(200, $status);
        self::assertSame([$user['id']], array_column($body['members'], 'value'));

        [$status, $body] = $this->scim('PATCH', '/scim/v2/Groups/' . $group['id'], $tenantId, [
            'schemas'    => [ScimPatch::SCHEMA],
            'Operations' => [['op' => 'remove', 'path' => 'members[value eq "' . $user['id'] . '"]']],
        ]);
        self::assertSame(200, $status);
        self::assertSame([], $body['members'] ?? []);

        [$status] = $this->scim('DELETE', '/scim/v2/Groups/' . $group['id'], $tenantId);
        self::assertSame(204, $status);
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    public function testContractWebhooks(): void
    {
        $event = json_encode([
            'event_id'    => 'evt-' . $this->uid(),
            'type'        => 'organization.updated',
            'occurred_at' => gmdate(DATE_ATOM),
            'org_id'      => $this->uid(),
            'data'        => ['name' => 'Renamed'],
        ], JSON_THROW_ON_ERROR);

        self::assertSame([200, 'received'], $this->webhook($event));
        self::assertSame([200, 'duplicate'], $this->webhook($event), 'Redeliveries are acknowledged but not processed again.');
        self::assertSame(401, $this->webhook($event, signature: 'sha256=' . str_repeat('0', 64))[0]);
        self::assertSame(401, $this->webhook($event, timestamp: time() - 3600)[0]);
        self::assertSame(401, $this->webhook($event . ' ', signature: WebhookSignature::sign($this->webhookSecret(), time(), $event))[0]);
        self::assertSame(400, $this->webhook('{"type": "x"}')[0]);
    }

    /** A brokered event the app doesn't consume is acknowledged, not refused (0.3). */
    public function testContractAppEventNotConsumed(): void
    {
        self::assertSame([200, 'received'], $this->appEvent('zzcontract.thing.happened', ['n' => 1]));
    }

    // ── Back-channel logout ──────────────────────────────────────────────────

    public function testContractBackchannelLogout(): void
    {
        $clientId = (string) $this->param('sls_connector.client_id');
        $good     = $this->tokens->logoutToken($this->issuer(), $clientId, 'sls-user-' . $this->uid(), 'sid-' . $this->uid());

        $this->client->request('POST', '/sls/oidc/backchannel-logout', ['logout_token' => $good]);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $bad = [
            'missing'   => null,
            'wrong aud' => $this->tokens->logoutToken($this->issuer(), 'other-client', 'u', 's'),
            'nonce'     => $this->tokens->logoutToken($this->issuer(), $clientId, 'u', 's', ['nonce' => 'n']),
            'no events' => $this->tokens->logoutToken($this->issuer(), $clientId, 'u', 's', ['events' => null]),
            'id token'  => $this->tokens->idToken($this->issuer(), $clientId, 'u', 'n'),
        ];
        foreach ($bad as $case => $token) {
            $this->client->request('POST', '/sls/oidc/backchannel-logout', $token === null ? [] : ['logout_token' => $token]);
            self::assertResponseStatusCodeSame(400, $case);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Posts a signed event another app sent through SLS (doc 09 "Events between apps", 0.3): the
     * envelope SLS builds, with `source` naming the sender. Listeners get it as `SlsAppEvent`
     * (`sls.event.<type>`) when the type is in `events.consumes`.
     *
     * @param array<string, mixed>   $data
     * @param array<string, ?string> $source merged over a default sender (`connection_id`, `app`,
     *                                       `instance_id`, `company_id`, `company_name`, `tenant_id`)
     *
     * @return array{0: int, 1: ?string} status, `status` field of the answer
     */
    protected function appEvent(string $type, array $data = [], array $source = [], ?string $tenantId = null, ?string $eventId = null): array
    {
        $app = $source['app'] ?? strstr($type, '.', true);

        return $this->webhook(json_encode([
            'event_id'    => $eventId ?? 'evt-' . $this->uid(),
            'type'        => $type,
            'occurred_at' => gmdate(DATE_ATOM),
            'org_id'      => 'org-' . $this->uid(),
            'tenant_id'   => $tenantId,
            'data'        => (object) $data,
            'source'      => $source + [
                'connection_id' => 'conn-' . $this->uid(),
                'app'           => $app,
                'instance_id'   => 'inst-' . $this->uid(),
                'company_id'    => 'co-' . $this->uid(),
                'company_name'  => 'Sibling Lab',
                'tenant_id'     => 'sibling-tenant-' . $this->uid(),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A sibling app's token for this app over a link (doc 09 10.4): `tenant_id` is the tenant here,
     * `caller_tenant_id` the sibling's own tenant, `scope` the app-link scopes granted.
     *
     * @param string[] $scopes
     */
    protected function linkToken(array $scopes, string $tenantId, ?string $callerTenantId = null, string $app = 'sibling'): string
    {
        return $this->tokens->linkToken($this->issuer(), $this->audience(), $scopes, $tenantId, $callerTenantId, $app);
    }

    /**
     * A partner app's token for this app (doc 09 §2b): {@see self::linkToken()} plus the
     * `partnership_id` and `role` claims.
     *
     * @param string[] $scopes
     */
    protected function partnerToken(array $scopes, string $tenantId, string $role, ?string $partnershipId = null, ?string $callerTenantId = null, string $app = 'sibling'): string
    {
        $token = $this->tokens->partnerToken($this->issuer(), $this->audience(), $scopes, $tenantId, $role, $partnershipId, $callerTenantId, $app);
        $this->rememberPartnerToken($token, true);

        return $token;
    }

    /**
     * Record SLS's introspection answer for a partner token (0.3.3), so the app accepts
     * (`$active`) or refuses it without asking SLS. {@see self::partnerToken()} records `true`.
     * Tokens minted elsewhere (e.g. `SlsTestTokens::appToken()` with a `partnership_id`) need this
     * too, or `sls_connector.api.introspect_partner_tokens: false` in the test environment.
     */
    protected function rememberPartnerToken(string $token, bool $active): void
    {
        $container = static::getContainer();
        if ($container->has(PartnerTokenIntrospector::class)) {
            $container->get(PartnerTokenIntrospector::class)->remember($token, $active);
        }
    }

    /** @param string[] $scopes */
    protected function serviceToken(array $scopes, ?string $tenantId = null): string
    {
        return $this->tokens->serviceToken($this->issuer(), $this->audience(), $scopes, $tenantId);
    }

    /** Creates a tenant through the provisioning API and returns its id. */
    protected function newTenant(): string
    {
        [$status, $body] = $this->call('POST', '/sls/provisioning/tenants', $this->serviceToken(['sls:provision']), $this->contractTenantRequest($this->uid()));
        self::assertContains($status, [200, 201], 'Creating a tenant failed: ' . json_encode($body));

        return $body['tenant_id'];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{0: int, 1: mixed, 2: string} status, decoded body, content type
     */
    protected function call(string $method, string $uri, ?string $token, ?array $body = null, string $contentType = 'application/json'): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if ($body !== null) {
            $server['CONTENT_TYPE'] = $contentType;
        }
        $this->client->request($method, $uri, [], [], $server, $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        $response = $this->client->getResponse();
        $content  = (string) $response->getContent();

        return [$response->getStatusCode(), $content !== '' ? json_decode($content, true) : null, (string) $response->headers->get('Content-Type')];
    }

    /** @return array{0: int, 1: mixed, 2: string} */
    protected function scim(string $method, string $uri, string $tenantId, ?array $body = null): array
    {
        return $this->call($method, $uri, $this->serviceToken(['sls:scim'], $tenantId), $body, 'application/scim+json');
    }

    /** @return array{0: int, 1: ?string} status, `status` field of the answer */
    protected function webhook(string $body, ?int $timestamp = null, ?string $signature = null): array
    {
        $timestamp ??= time();
        $this->client->request('POST', '/sls/webhooks', [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X_SLS_TIMESTAMP'  => (string) $timestamp,
            'HTTP_X_SLS_SIGNATURE'  => $signature ?? WebhookSignature::sign($this->webhookSecret(), $timestamp, $body),
        ], $body);
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)['status'] ?? null];
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> the resolved `sls_connector` config */
    protected function config(): array
    {
        return $this->param('sls_connector.config');
    }

    protected function issuer(): string
    {
        return (string) $this->param('sls_connector.issuer');
    }

    protected function audience(): string
    {
        return (string) $this->param('sls_connector.audience');
    }

    protected function webhookSecret(): string
    {
        return (string) $this->param('sls_connector.webhook_secret');
    }

    protected function param(string $name): mixed
    {
        return static::getContainer()->getParameter($name);
    }

    protected function uid(): string
    {
        return bin2hex(random_bytes(6));
    }
}
