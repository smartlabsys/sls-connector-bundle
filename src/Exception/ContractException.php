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

    public static function notImplemented(string $message): self
    {
        return new self(501, 'not_implemented', $message);
    }
}
