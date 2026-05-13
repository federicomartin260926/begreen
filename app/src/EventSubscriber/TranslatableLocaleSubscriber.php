<?php
// src/EventSubscriber/TranslatableLocaleSubscriber.php
namespace App\EventSubscriber;

use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class TranslatableLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private TranslatableListener $translatableListener) {}

    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;
        $request = $event->getRequest();
        $this->translatableListener->setTranslatableLocale($request->getLocale() ?? 'es');
    }
}
