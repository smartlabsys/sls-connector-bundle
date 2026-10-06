<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

/**
 * SLS refused a partner API call (doc 09 §2b, `/api/partner/v1`, 4xx): a missing `sls:` scope (403),
 * a tenant or partnership it doesn't know (404), or a business rule — not listed, already exists,
 * invite invalid or expired (400). `$errors` holds SLS's translated messages.
 */
final class SlsPartnerException extends \RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct(sprintf('SLS refused the partner call (HTTP %d)%s', $status, $errors ? ': ' . implode('; ', $errors) : '.'));
    }
}
