<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:send-test-email',
    description: 'Envía un email de prueba usando Symfony Mailer y Gmail SMTP.',
)]
class SendTestEmailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer, // <-- inyectamos mailer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'Email destinatario');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipientEmail = $input->getArgument('email') ?: 'federicomartin2004@gmail.com';

        $io->note(sprintf('Email destinatario: %s', $recipientEmail));

        $email = (new Email())
            ->from('federicomartin2004@gmail.com')   // Cambiá por tu email Gmail real
            ->to($recipientEmail)
            ->subject('Email de prueba desde Symfony 7')
            ->text('¡Hola! Este es un email de prueba enviado desde Symfony 7 usando Gmail y contraseña de aplicación.');

        try {
            $this->mailer->send($email);
            $io->success('Email enviado correctamente.');
        } catch (\Throwable $e) {
            $io->error('Error enviando el email: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
