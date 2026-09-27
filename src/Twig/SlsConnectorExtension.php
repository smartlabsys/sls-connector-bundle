<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `sls_login_url(target = null)` — the href of the "Sign in with Smartlab" button. */
final class SlsConnectorExtension extends AbstractExtension
{
    public function __construct(private UrlGeneratorInterface $urls) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('sls_login_url', $this->loginUrl(...))];
    }

    public function loginUrl(?string $target = null): string
    {
        return $this->urls->generate('sls_connector.oidc_login', array_filter(['target' => $target]));
    }
}
