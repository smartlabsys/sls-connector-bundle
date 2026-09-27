<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

/** The SLS token endpoint refused a request (`error` / `error_description` from RFC 6749 §5.2). */
final class SlsTokenException extends \RuntimeException
{
    public function __construct(public readonly string $error, public readonly string $description = '')
    {
        parent::__construct(sprintf('SLS token request failed: %s%s', $error, $description !== '' ? ' — ' . $description : ''));
    }
}
