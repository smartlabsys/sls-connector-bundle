# SLS connector bundle

`smartlabsys/sls-connector-bundle` implements the app side of the **SLS app contract v1**, the
contract between the Smartlab Systems platform (SLS, `platform.smartlabsys.com`) and each
connected app (QC, LIMS, …). You install the bundle, implement a few interfaces, and the app
then has:

| Feature | Endpoint | Auth |
|---|---|---|
| Manifest | `GET /.well-known/sls-app.json` | public |
| Health | `GET /sls/health` | SLS service token, `sls:health` |
| Provisioning API | `/sls/provisioning/tenants[/…]` | service token, `sls:provision` |
| SCIM 2.0 server | `/scim/v2/…` | service token, `sls:scim` + `tenant_id` |
| Webhook receiver | `POST /sls/webhooks` | HMAC signature |
| "Sign in with Smartlab" | `GET /sls/oidc/login`, `/sls/oidc/callback` | OIDC code + PKCE |
| Back-channel logout | `POST /sls/oidc/backchannel-logout` | SLS logout token |
| App → SLS calls | `SlsClient` | client credentials / token exchange |

The contract itself is specified in the SLS repo: `docs/platform/05-app-contract.md` and
`09-inter-app-wiring.md`.

Requirements: PHP ≥ 8.2 and Symfony 7.4 (framework, security, http-client, cache).

## Install

```bash
composer config repositories.sls-connector path ../sls-connector-bundle   # until it is on a registry
composer require smartlabsys/sls-connector-bundle
```

```php
// config/bundles.php
Smartlabsys\SlsConnectorBundle\SlsConnectorBundle::class => ['all' => true],
```

```yaml
# config/routes/sls_connector.yaml
sls_connector:
    resource: '@SlsConnectorBundle/config/routes.php'
```

## Configure

When you register the instance in SLS (Admin → Apps → Instances), SLS shows a one-time
registration bundle. Put those values in the app's environment:

```dotenv
SLS_ISSUER=https://platform.smartlabsys.com
SLS_INSTANCE_ID=…
SLS_AUDIENCE=https://qc.example.com      # this instance's base URL
SLS_CLIENT_ID=…
SLS_CLIENT_SECRET=…
SLS_WEBHOOK_SECRET=…
```

```yaml
# config/packages/sls_connector.yaml
sls_connector:
    issuer:         '%env(SLS_ISSUER)%'
    instance_id:    '%env(SLS_INSTANCE_ID)%'
    audience:       '%env(SLS_AUDIENCE)%'
    client_id:      '%env(SLS_CLIENT_ID)%'
    client_secret:  '%env(SLS_CLIENT_SECRET)%'
    webhook_secret: '%env(SLS_WEBHOOK_SECRET)%'
    app: { key: qc, name: SmartQC, version: '2.4.0' }
    roles:                                   # keys must start with "<app key>:"
        - { key: 'qc:analyst', label: { en: Analyst, sr: Analitičar } }
    seed_templates:
        - { key: food-lab-basic, version: 3, label: { en: 'Food lab – basic' }, parameters: [] }
    events: { emits: [], consumes: [] }
    endpoints: { api: /api, mcp: null }      # optional manifest endpoints
    oidc:
        scopes: 'openid profile email org apps'
        default_target_path: /
        failure_path: /login
        rp_logout: true                      # local logout also ends the SLS session
    # jwks_file: null                        # read SLS keys from a file (air-gapped installs)
    # cache_pool: cache.app
    # http_client: http_client
```

The configuration is validated when the container is built. The rules match the ones SLS
applies to manifests: the app key must match `^[a-z][a-z0-9_-]{1,31}$`, role keys must carry the
app prefix, labels need an `en` entry, and endpoints must be absolute paths.

After deploying, run `bin/console sls:connector:warmup`. It fetches and caches SLS's discovery
document and JWKS, so the first token validation doesn't have to call SLS.

## Security

```yaml
# config/packages/security.yaml
security:
    firewalls:
        sls_service:                 # SLS → app service calls
            pattern: ^/(sls/(health|provisioning)|scim/v2)
            stateless: true
            access_token:
                token_handler: Smartlabsys\SlsConnectorBundle\Security\SlsServiceTokenHandler
        api:                         # optional: SLS user access tokens on your API/MCP
            pattern: ^/api
            stateless: true
            access_token:
                token_handler: Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler
        main:
            lazy: true
            custom_authenticators:
                - Smartlabsys\SlsConnectorBundle\Security\SlsOidcAuthenticator
            logout: { path: /logout }
```

