<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Exception;

/**
 * An error answer on one of the contract endpoints: HTTP status + a machine-readable code
 * (`{"error": code, "message": …}`, or the SCIM error schema on `/scim/v2`).
 */
class ContractException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        string $message = '',
        public readonly ?string $scimType = null,
    ) {
        parent::__construct($message !== '' ? $message : $error);
    }

    public static function notFound(string $error, string $message = ''): self
    {
        return new self(404, $error, $message);
    }

    public static function badRequest(string $message, ?string $scimType = null): self
    {
        return new self(400, 'invalid_request', $message, $scimType);
    }

    public static function conflict(string $message, string $error = 'conflict'): self
    {
        return new self(409, $error, $message, 'uniqueness');
    }

    /**
     * Phase 13: `claim.code` doesn't match a live (unexpired, unused) code of a standalone company.
     * Answer it from both `create()` and `preview()` — never fall through to creating a tenant.
     */
    public static function invalidClaimCode(string $message = 'The claim code is invalid, expired or already used.'): self
    {
        return new self(422, 'invalid_claim_code', $message);
    }

    public static function notImplemented(string $message): self
    {
        return new self(501, 'not_implemented', $message);
    }
}
