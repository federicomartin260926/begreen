<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PlanController;
use App\Entity\PlanMeasure;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class PlanControllerCriticalReasonTest extends KernelTestCase
{
    public function testMissingCriticalReasonReturnsValidationError(): void
    {
        $controller = $this->getController();
        $planMeasure = (new PlanMeasure())->setIsCritical(true);

        $response = $this->invokeValidateCriticalReasonField($controller, $planMeasure, '');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertSame('Debes escribir un motivo cuando la medida es crítica.', $payload['error']);
    }

    public function testCriticalMeasureCannotProceedToImplementWithoutReason(): void
    {
        $controller = $this->getController();
        $planMeasure = (new PlanMeasure())->setIsCritical(true);

        $response = $this->invokeValidateCriticalReasonBeforeImplementing($controller, $planMeasure);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertSame('Debes escribir un motivo cuando la medida es crítica.', $payload['error']);
    }

    private function getController(): PlanController
    {
        self::bootKernel();

        /** @var PlanController $controller */
        $controller = self::getContainer()->get(PlanController::class);

        return $controller;
    }

    private function invokeValidateCriticalReasonField(
        PlanController $controller,
        PlanMeasure $planMeasure,
        string $text
    ): ?JsonResponse {
        $reflection = new \ReflectionMethod($controller, 'validateCriticalReasonField');
        $reflection->setAccessible(true);

        /** @var JsonResponse|null $response */
        $response = $reflection->invoke($controller, $planMeasure, $text);

        return $response;
    }

    private function invokeValidateCriticalReasonBeforeImplementing(
        PlanController $controller,
        PlanMeasure $planMeasure
    ): ?JsonResponse {
        $reflection = new \ReflectionMethod($controller, 'validateCriticalReasonBeforeImplementing');
        $reflection->setAccessible(true);

        /** @var JsonResponse|null $response */
        $response = $reflection->invoke($controller, $planMeasure);

        return $response;
    }
}
