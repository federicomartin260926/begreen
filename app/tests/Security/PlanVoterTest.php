<?php

namespace App\Tests\Security;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMembershipRepository;
use App\Security\PlanVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PlanVoterTest extends TestCase
{
    public function testVerifierCanVerifyOnlyAssignedProjects(): void
    {
        $user = $this->userWithRole('ROLE_VERIFIER');
        $assignedProject = new Project();
        $otherProject = new Project();
        $assignedPlan = (new Plan())->setProject($assignedProject);
        $otherPlan = (new Plan())->setProject($otherProject);

        $memberships = $this->createMock(ProjectMembershipRepository::class);
        $memberships->expects(self::exactly(2))
            ->method('isMember')
            ->willReturnCallback(
                static fn (Project $project, User $candidate): bool => $project === $assignedProject && $candidate === $user
            );

        $voter = new PlanVoter($memberships);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $assignedPlan, [PlanVoter::VERIFY]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $otherPlan, [PlanVoter::VERIFY]));
    }

    public function testAdminRolesKeepGlobalVerifyAccess(): void
    {
        $plan = (new Plan())->setProject(new Project());
        $memberships = $this->createMock(ProjectMembershipRepository::class);
        $memberships->expects(self::never())->method('isMember');
        $voter = new PlanVoter($memberships);

        foreach (['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as $role) {
            $user = $this->userWithRole($role);
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $plan, [PlanVoter::VERIFY]));
        }
    }

    public function testExistingUserAndManagerEditAccessIsUnchangedButVerifyCapabilityIsNotGranted(): void
    {
        $project = new Project();
        $plan = (new Plan())->setProject($project);
        $memberships = $this->createMock(ProjectMembershipRepository::class);
        $memberships->expects(self::exactly(2))
            ->method('isMember')
            ->willReturn(true);
        $voter = new PlanVoter($memberships);

        foreach (['ROLE_USER', 'ROLE_MANAGER'] as $role) {
            $user = $this->userWithRole($role);
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

            self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $plan, [PlanVoter::EDIT]));
            self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $plan, [PlanVoter::VERIFY]));
        }
    }

    private function userWithRole(string $role): User
    {
        return (new User())
            ->setName('Test')
            ->setSurnames('User')
            ->setEmail(strtolower($role).'@example.test')
            ->setPassword('password')
            ->setRoles([$role]);
    }
}
