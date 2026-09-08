<?php

declare(strict_types=1);

namespace App\Service\Emission\Material;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MaterialEmissionRecordService
{
    public function __construct(
        private MaterialEmissionCalculator $calculator,
        private MaterialEmissionSnapshot $snapshot,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, scalar|null> $presentation */
    public function write(
        Project $project,
        Category $category,
        ProjectPhaseDate $phase,
        MaterialEmissionInput $input,
        ?string $notes = null,
        ?EmissionRecord $record = null,
        array $presentation = [],
    ): MaterialEmissionRecordWriteResult {
        if (null === $input->startDate) {
            throw new \InvalidArgumentException('startDate is required to persist a material record.');
        }
        $calculation = $this->calculator->calculate($input);
        if (EmissionRecord::STATUS_PENDING_DATA === $calculation->status) {
            throw new \InvalidArgumentException('Incomplete or invalid material input cannot be persisted.');
        }

        $record ??= new EmissionRecord();
        $record
            ->setProject($project)
            ->setCategory($category)
            ->setActivity(null)
            ->setPhase($phase)
            ->setRegisteredAt(\DateTimeImmutable::createFromInterface($input->startDate))
            ->setNotes($notes)
            ->setAmount(null === $calculation->normalizedAmount ? null : (float) $calculation->normalizedAmount)
            ->setEmission(null === $calculation->emissionKgCo2e ? null : (float) $calculation->emissionKgCo2e)
            ->setStatus($calculation->status)
            ->setCalculationDetails($this->snapshot->encode($input, $calculation, $presentation));

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return new MaterialEmissionRecordWriteResult($calculation, $record);
    }
}