Each bundle endpoint also checks the token's scope and tenant itself, so a misconfigured
firewall can't open it up. The webhook, manifest, OIDC and back-channel logout endpoints
authenticate themselves and must stay outside `access_control` rules that require a login.

For the login button, call the `sls_login_url()` Twig function (with an optional target path):

```twig
<a href="{{ sls_login_url() }}">{{ 'sls.login.button'|trans }}</a>
```

The back-channel logout endpoint records the revoked SLS session in the cache pool. On the
next request, a listener ends any local session that belongs to it, so use a cache pool
that all web nodes share.

## Implement

Write a class for each interface; autoconfiguration wires it in. If you have two
implementations of one interface, alias the interface to the one you want.

| Interface | Required for | Notes |
|---|---|---|
| `Security\SlsUserResolverInterface` | SSO, user tokens | `resolveOidcUser(SlsIdentity)`: link by SLS `sub` → verified email → create. `loadBySlsUserId()` for API tokens. |
| `Provisioning\TenantProvisionerInterface` | provisioning | `create()` is idempotent on `sls_org_id` (201 new / 200 existing). A dedicated install returns its one tenant. |
| `Provisioning\SeedHandlerInterface` | seed templates (optional) | `start()` queues the work and returns a `SeedJob`. The endpoint answers 202. |
| `Scim\ScimUserMapperInterface` | SCIM users | CRUD on `ScimUser`. Throw `ContractException::conflict(…, 'uniqueness')` for a duplicate `userName`. |
| `Scim\ScimGroupMapperInterface` | SCIM groups (optional) | Without it, `/Groups` answers 501. |
| `Health\HealthCheckInterface` | health (optional) | Any failing check reports the instance as `degraded`. |

If an optional interface has no implementation, its endpoint answers `501 not_implemented`.
The bundle handles the protocol for you: SCIM filtering (`eq ne co sw pr` joined by `and`),
applying PATCH (via get, apply, then `replace()`), list paging, error schemas, and webhook
deduplication.

### Webhooks

Verified events are dispatched twice: as `sls.webhook` and as `sls.webhook.<type>`. Both carry
`Webhook\SlsWebhookEvent`:

```php
#[AsEventListener('sls.webhook.user.assigned')]
public function onAssigned(SlsWebhookEvent $event): void { /* $event->data, ->tenantId, … */ }
```

The signature is `X-SLS-Signature: sha256=<hex HMAC-SHA256(SLS_WEBHOOK_SECRET, "{X-SLS-Timestamp}.{raw body}")>`,
and the timestamp must be within ±5 minutes. Each `event_id` is processed once, and the bundle
keeps a record of processed events for 7 days.

### Calling SLS and sibling apps

```php
$client->serviceToken(['sls:read']);                          // client credentials, cached
$client->connections($orgId);                                 // discovery, cached 5 min
$client->callSibling($orgId, 'lims', 'GET', '/samples');      // as this app
$client->callSibling($orgId, 'lims', 'GET', '/samples', [], $userAccessToken); // on behalf of a user
```

## Contract test suite

Every app should run the contract suite in CI:

```php
final class SlsContractTest extends \Smartlabsys\SlsConnectorBundle\Test\SlsContractTestCase
{
    protected function contractSeedParameters(): array { return ['lab_name' => 'Contract lab']; }
}
```

The suite generates a signing key and installs it through `jwks_file`, so in the test
environment set `sls_connector.jwks_file` to a writable path such as
`%kernel.project_dir%/var/test/sls-jwks.json`. It then mints SLS tokens and exercises the
manifest, health, token rejection, the tenant lifecycle, seeds, SCIM users and groups,
webhooks and back-channel logout. You can override hooks such as `contractTenantRequest()`,
`contractScimUser()`, `contractRoles()`, `contractSupportsGroups()` and
`contractSupportsSeeds()` to fit your app. `Test\SlsTestTokens` is also available for your own
tests.

## Developing the bundle

```bash
composer install
vendor/bin/phpunit                  # unit + contract suite against the demo app in tests/App
```

`tests/App` is a small demo app that implements every interface with a JSON file store. To run
it against a real SLS, put the registration bundle in `tests/App/.env.local` and then run:

```bash
php tests/App/bin-console.php sls:connector:warmup
php -S 127.0.0.1:8090 -t tests/App/public tests/App/public/index.php
```
