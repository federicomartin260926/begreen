<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\PlanMeasureLockTimeoutSubscriber;
use App\Exception\PlanMeasureLockTimeoutException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlanMeasureLockTimeoutSubscriberTest extends TestCase
{
    public function testConvertsContentionToRetryableConflictResponse(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('backend.plan.concurrent_update')
            ->willReturn('Reintenta en unos segundos.');
        $event = new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/backend/plan/update-selection', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new PlanMeasureLockTimeoutException()
        );

        (new PlanMeasureLockTimeoutSubscriber($translator))->onKernelException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame([
            'success' => false,
            'retryable' => true,
            'error' => 'Reintenta en unos segundos.',
        ], json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
