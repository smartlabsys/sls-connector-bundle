<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Provisioning\Model;

/**
 * The SLS company a tenant belongs to (contract 2): the `company` object of `POST /tenants` and
 * of the `company.updated` webhook's `data.company`. SLS owns these details once the company is
 * connected; the app keeps a copy and shows those fields read-only.
 */
final class CompanyDetails
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $taxId = null,
        public readonly ?string $registrationNumber = null,
        public readonly ?string $publicFundsId = null,
        public readonly ?string $address = null,
        public readonly ?string $city = null,
        public readonly ?string $postalCode = null,
        /** ISO 3166-1 alpha-2 */
        public readonly ?string $country = null,
    ) {}

    /**
     * From the snake_case JSON object; null when it has no name.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $name = self::optionalString($data['name'] ?? null);
        if ($name === null) {
            return null;
        }

        return new self(
            $name,
            self::optionalString($data['tax_id'] ?? null),
            self::optionalString($data['registration_number'] ?? null),
            self::optionalString($data['public_funds_id'] ?? null),
            self::optionalString($data['address'] ?? null),
            self::optionalString($data['city'] ?? null),
            self::optionalString($data['postal_code'] ?? null),
            self::optionalString($data['country'] ?? null),
        );
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'name'                => $this->name,
            'tax_id'              => $this->taxId,
            'registration_number' => $this->registrationNumber,
            'public_funds_id'     => $this->publicFundsId,
            'address'             => $this->address,
            'city'                => $this->city,
            'postal_code'         => $this->postalCode,
            'country'             => $this->country,
        ];
    }

    private static function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
