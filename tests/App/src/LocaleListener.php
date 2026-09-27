<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Tests\App;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** The EN · SR links: `?_locale=en|sr`, kept in the session (like SLS's LocaleSubscriber). */
#[AsEventListener(KernelEvents::REQUEST, priority: 20)]
final class LocaleListener
{
    private const LOCALES = ['en', 'sr'];

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $locale  = $request->query->get('_locale');
        if (in_array($locale, self::LOCALES, true)) {
            $session->set('_locale', $locale);
        }
        $request->setLocale($session->get('_locale', 'en'));
    }
}
