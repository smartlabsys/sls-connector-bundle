<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Jwt;

/** A JWT failed validation. The message is a short reason meant for logs, never for end users. */
final class InvalidTokenException extends \RuntimeException
{
}
