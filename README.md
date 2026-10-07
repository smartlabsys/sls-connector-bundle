# SLS connector bundle

`smartlabsys/sls-connector-bundle` implements the app side of the **SLS app contract v2** (the company is the tenant), the
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

> **0.3.0.** Contract 2 (tenants per SLS company), links between connections, and events between
> apps and sibling
> endpoint helpers, all backward compatible: `SlsClient::emit()`, `Event\SlsAppEvent`
> (`sls.event.<type>`), `Provisioning\CompanyUpdatedHandlerInterface`, `#[SlsSibling]` with
> `Security\SlsTenantResolverInterface`, `callSibling(connectionId:)`, and the test kit's
> `appEvent()` / `linkToken()`. Needs SLS with 10.8 (event broker) for `emit()`.
>
> Partnerships (SLS 10.9): `integration.partnership_roles`,
> `SlsClient::directory()` / `partnerships()` / `proposePartnership()` / `redeemInvite()` /
> `acceptPartnership()` / `declinePartnership()` / `endPartnership()` (`SlsPartnerException`),
> `SlsAppUser::$partnershipId` / `$partnershipRole`, the discovery cache dropped on `partnership.*`,
> and the test kit's `partnerToken()`.
>
> Claim codes (SLS Phase 13): `tenants.claim_code`, `Provisioning\ClaimCodes`,
> `TenantRequest::$claimCode`; see [Claiming an existing company](#claiming-an-existing-company-claim-codes).

## Install

```bash
composer config repositories.sls-connector vcs https://github.com/smartlabsys/sls-connector-bundle
composer require smartlabsys/sls-connector-bundle:^0.1
```

To work on the bundle and an app side by side, point the app at a local checkout instead
(`composer config repositories.sls-connector path ../sls-connector-bundle`) — just don't commit that.

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
    events:                                  # events between apps (0.3) — optional
        emits: ['qc.request.created']        # own events SLS brokers to linked apps: "<app key>." prefix
        consumes: ['lims.request.updated']  # other apps' events this app wants (SlsAppEvent)
    integration:                             # app links (doc 09) — optional
        provides:                            # what siblings may be allowed to do here
            - { scope: 'qc:requests.read', label: { en: Read requests, sr: Čitanje zahteva } }
        uses:                                # siblings' scopes this app calls with
            - { app: lims, scopes: ['lims:requests.write'] }
        partnership_roles:                   # partnerships (SLS 10.9) — optional
            - key: 'qc:laboratory'           # "<app key>:" prefix
              label: { en: Laboratory, sr: Laboratorija }
              provider_apps: [lims]          # apps that may take the role (empty = any)
              provider_scopes: ['qc:requests.read']     # what the provider gets here: from `provides`
              customer_scopes: ['lims:requests.write']  # what this app gets back on the provider
    endpoints: { api: /api, mcp: null }      # optional manifest endpoints
    tenants:
        claim_code: false                    # true: SLS may claim a standalone company with a claim code
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
app prefix, labels need an `en` entry, and endpoints must be absolute paths. Event types are
dotted lowercase (`qc.request.created`, ≤ 64 characters). Scopes look like
`<app>:<resource>.<action>`: provided ones carry this app's key, used ones the sibling's.

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
`organizationId`, `tenantId` and `callerTenantId`, the caller's own tenant). Otherwise such
tokens are refused. SLS service tokens are
always refused there.

**Link scopes.** The token carries the scopes the link from the caller's connection to yours
grants (all the ones it `uses` that you `provide`, minus any an org admin took away). Guard sibling endpoints with
them:

```php
#[IsGranted('SLS_SCOPE:qc:requests.read')]
```

`SLS_SCOPE:` checks an `SlsAppUser`'s scopes, or the claims of a user token a sibling exchanged
for this app. A session login never has them, so don't put it on the app's own pages. While the
apps declare no scopes for each other, SLS still lets the call through without any, as before.

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
| `Provisioning\TenantProvisionerInterface` | provisioning | `create()` is idempotent on `TenantRequest::$slsCompanyId` (contract 2; 201 new / 200 existing), or on `sls_org_id` when a contract 1 platform sends no company. A tenant made under contract 1 (org only) is linked to the first company of that org that asks; see the interface for the lookup order. `TenantRequest::$company` (`CompanyDetails`) carries the company's details: SLS owns them once connected, and `company.updated` (`sls.webhook.company.updated`) brings changes. A dedicated install returns its one tenant. `TenantRequest::$claimOwnerEmail` (the connecting user's verified e-mail) lets you link an existing, not-yet-linked tenant that user owns instead of creating a duplicate (201). `TenantRequest::$claimCode` (`claim.code`) claims the company that issued the code; see [Claiming an existing company](#claiming-an-existing-company-claim-codes). |
| `Provisioning\TenantPreviewInterface` | tenant preview (optional) | `preview()` answers `POST /tenants/preview`: what `create()` would do for the same request (`existing` / `claim` / `create`, with the tenant for the first two) without writing anything. SLS's connect wizard shows it ("your existing company will be linked"). Without it the endpoint answers 501 and SLS shows a neutral message. Implement it on your provisioner with the same lookup `create()` uses. |
| `Provisioning\SeedHandlerInterface` | seed templates (optional) | `start()` queues the work and returns a `SeedJob`. The endpoint answers 202. |
| `Provisioning\CancellableSeedHandlerInterface` | cancelling seeds (optional) | Extends the seed handler with `cancel()`: stop a queued / running job (`DELETE …/seeds/{job}` → 204, unknown job → 404). Without it the endpoint answers 501 and SLS just stops tracking the job. |
| `Scim\ScimUserMapperInterface` | SCIM users | CRUD on `ScimUser`. Throw `ContractException::conflict(…, 'uniqueness')` for a duplicate `userName`. |
| `Scim\ScimGroupMapperInterface` | SCIM groups (optional) | Without it, `/Groups` answers 501. |
| `Health\HealthCheckInterface` | health (optional) | Any failing check reports the instance as `degraded`. |
| `Provisioning\CompanyUpdatedHandlerInterface` | company details (optional, 0.3) | `companyUpdated($tenantId, CompanyDetails)` on each `company.updated` webhook with a named company. Saves writing the listener yourself. |
| `Security\SlsTenantResolverInterface` | `#[SlsSibling]` (optional, 0.3) | `resolveTenant($tenantId)` → your tenant object (a `Company`, …) or null → 404 `tenant_not_found`. |

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
`organization.updated`, `link.created|updated|removed` (to both connections of a link, with
`{link_id, caller, target, enabled, scopes}`). `tenant_id` is your tenant for the org. The bundle
drops its cached discovery answers on `connection.*` and `link.*` events.

### Events between apps (0.3)

An app announces its own events with `emit()`; SLS (10.8) delivers them to every connection
linked to the sender (either direction, link on) whose manifest lists the type under
`events.consumes`. The type must start with this app's key and be listed under `events.emits`
(`InvalidArgumentException` otherwise); `data` is at most 64 KB.

```php
$eventId = $client->emit('qc.request.created', $tenantId, ['request_id' => $id]); // returns the event id
$client->emit('qc.request.created', $tenantId, $data, $stableId);                 // idempotent resend
```

On the receiving side the envelope carries `source` (the sender: `connection_id`, `app`,
`instance_id`, `company_id`, `company_name`, `tenant_id`). For a type under `events.consumes` the
bundle dispatches, after the usual `sls.webhook*` events, `Event\SlsAppEvent` as `sls.event` and
`sls.event.<type>`. Other brokered types are acknowledged and logged, not dispatched.

```php
#[AsEventListener('sls.event.qc.request.created')]
public function onRequest(SlsAppEvent $event): void
{
    $request = $this->client->callSibling($event->organizationId, 'qc', 'GET', '/requests/' . $event->data['request_id'],
        connectionId: $event->source['connection_id'], tenantId: $event->tenantId);
}
```

### Sibling endpoints (0.3)

`#[SlsSibling(scope: 'qc:requests.read')]` on a controller class or action, for calls from sibling
apps (`SlsAppUser`): without the scope the answer is 403 `{"error":"forbidden"}`; with a
`SlsTenantResolverInterface` the token's `tenant_id` is resolved (404 `tenant_not_found` when
null) and handed to an argument typed as your tenant class. Users pass through untouched (check
them as usual).

