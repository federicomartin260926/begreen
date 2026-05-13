<?php
namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private string $defaultLocale = 'es') {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        if (!$request->hasSession()) return;

        // 1) Capturar locale desde query o atributos de ruta
        $incomingLocale = $request->query->get('_locale')
            ?? $request->attributes->get('_locale');

        if ($incomingLocale) {
            $request->getSession()->set('_locale', $incomingLocale);
        }

        $locale = $request->getSession()->get('_locale', $this->defaultLocale);
        $request->setLocale($locale);

        // 2) Si es BACKEND y la URL trae /{_locale} al principio, redirigir sin el prefijo
        //    Ajusta el detector a tus prefijos/nombres de ruta:
        $routeName = (string) $request->attributes->get('_route', '');
        $isBackendRoute = str_starts_with($routeName, 'backend_') || str_starts_with($routeName, 'admin_');

        // También puedes basarte en el path:
        $pathInfo = $request->getPathInfo(); // e.g. /en/backend_emission_index
        $hasLeadingLocale = preg_match('#^/(en|es)(/|$)#i', $pathInfo) === 1;

        if ($isBackendRoute && $hasLeadingLocale) {
            // construir misma URL sin /{locale} inicial y sin _locale en query
            $strippedPath = preg_replace('#^/(en|es)(?=/|$)#i', '', $pathInfo) ?: '/';

            // reconstruir querystring sin _locale
            $qs = $request->query->all();
            unset($qs['_locale']);
            $queryString = $qs ? ('?' . http_build_query($qs)) : '';

            $target = $strippedPath . $queryString;

            // Evitar bucle si ya estamos en la versión limpia
            if ($target !== $request->getRequestUri()) {
                $event->setResponse(new RedirectResponse($target, 302));
            }
            return;
        }

        // 3) Si viene ?_locale= en backend, también limpiamos el query para dejar URL sin param
        if ($isBackendRoute && $request->query->has('_locale') && !$hasLeadingLocale) {
            $qs = $request->query->all();
            unset($qs['_locale']);
            $queryString = $qs ? ('?' . http_build_query($qs)) : '';
            $target = $request->getPathInfo() . $queryString;

            if ($target !== $request->getRequestUri()) {
                $event->setResponse(new RedirectResponse($target, 302));
            }
            return;
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }
}
