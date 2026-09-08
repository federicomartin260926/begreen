<?php

namespace App\Service\Emission\Transport;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TransportEmissionRecordService
{
    private const PERSISTABLE_STATUSES = [
        TransportEmissionResult::STATUS_CALCULATED,
        TransportEmissionResult::STATUS_DIRECT_OPERATOR_EMISSION,
        TransportEmissionResult::STATUS_DIRECT_ZERO,
    ];

    public function __construct(
        private TransportEmissionCalculator $calculator,
        private TransportEmissionSnapshot $snapshot,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @param array<string, string> $presentation */
    public function write(
        Project $project,
        Category $category,
        ProjectPhaseDate $phase,
        TransportEmissionInput $input,
        ?string $notes = null,
        ?EmissionRecord $record = null,
        array $presentation = [],
    ): TransportEmissionRecordWriteResult {
        $calculation = $this->calculator->calculate($input);
        if (!in_array($calculation->status, self::PERSISTABLE_STATUSES, true)) {
            return new TransportEmissionRecordWriteResult($calculation, null);
        }
        if (null === $calculation->normalizedActivityValue || null === $calculation->generatedKgCo2e) {
            throw new \LogicException('A persistable transport result must contain normalized amount and emissions.');
        }

        $record ??= new EmissionRecord();
        $record
            ->setProject($project)
            ->setCategory($category)
            ->setPhase($phase)
            ->setRegisteredAt($input->startDate)
            ->setNotes($notes)
            ->setAmount((float) $calculation->normalizedActivityValue)
            ->setEmission((float) $calculation->generatedKgCo2e)
            ->setCalculationDetails($this->snapshot->encode($input, $calculation, $presentation));

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return new TransportEmissionRecordWriteResult($calculation, $record);
    }
}
