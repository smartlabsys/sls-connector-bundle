<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final class DemoUser implements UserInterface
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly ?string $name,
        public readonly ?string $slsSub,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self($row['id'], $row['email'], $row['name'] ?? null, $row['sls_sub'] ?? null);
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void {}
}
