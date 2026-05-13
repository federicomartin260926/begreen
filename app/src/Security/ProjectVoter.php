<?php

namespace App\Security;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ProjectVoter extends Voter
{
    public const VIEW = 'PROJECT_VIEW';
    public const EDIT = 'PROJECT_EDIT';

    public function __construct(private readonly ProjectMembershipRepository $membershipRepo) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof Project;
    }

    /**
     * @param Project $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false; // no autenticado
        }

        // Admin siempre permitido
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return true;
        }

        $project = $subject;

        // Miembro del proyecto => puede ver y editar
        return $this->membershipRepo->isMember($project, $user);
    }
}