```php
#[Route('/api/sibling/requests', methods: ['GET'])]
#[SlsSibling(scope: 'qc:requests.read')]
public function list(?Company $company = null): JsonResponse { /* $company: the caller's tenant here */ }
```

### Calling SLS and sibling apps

```php
$client->serviceToken(['sls:read']);                          // client credentials, cached
$client->connections($orgId);                                 // discovery, cached 5 min
$client->callSibling($orgId, 'lims', 'GET', '/samples');      // as this app
$client->callSibling($orgId, 'lims', 'GET', '/samples', [], OidcLoginFlow::accessToken($session)); // as the user
$client->callSibling($orgId, 'lims', 'GET', '/samples', instanceId: $instanceId); // one of several LIMS instances
$client->links($tenantId);                                    // what this tenant is linked to, cached 5 min
$client->callLink($tenantId, 'qc', 'POST', '/requests', ['json' => $body]);       // by app key or connection id
$client->callLink($tenantId, $connectionId, 'GET', '/requests', [], $userToken);  // as the user
$client->callSibling($orgId, 'qc', 'GET', '/requests', connectionId: $connId);    // one exact connection (0.3)
$client->callSibling($orgId, 'qc', 'GET', '/requests', connectionId: $connId, tenantId: $tenantId); // = callLink()
$client->emit('lims.sample.received', $tenantId, $data);      // an event for linked apps (0.3)
$client->seedCompleted($tenantId, $job);                      // an async seed job finished
$client->userCreated($tenantId, $scimUser);                   // a user was created locally
$client->sendEvent('some.event', $tenantId, $data);           // any event SLS accepts
```

