<?php

declare(strict_types=1);

namespace App\Service\Emission\Catering;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CateringEmissionRecordService
{
    public function __construct(
        private CateringEmissionCalculator $calculator,
        private CateringEmissionSnapshot $snapshot,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, scalar|null> $presentation */
    public function write(
        Project $project,
        Category $category,
        ProjectPhaseDate $phase,
        CateringEmissionInput $input,
        ?string $notes = null,
        ?EmissionRecord $record = null,
        array $presentation = [],
    ): CateringEmissionRecordWriteResult {
        if (null === $input->startDate) {
            throw new \InvalidArgumentException('startDate is required to persist a catering record.');
        }

        $calculation = $this->calculator->calculate($input);
        if (EmissionRecord::STATUS_PENDING_DATA === $calculation->status) {
            throw new \InvalidArgumentException('Incomplete catering input cannot be persisted.');
        }

        $record ??= new EmissionRecord();
        $record
            ->setProject($project)
            ->setCategory($category)
            ->setPhase($phase)
            ->setRegisteredAt(\DateTimeImmutable::createFromInterface($input->startDate))
            ->setNotes($notes)
            ->setAmount(null === $calculation->normalizedAmount ? null : (float) $calculation->normalizedAmount)
            ->setEmission(null === $calculation->emissionKgCo2e ? null : (float) $calculation->emissionKgCo2e)
            ->setStatus($calculation->status)
            ->setCalculationDetails($this->snapshot->encode($input, $calculation, $presentation));

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return new CateringEmissionRecordWriteResult($calculation, $record);
    }
}
