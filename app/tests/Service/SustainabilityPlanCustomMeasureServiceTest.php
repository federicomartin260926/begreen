<?php

namespace App\Tests\Service;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\User;
use App\Service\SustainabilityPlanCustomMeasureParser;
use App\Service\SustainabilityPlanCustomMeasureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SustainabilityPlanCustomMeasureServiceTest extends TestCase
{
    public function testAddCustomMeasureAppendsToPlanAndSendsNotification(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                self::assertSame('noreply@example.com', $email->getFrom()[0]->getAddress());
                self::assertSame(['notify@example.com'], array_map(
                    static fn ($address): string => $address->getAddress(),
                    $email->getTo()
                ));
                self::assertSame('Nueva medida personalizada en Proyecto Demo', $email->getSubject());
                self::assertStringContainsString('Título: Instalar paneles solares', (string) $email->getTextBody());

                return true;
            }));

        $service = $this->createService($mailer, 'notify@example.com');

        $plan = (new Plan())->setCustomMeasures(null);
        $project = (new Project())->setName('Proyecto Demo');
        $user = (new User())
            ->setName('Ana')
            ->setSurnames('García')
            ->setEmail('ana@example.com');

        $items = $service->addCustomMeasure(
            $plan,
            $project,
            $user,
            '  Instalar paneles solares  ',
            "Reducir consumo\neléctrico"
        );

        self::assertCount(1, $items);
        self::assertSame('Instalar paneles solares', $items[0]['title']);
        self::assertSame('Reducir consumo eléctrico', $items[0]['description']);
        self::assertSame('planned', $items[0]['state']);
        self::assertSame("Instalar paneles solares | Reducir consumo eléctrico |  | planned", $plan->getCustomMeasures());
    }

    public function testAddCustomMeasureSkipsNotificationWhenEmailIsMissing(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::callback(static fn (string $message): bool => str_contains($message, 'CONTACT_EMAIL')));

        $service = $this->createService($mailer, '', $logger);

        $plan = (new Plan())->setCustomMeasures(null);
        $project = (new Project())->setName('Proyecto Demo');

        $items = $service->addCustomMeasure(
            $plan,
            $project,
            null,
            'Medida propia',
            'Descripción'
        );

        self::assertCount(1, $items);
        self::assertSame("Medida propia | Descripción |  | planned", $plan->getCustomMeasures());
    }

    private function createService(
        MailerInterface $mailer,
        string $notifyEmail,
        ?LoggerInterface $logger = null
    ): SustainabilityPlanCustomMeasureService {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []): string {
            return match ($id) {
                'backend.plan.custom_measures.notification.subject' => 'Nueva medida personalizada en ' . ($parameters['%project%'] ?? ''),
                'backend.plan.custom_measures.notification.body' => sprintf(
                    "Proyecto: %s\nPlan: %s\nUsuario: %s\nTítulo: %s\nDescripción: %s\nFecha: %s",
                    $parameters['%project%'] ?? '',
                    $parameters['%plan%'] ?? '',
                    $parameters['%user%'] ?? '',
                    $parameters['%title%'] ?? '',
                    $parameters['%description%'] ?? '',
                    $parameters['%date%'] ?? ''
                ),
                default => $id,
            };
        });

        return new SustainabilityPlanCustomMeasureService(
            new SustainabilityPlanCustomMeasureParser(),
            $mailer,
            $logger ?? $this->createMock(LoggerInterface::class),
            $translator,
            'noreply@example.com',
            $notifyEmail
        );
    }
}