- **As this app**, the sibling gets a token with `aud` = the sibling, `sub` = your client id and
  `tenant_id` = the org's tenant *there*. SLS issues it only while both apps are connected to the
  org, and refuses it (`invalid_target`) without a link or once an org admin switches it off. The
  token gets every scope the link grants; the cached token keeps them until it expires (≤ 15 min).
- **Links** (SLS 10.4) go from one connection to another. Inside one company they are on by
  default; between companies (Lab A's LIMS → the org's QC) an org admin creates them. When your
  app has several tenants in one org (one per company), use `links()` / `callLink()` with the
  tenant the call is for: the token names both connections (`target_connection`,
  `caller_tenant_id`), and the receiving app sees `SlsAppUser::$callerTenantId`.
  `callSibling()` still works while the org has one connection per app.
- **As the user**, SLS swaps the user's access token (RFC 8693 token exchange). The new token
  carries the user's roles in the sibling, `tenant_id` there, and `act: {sub, app, instance_id}`
  naming your app. SLS refuses it if the user isn't assigned to the sibling.
- **Where the user's token comes from:** after "Sign in with Smartlab" it is kept in the session.
  `OidcLoginFlow::accessToken($session)` returns it, or null once it has expired; the user then
  signs in again.
- **Partners** (SLS 10.9): a partnership joins a connection of your app with one of another
  app — often in another organization — in a role. Once it is active the partner shows up in
  `links()` (with `partnership: {id, role, side}`), `callLink()` works across organizations, and
  the receiving app sees `SlsAppUser::$partnershipId` / `$partnershipRole`. Partners also receive
  each other's events. The partner API calls use a plain service token with the `sls:` scopes:

  ```php
  $client->directory('qc:laboratory', $tenantId);              // providers listed for this tenant's org
  $client->partnerships($tenantId);                            // any status
  $client->proposePartnership('qc:laboratory', $tenantId, $connectionId); // pending (active in one org)
  $client->redeemInvite('ABCD-EFGH-JKLM', $tenantId);          // active at once
  $client->acceptPartnership($id); $client->declinePartnership($id); $client->endPartnership($id);
  ```

  Ask SLS to allow `sls:directory.read`, `sls:partnerships.read` and `sls:partnerships.manage`
  for your OAuth client. `partnership.*` webhooks reach both sides and drop the cached links.
- **Errors:**
  - A refused token request throws `SlsTokenException` (`invalid_target` / `invalid_grant`).
  - A refused partner API call throws `SlsPartnerException` (`status`, `errors`).
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

## Claiming an existing company (claim codes)

A company that already uses the app on its own (standalone: linked to no SLS org or company) can
come under an SLS organization without losing its data. The company's admin proves ownership with a
**claim code** the app issues; SLS sends it back when it connects the company. This is an additive
extension of contract 2: apps that don't implement it keep working (`claim.owner_email` stays).

