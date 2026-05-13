<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;

class UserFixtures extends Fixture implements FixtureGroupInterface
{
    private UserPasswordHasherInterface $hasher;

    public function __construct(UserPasswordHasherInterface $hasher)
    {
        $this->hasher = $hasher;
    }

    public static function getGroups(): array
    {
        return ['user'];
    }

    public function load(ObjectManager $manager): void
    {
        $users = [
            [
                'name' => 'Fede',
                'surnames' => 'Peña López',
                'email' => 'federicomartin2004@gmail.com',
                'isVerified' => true,
                'roles' => [
                    'ROLE_USER',
                    'ROLE_SUPER_ADMIN',
                    'ROLE_ALLOWED_TO_SWITCH',
                ]
            ],
            [
                'name' => 'Franc',
                'surnames' => 'Planas',
                'email' => 'francplanas@gmail.com',
                'isVerified' => true,
                'roles' => [
                    'ROLE_USER',
                    'ROLE_SUPER_ADMIN',
                    'ROLE_ALLOWED_TO_SWITCH',
                ]
            ],
            [
                'name' => 'Alfredo',
                'surnames' => 'Molina',
                'email' => 'alfredjmolina@gmail.com',
                'isVerified' => true,
                'roles' => [
                    'ROLE_USER',
                    'ROLE_SUPER_ADMIN',
                    'ROLE_ALLOWED_TO_SWITCH',
                ]
            ],
            [
                'name' => 'Gestor',
                'surnames' => 'Test',
                'email' => 'gestor@gmail.com',
                'isVerified' => true,
                'roles' => [
                    'ROLE_USER',
                    'ROLE_MANAGER'
                ]
            ],
            [
                'name' => 'Editor',
                'surnames' => 'Test',
                'email' => 'editor@gmail.com',
                'isVerified' => true,
                'roles' => [
                    'ROLE_USER'
                ]
            ]
        ];

        foreach ($users as $userData) {
            $user = new User();
            $user->setName($userData['name']);
            $user->setSurnames($userData['surnames']);
            $user->setEmail($userData['email']);
            $user->setIsVerified($userData['isVerified']);
            $user->setRoles($userData['roles']);

            $hashedPassword = $this->hasher->hashPassword($user, '123456');
            $user->setPassword($hashedPassword);

            $manager->persist($user);
        }

        $manager->flush();
    }
}
