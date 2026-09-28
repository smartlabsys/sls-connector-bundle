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
        - key: food-lab-basic
          version: 3
          label: { en: 'Food lab – basic', sr: 'Laboratorija za hranu – osnovno' }
          parameters:                        # string | choice | integer | boolean; texts localized like labels
            - { key: lab_name, type: string, required: true, label: { en: Lab name, sr: Naziv laboratorije } }
            - key: sample_set
              type: choice
              choices: [food, water]
              default: food
              label: { en: Sample types, sr: Vrste uzoraka }
              description: { en: Which sample types to create. }   # optional help text
              choice_labels: { food: { en: Food, sr: Hrana }, water: { en: Water, sr: Voda } }
    events: { emits: [], consumes: [] }
    endpoints: { api: /api, mcp: null }      # optional manifest endpoints
    api:
        accept_app_tokens: false             # let sibling apps call your API as themselves
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

With `api.accept_app_tokens: true`, a sibling app's own token (`sub` = its `client_id`) passes
the `api` firewall as a `Security\SlsAppUser` (`ROLE_SLS_APP`, with `app`, `instanceId`,
`organizationId` and `tenantId`). Otherwise such tokens are refused. SLS service tokens are
always refused there.

The back-channel logout endpoint records the revoked SLS session in the cache pool. On the
next request, a listener ends any local session that belongs to it, so use a cache pool
that all web nodes share.

### MCP

To put an MCP server behind SLS, set `endpoints.mcp` (e.g. `/mcp`) and give it its own firewall
with the bearer challenge:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        mcp:
            pattern: ^/mcp
            stateless: true
            access_token:
                token_handler: Smartlabsys\SlsConnectorBundle\Security\SlsUserTokenHandler
                failure_handler: Smartlabsys\SlsConnectorBundle\Security\SlsBearerChallenge
            entry_point: Smartlabsys\SlsConnectorBundle\Security\SlsBearerChallenge
    access_control:
        - { path: ^/mcp, roles: IS_AUTHENTICATED_FULLY }
