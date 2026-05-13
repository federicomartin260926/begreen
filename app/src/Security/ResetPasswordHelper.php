<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ResetPasswordHelper
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator
    ) {}

    public function generateToken(User $user): string
    {
        $token = Uuid::v4()->toRfc4122();
        $user->setResetToken($token);
        $user->setResetTokenExpiresAt((new \DateTime())->modify('+1 hour'));
        $this->em->flush();

        return $token;
    }

    public function sendResetEmail(User $user): void
    {
        $token = $this->generateToken($user);

        $resetUrl = $this->router->generate('app_reset_password', [
            'token' => $token
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->from('noreply@begreenmyfriend.com')
            ->to($user->getEmail())
            ->subject($this->translator->trans('reset_password.email_subject'))
            ->html($this->translator->trans('reset_password.email_message', ['%resetUrl%' => $resetUrl]));

        $this->mailer->send($email);
    }

    public function validateToken(?User $user): bool
    {
        if (!$user || !$user->getResetToken() || !$user->getResetTokenExpiresAt()) {
            return false;
        }

        return new \DateTime() <= $user->getResetTokenExpiresAt();
    }

    public function clearToken(User $user): void
    {
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $this->em->flush();
    }
}
