<?php

namespace App\Service\Ai;

use App\Entity\Category;
use App\Entity\Measure;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use App\Exception\Ai\AiReportRequestException;
use App\Service\Ai\Dto\AiReportCategory;
use App\Service\Ai\Dto\AiReportMeasure;
use App\Service\Ai\Dto\AiReportRequest;
use App\Service\SustainabilityPlanMeasureOrderer;
use Doctrine\Persistence\ManagerRegistry;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;

final readonly class PlanAiReportRequestBuilder
{
    public function __construct(
        private AiReportConfiguration $configuration,
        private SustainabilityPlanMeasureOrderer $measureOrderer,
        private ManagerRegistry $doctrine,
    ) {
    }

    public function build(Plan $plan, string $locale): AiReportRequest
    {
        $locale = $this->normalizeLocale($locale);
        $translationRepository = $this->translationRepository();

        /** @var array<int, PlanMeasure> $planMeasuresByMeasure */
        $planMeasuresByMeasure = [];
        foreach ($plan->getPlanMeasures() as $planMeasure) {
            $measure = $planMeasure->getMeasure();
            // Configurable threshold pending final functional validation with Franc.
            if (!$measure || ($measure->getScore() ?? 0) < $this->configuration->minMeasureScore) {
                continue;
            }

            $decision = $this->decision($planMeasure);
            if (
                $decision === null
                || $measure->getId() === null
                || $measure->getCategory()?->getId() === null
            ) {
                continue;
            }

            $planMeasuresByMeasure[spl_object_id($measure)] = $planMeasure;
        }

        $measures = array_map(
            static fn (PlanMeasure $planMeasure): Measure => $planMeasure->getMeasure(),
            array_values($planMeasuresByMeasure),
        );
        $measures = $this->measureOrderer->sortVisibleMeasures($measures, Protocol::GROUP_BY_CATEGORY);

        /** @var array<int, array{name:string, measures:list<AiReportMeasure>}> $grouped */
        $grouped = [];
        foreach ($measures as $measure) {
            $planMeasure = $planMeasuresByMeasure[spl_object_id($measure)];
            $category = $measure->getCategory();
            if (!$category || $category->getId() === null) {
                continue;
            }

            $categoryName = $this->translatedValue($category, 'name', $locale, $translationRepository);
            $title = $this->translatedMeasureTitle($measure, $locale, $translationRepository);
            $decision = $this->decision($planMeasure);
            if ($categoryName === '' || $title === '' || $decision === null) {
                continue;
            }

            $categoryId = $category->getId();
            $grouped[$categoryId] ??= ['name' => $categoryName, 'measures' => []];
            $grouped[$categoryId]['measures'][] = new AiReportMeasure(
                sprintf('measure:%d', $measure->getId()),
                $title,
                $this->translatedValue($measure, 'description', $locale, $translationRepository),
                $decision,
                $planMeasure->isCritical(),
                trim((string) $planMeasure->getObservations()),
                (int) $measure->getScore(),
            );
        }

        $categories = [];
        foreach ($grouped as $categoryId => $category) {
            if ($category['measures'] === []) {
                continue;
            }

            $categories[] = new AiReportCategory(
                sprintf('category:%d', $categoryId),
                $category['name'],
                $category['measures'],
            );
        }

        if ($categories === []) {
            throw new AiReportRequestException('The plan has no eligible measures for the AI report.');
        }

        return new AiReportRequest($locale, $categories);
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        $normalized = explode('-', $normalized, 2)[0];

        if (!in_array($normalized, ['es', 'en'], true)) {
            throw new AiReportRequestException('The requested AI report locale is not supported.');
        }

        return $normalized;
    }

    private function decision(PlanMeasure $planMeasure): ?AiReportMeasureDecision
    {
        if ($planMeasure->isApplicable() === false) {
            return AiReportMeasureDecision::NOT_APPLICABLE;
        }

        if ($planMeasure->isApplicable() !== true || $planMeasure->willImplement() === null) {
            return null;
        }

        return $planMeasure->willImplement()
            ? AiReportMeasureDecision::PLANNED
            : AiReportMeasureDecision::NOT_PLANNED;
    }

    private function translatedMeasureTitle(
        Measure $measure,
        string $locale,
        TranslationRepository $translationRepository,
    ): string {
        $reviewTitle = $this->translatedValue($measure, 'nameReview', $locale, $translationRepository);

        return $reviewTitle !== ''
            ? $reviewTitle
            : $this->translatedValue($measure, 'name', $locale, $translationRepository);
    }

    private function translatedValue(
        Measure|Category $entity,
        string $field,
        string $locale,
        TranslationRepository $translationRepository,
    ): string {
        if ($locale === 'es') {
            $value = match ($field) {
                'name' => $entity->getName(),
                'nameReview' => $entity instanceof Measure ? $entity->getNameReview() : null,
                'description' => $entity instanceof Measure ? $entity->getDescription() : null,
                default => null,
            };

            return trim((string) $value);
        }

        $translations = $translationRepository->findTranslations($entity);

        return trim((string) ($translations[$locale][$field] ?? ''));
    }

    private function translationRepository(): TranslationRepository
    {
        $repository = $this->doctrine->getRepository(Translation::class);
        if (!$repository instanceof TranslationRepository) {
            throw new AiReportRequestException('The AI report translations could not be loaded safely.');
        }

        return $repository;
    }
}
