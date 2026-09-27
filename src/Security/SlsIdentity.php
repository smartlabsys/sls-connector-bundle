<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Security;

/**
 * Who just signed in with Smartlab: the validated ID token claims (plus UserInfo when the ID token
 * lacks the profile claims). `sub` is the stable SLS user id apps link local users to.
 */
final class SlsIdentity
{
    /** @param array<string, mixed> $claims */
    public function __construct(public readonly array $claims) {}

    public function subject(): string
    {
        return (string) $this->claims['sub'];
    }

    public function email(): ?string
    {
        return $this->string('email');
    }

    /** Only a verified email may be used to link an existing local account (doc 05 §2). */
    public function emailVerified(): bool
    {
        return ($this->claims['email_verified'] ?? false) === true;
    }

    public function name(): ?string
    {
        return $this->string('name');
    }

    public function givenName(): ?string
    {
        return $this->string('given_name');
    }

    public function familyName(): ?string
    {
        return $this->string('family_name');
    }

    public function locale(): ?string
    {
        return $this->string('locale');
    }

    public function organizationId(): ?string
    {
        return $this->string('org_id');
    }

    public function organizationSlug(): ?string
    {
        return $this->string('org_slug');
    }

    public function organizationName(): ?string
    {
        return $this->string('org_name');
    }

    /** This org's tenant id in this app (from the SLS Connection; present from 6.1). */
    public function tenantId(): ?string
    {
        return $this->string('tenant_id');
    }

    /** The SLS role in the chosen org: owner / admin / member. */
    public function slsRole(): ?string
    {
        return $this->string('sls_role');
    }

    /** @return string[] app roles from the Assignment, e.g. ["qc:analyst"] (present from 6.2) */
    public function roles(): array
    {
        $roles = $this->claims['roles'] ?? [];

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    /** The SLS session id — what back-channel logout refers to. */
    public function sessionId(): ?string
    {
        return $this->string('sid');
    }

    private function string(string $claim): ?string
    {
        $value = $this->claims[$claim] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
