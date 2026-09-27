<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Client;

/**
 * SLS refused an event sent with {@see SlsClient::sendEvent()} (4xx): unknown tenant or seed job,
 * unsupported type, invalid data. Sending it again won't help.
 */
final class SlsEventException extends \RuntimeException
{
    /** @param string[] $errors SLS's (translated) error messages */
    public function __construct(public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct(sprintf('SLS refused the event (HTTP %d)%s', $status, $errors ? ': ' . implode('; ', $errors) : '.'));
    }
}
