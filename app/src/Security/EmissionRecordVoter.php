<?php

namespace App\Security;

use App\Entity\EmissionRecord;
use App\Entity\User;
use App\Repository\ProjectMembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class EmissionRecordVoter extends Voter
{
    public const VIEW = 'EMISSION_VIEW';
    public const EDIT = 'EMISSION_EDIT';

    public function __construct(private readonly ProjectMembershipRepository $pmRepo) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof EmissionRecord;
    }

    /** @param EmissionRecord $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) return false;

        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return true;
        }

        $record  = $subject;
        $project = $record->getProject();
        if (!$project) return false;

        // Miembro del proyecto => OK para ver/editar
        return $this->pmRepo->isMember($project, $user);
    }
}
