<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

/**
 * What {@see \Smartlabsys\SlsConnectorBundle\Provisioning\TenantPreviewInterface::preview()} returns:
 * what creating the tenant would do right now, without doing it.
 */
final class TenantPreview
{
    /** The org already has a tenant — create would return it. */
    public const ACTION_EXISTING = 'existing';
    /** An existing, unlinked tenant owned by the claim e-mail would be linked. */
    public const ACTION_CLAIM = 'claim';
    /** A new tenant would be created. */
    public const ACTION_CREATE = 'create';

    /** At most this many `users` are sent. */
    public const MAX_USERS = 500;

    /**
     * @param Tenant|null                    $tenant  the tenant that would be returned or linked; null for ACTION_CREATE
     * @param CompanyDetails|null            $details Phase 13, claim by code only: the company's current details in the app
     * @param list<array{id: string, email: ?string, name: ?string, active: bool, roles: list<string>}>|null $users
     *                                                Phase 13, claim by code only: the company's local users (all, active or not)
     */
    public function __construct(
        public readonly string $action,
        public readonly ?Tenant $tenant = null,
        public readonly ?CompanyDetails $details = null,
        public readonly ?array $users = null,
    ) {
        if (!in_array($action, [self::ACTION_EXISTING, self::ACTION_CLAIM, self::ACTION_CREATE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown tenant preview action "%s".', $action));
        }
        if (($tenant === null) !== ($action === self::ACTION_CREATE)) {
            throw new \InvalidArgumentException('A tenant is required for "existing" and "claim", and not allowed for "create".');
        }
        if (($details !== null || $users !== null) && $action !== self::ACTION_CLAIM) {
            throw new \InvalidArgumentException('"details" and "users" are only for a "claim" preview (by claim code).');
        }
        if ($users !== null && (!array_is_list($users) || count($users) > self::MAX_USERS)) {
            throw new \InvalidArgumentException(sprintf('"users" must be a list of at most %d users.', self::MAX_USERS));
        }
    }

    /**
     * Claim by code (Phase 13): the claim preview with the app's current company details and its local users.
     *
     * @param list<array{id: string, email: ?string, name: ?string, active: bool, roles: list<string>}> $users
     */
    public static function claimByCode(Tenant $tenant, CompanyDetails $details, array $users): self
    {
        return new self(self::ACTION_CLAIM, $tenant, $details, array_values($users));
    }

    /** @return array<string, mixed> `details` and `users` only when set */
    public function toArray(): array
    {
        return ['action' => $this->action, 'tenant' => $this->tenant?->toArray()]
            + ($this->details !== null ? ['details' => $this->details->toArray()] : [])
            + ($this->users !== null ? ['users' => array_map(static fn (array $user): array => [
                'id'     => (string) $user['id'],
                'email'  => $user['email'] ?? null,
                'name'   => $user['name'] ?? null,
                'active' => (bool) ($user['active'] ?? true),
                'roles'  => array_values($user['roles'] ?? []),
            ], $this->users)] : []);
    }
}
