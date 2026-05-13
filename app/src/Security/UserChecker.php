<?php 

namespace App\Security;

use App\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            throw new CustomUserMessageAccountStatusException(
                $this->translator->trans('error.account_not_verified', [], 'security')
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Puedes agregar lógica posterior a la autenticación si necesitas
    }
}