```

- **Discovery.** Without a valid token the app answers 401 with
  `WWW-Authenticate: Bearer resource_metadata="<audience>/.well-known/oauth-protected-resource/mcp"`
  (plus `error="invalid_token"` when a token was sent). That RFC 9728 document is served by the
  bundle. It names SLS as the authorization server. `/.well-known/oauth-protected-resource`
  describes the app itself (`resource` = the audience).
- **Tokens.** MCP clients register at SLS (dynamic client registration) and ask for a token with
  `resource=<audience><mcp path>`. SLS issues it only to users assigned to this instance in an org
  connected to it. Its `aud` is your audience, so tokens for other apps or for SLS are refused.
  The claims (`tenant_id`, `roles`, `scope`, `client_id`) are on the request as `_sls_claims`, as
  on `/api`.
- **Protocol.** The bundle doesn't implement MCP itself. Use any MCP server library, or answer
  JSON-RPC on `POST /mcp` yourself (see the demo app's `McpController`).

## Implement

Write a class for each interface; autoconfiguration wires it in. If you have two
implementations of one interface, alias the interface to the one you want.

| Interface | Required for | Notes |
|---|---|---|
| `Security\SlsUserResolverInterface` | SSO, user tokens | `resolveOidcUser(SlsIdentity)`: link by SLS `sub` → verified email → create. `loadBySlsUserId()` for API tokens. |
| `Provisioning\TenantProvisionerInterface` | provisioning | `create()` is idempotent on `sls_org_id` (201 new / 200 existing). A dedicated install returns its one tenant. |
| `Provisioning\SeedHandlerInterface` | seed templates (optional) | `start()` queues the work and returns a `SeedJob`. The endpoint answers 202. |
| `Provisioning\CancellableSeedHandlerInterface` | cancelling seeds (optional) | Extends the seed handler with `cancel()`: stop a queued / running job (`DELETE …/seeds/{job}` → 204, unknown job → 404). Without it the endpoint answers 501 and SLS just stops tracking the job. |
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
keeps a record of processed events for 7 days. Answer 2xx quickly: SLS retries anything else
after 1, 5, 15 and 60 minutes.

SLS sends `connection.created|suspended|resumed|disconnected` (to every app of the org, so you
learn about siblings), `user.assigned|updated|unassigned` (to the assignment's app) and
`organization.updated`. `tenant_id` is your tenant for the org. The bundle drops its cached
discovery answer on `connection.*` events.

### Calling SLS and sibling apps

```php
$client->serviceToken(['sls:read']);                          // client credentials, cached
$client->connections($orgId);                                 // discovery, cached 5 min
$client->callSibling($orgId, 'lims', 'GET', '/samples');      // as this app
$client->callSibling($orgId, 'lims', 'GET', '/samples', [], OidcLoginFlow::accessToken($session)); // as the user
$client->seedCompleted($tenantId, $job);                      // an async seed job finished
$client->userCreated($tenantId, $scimUser);                   // a user was created locally
$client->sendEvent('some.event', $tenantId, $data);           // any event SLS accepts
```

- **As this app**, the sibling gets a token with `aud` = the sibling, `sub` = your client id and
  `tenant_id` = the org's tenant *there*. SLS issues it only while both apps are connected to the
  org.
- **As the user**, SLS swaps the user's access token (RFC 8693 token exchange). The new token
  carries the user's roles in the sibling, `tenant_id` there, and `act: {sub, app, instance_id}`
  naming your app. SLS refuses it if the user isn't assigned to the sibling.
- **Where the user's token comes from:** after "Sign in with Smartlab" it is kept in the session.
  `OidcLoginFlow::accessToken($session)` returns it, or null once it has expired; the user then
  signs in again.
- **Errors:**
  - A refused token request throws `SlsTokenException` (`invalid_target` / `invalid_grant`).
  - A refused event throws `SlsEventException` (unknown tenant or job, unsupported type).
  - SLS being unreachable throws `SlsUnavailableException`.

Report async seeds with `seedCompleted()` once the job ends. SLS also keeps polling
`GET /seeds/{job}` as a fallback.

### Reporting local users

If your app lets people register or edit users locally (outside SLS), tell SLS so the org's
Owners / Admins can see them and bring them under SLS management ("Unmanaged in <app>" on the
connection page, then **Adopt**):

```php
$client->userCreated($tenantId, $scimUser);   // ScimUser with at least id + userName
$client->userUpdated($tenantId, $scimUser);
$client->userDeleted($tenantId, $appUserId);
```

- Send them **only for local changes**, never for changes SLS made through SCIM. (SLS answers an
  echo of its own push with `duplicate`, so a mistake doesn't loop, but it costs a request.)
- For a user SLS manages (`slsUserId` set) a local change doesn't stick: SLS compares it with
  what it pushed and, if it differs, pushes its version again (`resynced`). A managed user deleted
  locally is created again while SLS still assigns it.
- The answer is `received`, `duplicate`, `resynced` or `ignored` (an `slsUserId` SLS doesn't
  assign here). Events are rate-limited per instance (600 / minute → 429,
  `SlsEventException`).
- **`GET /Users` must list local users too.** SLS's nightly drift check reads the full listing
  and records users without `slsUserId` / `externalId` as unmanaged — so an app that never sends
  events is still covered, just a day later.
- On **Adopt**, SLS PUTs its user onto the existing app user (same `id`), setting `slsUserId`;
  from then on it's managed like any other.

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

To try sibling calls, run a second demo app (`demo2`, own store and session cookie) with its own
registration bundle in `tests/App/.env.dev2.local` (`SLS_AUDIENCE=http://127.0.0.1:8091`):

```bash
APP_ENV=dev2 php -S 127.0.0.1:8091 -t tests/App/public tests/App/public/index.php
```

`/siblings` lists the org's other apps and calls their `/api/ping` as the app or as the user.
With `DEMO_SEED_ASYNC=1`, seed jobs stay queued until
`php tests/App/bin-console.php demo:seed:complete <tenant> <job> [--fail]` finishes them and
sends `seed.completed` to SLS. `demo:user:create|update|delete <tenant> …` change local users and
send `user.*` events (`--local-only` skips the event, to try the drift check).
