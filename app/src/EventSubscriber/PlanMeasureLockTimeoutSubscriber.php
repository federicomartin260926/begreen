<?php

namespace App\EventSubscriber;

use App\Exception\PlanMeasureLockTimeoutException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PlanMeasureLockTimeoutSubscriber implements EventSubscriberInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof PlanMeasureLockTimeoutException) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'success' => false,
            'retryable' => true,
            'error' => $this->translator->trans('backend.plan.concurrent_update'),
        ], JsonResponse::HTTP_CONFLICT));
    }
}
