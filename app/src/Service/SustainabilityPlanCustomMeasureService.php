<?php

namespace App\Service;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SustainabilityPlanCustomMeasureService
{
    public function __construct(
        private readonly SustainabilityPlanCustomMeasureParser $parser,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly string $fromEmail,
        private ?string $notifyEmail,
    ) {
        $this->notifyEmail = trim((string) $this->notifyEmail);
    }

    /**
     * @return array<int, array{
     *     title: string,
     *     description: string,
     *     score: int|null,
     *     state: string,
     *     raw: string
     * }>
     */
    public function addCustomMeasure(
        Plan $plan,
        Project $project,
        ?User $user,
        string $title,
        string $description
    ): array {
        $normalizedTitle = $this->normalizeValue($title);
        $normalizedDescription = $this->normalizeValue($description);

        if ($normalizedTitle === '') {
            return $this->parser->parse($plan->getCustomMeasures());
        }

        $serializedMeasure = implode(' | ', [
            $normalizedTitle,
            $normalizedDescription,
            '',
            'planned',
        ]);

        $existing = trim((string) $plan->getCustomMeasures());
        $plan->setCustomMeasures(
            $existing !== ''
                ? $existing . "\n" . $serializedMeasure
                : $serializedMeasure
        );

        $this->sendNotification($project, $plan, $user, $normalizedTitle, $normalizedDescription);

        return $this->parser->parse($plan->getCustomMeasures());
    }

    private function normalizeValue(string $value): string
    {
        $normalized = str_replace(["\r", "\n", '|'], ' ', trim($value));

        return trim((string) preg_replace('/\s+/u', ' ', $normalized));
    }

    private function sendNotification(
        Project $project,
        Plan $plan,
        ?User $user,
        string $title,
        string $description
    ): void {
        if ($this->notifyEmail === '') {
            $this->logger->warning('Custom measure notification skipped because CONTACT_EMAIL is not configured.');
            return;
        }

        $projectName = trim((string) ($project->getName() ?? ''));
        $planId = $plan->getId();
        $userLabel = $this->buildUserLabel($user);
        $date = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));

        try {
            $email = (new Email())
                ->from($this->fromEmail)
                ->to($this->notifyEmail)
                ->subject($this->translator->trans('backend.plan.custom_measures.notification.subject', [
                    '%project%' => $projectName !== '' ? $projectName : '—',
                ]))
                ->text($this->translator->trans('backend.plan.custom_measures.notification.body', [
                    '%project%' => $projectName !== '' ? $projectName : '—',
                    '%plan%' => $planId !== null ? (string) $planId : '—',
                    '%user%' => $userLabel !== '' ? $userLabel : '—',
                    '%title%' => $title,
                    '%description%' => $description !== '' ? $description : '—',
                    '%date%' => $date->format('d/m/Y H:i'),
                ]));

            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            $this->logger->warning('Failed to send custom measure notification email.', [
                'exception' => $exception,
                'project_id' => $project->getId(),
                'plan_id' => $planId,
            ]);
        }
    }

    private function buildUserLabel(?User $user): string
    {
        if (!$user instanceof User) {
            return '';
        }

        $fullName = trim((string) $user->getName() . ' ' . (string) $user->getSurnames());
        if ($fullName !== '') {
            return $fullName;
        }

        return (string) ($user->getEmail() ?? '');
    }
}
