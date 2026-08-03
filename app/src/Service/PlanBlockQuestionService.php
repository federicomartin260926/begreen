<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\SustainabilityPlanBlockAnswer;
use App\Entity\User;
use App\Repository\PlanMeasureRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PlanBlockQuestionService
{
    public function __construct(
        private readonly PlanMeasureRepository $planMeasureRepository,
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

        foreach ($blockMeasures as $blockMeasure) {
            if (!$blockMeasure instanceof Measure) {
                continue;
            }

            $item = $this->planMeasureRepository->findOneBy(['plan' => $plan, 'measure' => $blockMeasure]);
            if (!$item instanceof PlanMeasure) {
                $item = new PlanMeasure();
                $plan->addPlanMeasure($item);
                $item->setMeasure($blockMeasure);
                $this->entityManager->persist($item);
            }

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
