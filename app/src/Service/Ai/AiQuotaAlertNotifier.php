<?php

namespace App\Service\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class AiQuotaAlertNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly AiReportConfiguration $configuration,
        private readonly string $environment,
        private readonly string $fromEmail,
    ) {
    }

    public function notify(string $provider, string $model, string $errorCode): void
    {
        $alertEmail = trim($this->configuration->alertEmail);
        $context = [
            'provider' => $provider,
            'model' => $model,
            'error_type' => 'quota_alert',
            'error_code' => $errorCode,
        ];

        if ($alertEmail === '') {
            $this->logger->warning('AI quota alert email skipped because AI_ALERT_EMAIL is not configured.', $context);

            return;
        }

        $date = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
        $body = implode("\n", [
            'Provider: '.$provider,
            'Model: '.$model,
            'Environment: '.$this->environment,
            'Date: '.$date->format(DATE_ATOM),
            'Error code: '.$errorCode,
        ]);

        try {
            $this->mailer->send(
                (new Email())
                    ->from($this->fromEmail)
                    ->to($alertEmail)
                    ->subject('AI provider quota alert')
                    ->text($body),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to send AI quota alert email.', [
                ...$context,
                'error_type' => 'quota_alert_email_failure',
                'exception_type' => $exception::class,
            ]);
        }
    }
}
