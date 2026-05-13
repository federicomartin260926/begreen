<?php

// src/Command/UserTestCommand.php
namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'app:user:test',
    description: 'Crea un usuario de prueba y lo muestra'
)]
class UserTestCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword(password_hash('1234', PASSWORD_BCRYPT));
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        $output->writeln('Usuario creado con ID: '.$user->getId());

        $userRepo = $this->em->getRepository(User::class);
        $fetchedUser = $userRepo->findOneBy(['email' => 'test@example.com']);

        if ($fetchedUser) {
            $output->writeln('Usuario recuperado: '.$fetchedUser->getEmail().' | Verificado: '.($fetchedUser->isVerified() ? 'Sí' : 'No'));
        } else {
            $output->writeln('No se encontró el usuario.');
        }

        return Command::SUCCESS;
    }
}
