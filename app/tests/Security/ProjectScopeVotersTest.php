<?php

namespace App\Tests\Security;

use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMembershipRepository;
use App\Security\EmissionRecordVoter;
use App\Security\ProjectVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ProjectScopeVotersTest extends TestCase
{
    public function testVerifierProjectAndEmissionAccessFollowMembership(): void
    {
        $user = (new User())
            ->setName('Verifier')
            ->setSurnames('Scoped')
            ->setEmail('verifier.scoped@example.test')
            ->setPassword('password')
            ->setRoles(['ROLE_VERIFIER']);
        $assignedProject = new Project();
        $otherProject = new Project();
        $assignedRecord = (new EmissionRecord())->setProject($assignedProject);
        $otherRecord = (new EmissionRecord())->setProject($otherProject);

        $memberships = $this->createMock(ProjectMembershipRepository::class);
        $memberships->expects(self::exactly(4))
            ->method('isMember')
            ->willReturnCallback(
                static fn (Project $project, User $candidate): bool => $project === $assignedProject && $candidate === $user
            );

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $projectVoter = new ProjectVoter($memberships);
        $emissionVoter = new EmissionRecordVoter($memberships);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $projectVoter->vote($token, $assignedProject, [ProjectVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $projectVoter->vote($token, $otherProject, [ProjectVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $emissionVoter->vote($token, $assignedRecord, [EmissionRecordVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $emissionVoter->vote($token, $otherRecord, [EmissionRecordVoter::EDIT]));
    }
}
