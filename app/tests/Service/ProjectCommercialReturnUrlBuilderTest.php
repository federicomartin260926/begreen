<?php

namespace App\Tests\Service;

use App\Service\ProjectCommercialReturnUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProjectCommercialReturnUrlBuilderTest extends TestCase
{
    public function testKeepsTheOriginalLocalEnvironmentAndTheStripeSessionPlaceholder(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::exactly(2))->method('generate')->willReturnCallback(
            static function (string $route, array $parameters, int $type): string {
                self::assertSame(UrlGeneratorInterface::ABSOLUTE_PATH, $type);
                self::assertSame(['id' => 43], $parameters);
                return match ($route) {
                    'backend_project_commercial_checkout_success' => '/backend/project/43/commercial-checkout/success',
                    'backend_project_commercial_checkout_cancel' => '/backend/project/43/commercial-checkout/cancel',
                    default => throw new \LogicException('Unexpected route: ' . $route),
                };
            }
        );
        $builder = new ProjectCommercialReturnUrlBuilder(
            $router,
            'http://localhost:8080/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/success/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
            'http://localhost:8080/backend/project/{PROJECT_ID}/subscription/{COMMERCIAL_PHASE}/cancel/{TARGET_TIER}?session_id={CHECKOUT_SESSION_ID}',
        );

        self::assertSame([
            'success_url' => 'http://localhost:8080/backend/project/43/commercial-checkout/success?phase=implementation&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'http://localhost:8080/backend/project/43/commercial-checkout/cancel?phase=implementation',
        ], $builder->build(43, 'implementation'));
    }

    public function testRejectsNonLocalInsecureOrigins(): void
    {
        $builder = new ProjectCommercialReturnUrlBuilder(
            $this->createMock(UrlGeneratorInterface::class),
            'http://begreen.example.com/legacy',
            'http://begreen.example.com/legacy',
        );
        $this->expectException(\DomainException::class);
        $builder->build(43);
    }

    public function testRejectsOriginMismatch(): void
    {
        $builder = new ProjectCommercialReturnUrlBuilder(
            $this->createMock(UrlGeneratorInterface::class),
            'https://begreen.example.com/legacy',
            'https://elsewhere.example.com/legacy',
        );
        $this->expectException(\DomainException::class);
        $builder->build(43);
    }

    public function testRejectsInvalidPhaseBeforeGeneratingRoutes(): void
    {
        $builder = new ProjectCommercialReturnUrlBuilder(
            $this->createMock(UrlGeneratorInterface::class),
            'https://begreen.example.com/legacy',
            'https://begreen.example.com/legacy',
        );
        $this->expectException(\InvalidArgumentException::class);
        $builder->build(43, 'invalid');
    }
}
