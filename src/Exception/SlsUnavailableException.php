<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Exception;

/** SLS could not be reached, or answered something unexpected (discovery, JWKS, token endpoint). */
final class SlsUnavailableException extends \RuntimeException
{
}
