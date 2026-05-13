<?php

namespace App\Security;

use App\Entity\Plan;
use App\Entity\User;
use App\Repository\ProjectMembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PlanVoter extends Voter
{
    public const VIEW = 'PLAN_VIEW';
    public const EDIT = 'PLAN_EDIT';

    public function __construct(private readonly ProjectMembershipRepository $pmRepo) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT], true) && $subject instanceof Plan;
    }

    /**
     * @param Plan $subject
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Admin / SuperAdmin siempre pueden
        $roles = $user->getRoles();
        if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            return true;
        }

        $plan = $subject;
        $project = $plan->getProject();
        if (!$project) {
            // Plan sin proyecto asociado => denegar por seguridad
            return false;
        }

        // Misma política que Project: cualquier miembro puede ver/editar
        return $this->pmRepo->isMember($project, $user);
    }
}
