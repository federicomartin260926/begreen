<?php

declare(strict_types=1);

namespace App\Service\Emission\Water;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WaterEmissionRecordService
{
    public function __construct(
        private WaterEmissionCalculator $calculator,
        private WaterEmissionSnapshot $snapshot,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, scalar|null> $presentation */
    public function write(
        Project $project,
        Category $category,
        ProjectPhaseDate $phase,
        WaterEmissionInput $input,
        ?string $notes = null,
        ?EmissionRecord $record = null,
        array $presentation = [],
    ): WaterEmissionRecordWriteResult {
        if (null === $input->startDate) {
            throw new \InvalidArgumentException('startDate is required to persist a water record.');
        }

        $calculation = $this->calculator->calculate($input);
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

        return new WaterEmissionRecordWriteResult($calculation, $record);
    }
}
