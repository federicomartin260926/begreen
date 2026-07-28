<?php

namespace App\Tests\Service;

use App\Entity\CrewMember;
use App\Entity\Project;
use App\Repository\CrewMemberRepository;
use App\Service\SustainabilityPlanClosureEmailRecipientResolver;
use PHPUnit\Framework\TestCase;

final class SustainabilityPlanClosureEmailRecipientResolverTest extends TestCase
{
    public function testResolvesOnlyMembersFromTheActiveProject(): void
    {
        $project = new Project();
        $member = (new CrewMember())->setProject($project)->setName('Ana')->setEmail('ana@example.test');
        $this->setEntityId($member, 7);

        $repository = $this->createMock(CrewMemberRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['project' => $project, 'id' => [7]])
            ->willReturn([$member]);

        $resolver = new SustainabilityPlanClosureEmailRecipientResolver($repository);

        self::assertSame([$member], $resolver->resolve($project, ['7']));
    }

    public function testRejectsAnIdentifierNotResolvedInsideTheActiveProject(): void
    {
        $project = new Project();
        $repository = $this->createMock(CrewMemberRepository::class);
        $repository->method('findBy')->willReturn([]);
        $resolver = new SustainabilityPlanClosureEmailRecipientResolver($repository);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($project, ['99']);
    }

    public function testRejectsMalformedIdentifiersBeforeQuerying(): void
    {
        $project = new Project();
        $repository = $this->createMock(CrewMemberRepository::class);
        $repository->expects(self::never())->method('findBy');
        $resolver = new SustainabilityPlanClosureEmailRecipientResolver($repository);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolve($project, ['7 OR 1=1']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
