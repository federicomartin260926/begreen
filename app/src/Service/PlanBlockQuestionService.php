<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class PlanBlockQuestionService
{
    public function __construct(
        private readonly PlanMeasureGetOrCreate $planMeasureGetOrCreate,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param iterable<int, Measure> $blockMeasures
     */
    public function applyAnswer(
        Plan $plan,
        MeasureBlock $block,
        bool $applies,
        ?User $answeredBy,
        iterable $blockMeasures
    ): void {
        $blockId = $block->getId();
        if ($blockId === null) {
            return;
        }

        $uniqueMeasures = [];
        foreach ($blockMeasures as $blockMeasure) {
            if ($blockMeasure instanceof Measure && $blockMeasure->getId() !== null) {
                $uniqueMeasures[$blockMeasure->getId()] = $blockMeasure;
            }
        }

        // One lock at most for the whole block. Do this before mutating the
        // block answer so a lock timeout cannot leave a partial unit of work.
        $relations = $this->planMeasureGetOrCreate->getOrCreateMany($plan, $uniqueMeasures);

        $blockAnswer = null;
        foreach ($plan->getBlockAnswers() as $existingBlockAnswer) {
            if (!$existingBlockAnswer instanceof SustainabilityPlanBlockAnswer) {
                continue;
            }

            if ($existingBlockAnswer->getMeasureBlock()?->getId() === $blockId) {
                $blockAnswer = $existingBlockAnswer;
                break;
            }
        }

        if (!$blockAnswer instanceof SustainabilityPlanBlockAnswer) {
            $blockAnswer = new SustainabilityPlanBlockAnswer();
            $blockAnswer
                ->setSustainabilityPlan($plan)
                ->setMeasureBlock($block);
            $plan->addBlockAnswer($blockAnswer);
            $this->entityManager->persist($blockAnswer);
        }

        $blockAnswer
            ->setApplies($applies)
            ->setAnsweredAt(new \DateTimeImmutable())
            ->setAnsweredBy($answeredBy);

        foreach ($uniqueMeasures as $measureId => $blockMeasure) {
            $item = $relations[$measureId];

            if ($applies) {
                if ($item->getApplicabilitySource() === 'block_skip' && $item->getBlockSkipAnswer()?->getId() === $blockAnswer->getId()) {
                    $item
                        ->setIsApplicable(null)
                        ->setWillImplement(null)
                        ->setImplemented(null)
                        ->setIsCritical(null)
                        ->setObservations(null)
                        ->setApplicabilitySource('manual')
                        ->setBlockSkipAnswer(null);
                }

                continue;
            }

            if ($item->getApplicabilitySource() === 'manual' && $item->isApplicable() === false) {
                continue;
            }

            $item
                ->setIsApplicable(false)
                ->setWillImplement(null)
                ->setImplemented(null)
                ->setIsCritical(null)
                ->setObservations(null)
                ->markAsBlockSkipped($blockAnswer);
        }
    }
}
