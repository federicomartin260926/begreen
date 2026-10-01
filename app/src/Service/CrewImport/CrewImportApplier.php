<?php

namespace App\Service\CrewImport;

use App\Entity\CrewDepartment;
use App\Entity\CrewMember;
use App\Entity\CrewMemberAssignment;
use App\Entity\CrewPosition;
use App\Entity\Project;
use App\Exception\CrewImport\CrewImportApplyException;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewMemberRepository;
use App\Repository\CrewPositionRepository;
use App\Service\CrewCatalogScopeResolver;
use App\Service\CrewImport\Dto\CrewImportAssignmentProposal;
use App\Service\CrewImport\Dto\CrewImportPersonProposal;
use App\Service\CrewImport\Dto\CrewImportProposal;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CrewImportApplier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CrewMemberRepository $crewMemberRepository,
        private CrewDepartmentRepository $departmentRepository,
        private CrewPositionRepository $positionRepository,
        private CrewCatalogScopeResolver $scopeResolver,
    ) {
    }

    /** @return list<CrewMember> */
    public function apply(Project $project, CrewImportProposal $proposal): array
    {
        $projectId = $project->getId();
        if ($projectId === null || $proposal->projectId !== $projectId) {
            throw new CrewImportApplyException('The crew import proposal belongs to a different project.');
        }
        if (!$proposal->isApplicable()) {
            throw new CrewImportApplyException('The crew import proposal contains rows requiring review.');
        }

        $scope = $this->scopeResolver->resolve($project);
        $prepared = [];

        // Validate the complete proposal before mutating any managed entity.
        foreach ($proposal->people as $person) {
            $member = null;
            if ($person->action === CrewImportPersonProposal::ASSOCIATE) {
                $member = $person->existingCrewMemberId !== null
                    ? $this->crewMemberRepository->find($person->existingCrewMemberId)
                    : null;
                if (!$member instanceof CrewMember || $member->getProject()?->getId() !== $projectId) {
                    throw new CrewImportApplyException('The proposed crew member does not belong to this project.');
                }
            } elseif ($person->action !== CrewImportPersonProposal::CREATE || $person->existingCrewMemberId !== null) {
                throw new CrewImportApplyException('The crew import proposal contains an invalid person action.');
            }

            $assignments = [];
            foreach ($person->assignments as $assignment) {
                if ($assignment->resolutionStatus === CrewImportAssignmentProposal::NONE) {
                    if ($assignment->departmentId !== null || $assignment->positionId !== null) {
                        throw new CrewImportApplyException('An empty assignment contains catalog IDs.');
                    }
                    continue;
                }
                if ($assignment->resolutionStatus !== CrewImportAssignmentProposal::RESOLVED || $assignment->departmentId === null) {
                    throw new CrewImportApplyException('The crew import proposal contains an unresolved assignment.');
                }

                $department = $this->departmentRepository->find($assignment->departmentId);
                if (!$department instanceof CrewDepartment || $department->getScope() !== $scope) {
                    throw new CrewImportApplyException('The proposed crew department is outside the project catalog scope.');
                }

                $position = null;
                if ($assignment->positionId !== null) {
                    $position = $this->positionRepository->find($assignment->positionId);
                    if (
                        !$position instanceof CrewPosition
                        || $position->getCrewDepartment()?->getId() !== $department->getId()
                    ) {
                        throw new CrewImportApplyException('The proposed crew position does not belong to its department.');
                    }
                }

                $assignments[] = [$department, $position];
            }

            $prepared[] = [$person, $member, $assignments];
        }

        $applied = [];
        foreach ($prepared as [$person, $member, $assignments]) {
            /** @var CrewImportPersonProposal $person */
            /** @var CrewMember|null $member */
            $member ??= new CrewMember();
            if ($person->action === CrewImportPersonProposal::CREATE) {
                $project->addCrewMember($member);
            }

            $member
                ->setName($person->name)
                ->setLastName($person->lastName !== '' ? $person->lastName : null)
                ->setEmail($person->email !== '' ? $person->email : null)
                ->setPhone($person->phone !== '' ? $person->phone : null);

            foreach ($assignments as [$department, $position]) {
                if (!$member->hasAssignment($department, $position)) {
                    $member->addAssignment(
                        (new CrewMemberAssignment())
                            ->setCrewDepartment($department)
                            ->setCrewPosition($position)
                    );
                }
            }

            $this->entityManager->persist($member);
            $applied[] = $member;
        }

        return $applied;
    }
}