**Issuing a code.** A company admin of a standalone company presses "Create a claim code" (a
"Connect to SLS" page in the app's settings). `Provisioning\ClaimCodes` does the mechanics:

```php
use Smartlabsys\SlsConnectorBundle\Provisioning\ClaimCodes;

$code = ClaimCodes::generate();          // "ABCD-EFGH-JKLM": 12 chars of ABCDEFGHJKLMNPQRSTUVWXYZ23456789
$company->slsClaimCodeHash      = ClaimCodes::hash($code);        // sha256 of the normalized code — store only this
$company->slsClaimCodeExpiresAt = ClaimCodes::expiresAt();        // now + 30 minutes (ClaimCodes::TTL_SECONDS)
// show $code once; a new code replaces the old one (one live code per company)

ClaimCodes::normalize(' abcd-efgh jklm ');   // "ABCDEFGHJKLM": uppercase, no spaces or dashes
ClaimCodes::isWellFormed('ABCDEFGHJKLM');    // length + alphabet check of a normalized code
ClaimCodes::verify($input, $storedHash);     // hash_equals() against the stored hash
```

Keep who created it and when it was used next to the hash. A company already connected to SLS
can't create a code.

**Sign-in variant: `/sls/claim`.** Instead of typing the code, the SLS wizard can send the admin to
`{instance base URL}/sls/claim?return_to=<SLS URL>&state=<opaque>&org_name=<SLS org name>`. The app
requires a signed-in company admin (normal login), asks "Connect <company> to SLS organization
<org_name>?", and on Confirm creates a code as above and redirects to
`return_to?code=<code>&state=<state>` (`state` unchanged). `return_to` must start with the
configured SLS issuer (`%sls_connector.issuer%`); refuse anything else (open redirect). The bundle
doesn't ship this controller yet: apps on v0.1 implement it locally.

**`claim.code` in `POST /tenants` and `POST /tenants/preview`.** SLS sends
`"claim": {"owner_email": "…", "code": "ABCD-EFGH-JKLM"}` (either may be absent); the bundle passes
the code, trimmed, as `TenantRequest::$claimCode` (null when absent or empty). Lookup order:

1. the tenant linked to `sls_company_id`;
2. the org's tenant without a company (made before contract 2);
3. **claim by code**: the code's hash matches a live (unexpired, unused) code whose company is still
   standalone;
4. claim by `owner_email`;
5. create.

A code that doesn't match, has expired, was used, or whose company is already connected answers
**`422 {"error": "invalid_claim_code", "message": …}`** from both preview and create: throw
`ContractException::invalidClaimCode()` (or `new ContractException(422, 'invalid_claim_code', …)`).
Never fall through to creating a tenant — the admin asked to claim.

**Preview.** For a claim by code, answer with the app's current details and its local users, so
the wizard can show a per-field merge table and link users by e-mail:

```php
return TenantPreview::claimByCode($tenant, $companyDetails, [
    ['id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'active' => $user->active, 'roles' => ['qc:analyst']],
]);
// {"action": "claim", "tenant": {…}, "details": {name, tax_id, registration_number, public_funds_id,
//   address, city, postal_code, country}, "users": [{id, email, name, active, roles}]}
```

`details` has the `company` block's keys (null where the app has no such field; `country` ISO-2 or
null). `users` are all the company's local users, active or not, at most 500
(`TenantPreview::MAX_USERS`). A claim by `owner_email` keeps the old shape (no `details` / `users`);
both are serialized only when set.

**Create.** A valid code links the company to `sls_org_id` / `sls_company_id` as the existing claim
does, **marks the code used**, then overwrites the SLS-managed fields with the `company` block
(SLS sends the merged details) and answers `201` with `{tenant_id, status, name}`. A repeat
`POST /tenants` with the same `sls_company_id` is found by step 1 and answers `200`; the used code
isn't checked again. Log "Company claimed by SLS organization …" (and add it to the app's audit
trail if it has one).

**Manifest.** Declare support so SLS offers "Already uses <app> — claim it":

```yaml
sls_connector:
    tenants: { claim_code: true }        # manifest: "tenants": {"claim_code": true}
```

Nothing is emitted when it's false, so other manifests are unchanged. Apps still on bundle v0.1
add `"tenants": {"claim_code": true}` through their `ManifestExtras` shim
(`app.sls_manifest_extras`).

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
tests. For your own event and sibling tests (0.3) the case offers `appEvent($type, $data, $source)`
(a signed brokered event) and `linkToken($scopes, $tenantId, $callerTenantId)` (a sibling's token
over a link), and `partnerToken($scopes, $tenantId, $role, $partnershipId)` (the same over a
partnership, with the `partnership_id` and `role` claims).

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
