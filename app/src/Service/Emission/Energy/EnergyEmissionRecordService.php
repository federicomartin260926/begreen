<?php

namespace App\Service\Emission\Energy;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EnergyEmissionRecordService
{
    public function __construct(
        private EnergyEmissionCalculator $calculator,
        private EnergyEmissionSnapshot $snapshot,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, scalar|null> $presentation */
    public function write(
        Project $project,
        Category $category,
        ProjectPhaseDate $phase,
        EnergyEmissionInput $input,
        ?string $notes = null,
        ?EmissionRecord $record = null,
        array $presentation = [],
    ): EnergyEmissionRecordWriteResult {
        $calculation = $this->calculator->calculate($input);
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

        return new EnergyEmissionRecordWriteResult($calculation, $record);
    }
}
