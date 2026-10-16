<?php

declare(strict_types=1);

namespace App\Service\FinalReport;

use App\Entity\FinalReportContent;
use App\Entity\Plan;
use App\Entity\PlanMeasure;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FinalReportContentEditor
{
    public const MANUAL_SECTIONS = [
        'introduction',
        'executive_summary',
        'implementation',
        'featured_storytelling',
        'deviations_learnings',
        'emissions',
        'ods',
        'conclusion',
    ];

    private const IMAGE_MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /** @param array<string, mixed> $input */
    public function update(FinalReportContent $content, Project $project, Plan $plan, array $input): void
    {
        if ($plan->getProject() !== $project) {
            throw new \InvalidArgumentException('The sustainability plan does not belong to the active project.');
        }

        $planMeasures = $this->indexPlanMeasures($plan);
        $documents = $this->indexImageDocuments($project);
        $content
            ->setProject($project)
            ->setFeaturedMeasures($this->normalizeFeaturedMeasures($input['featured'] ?? [], $planMeasures))
            ->setPhotos($this->normalizePhotos(
                $input['photos'] ?? [],
                $documents,
                $planMeasures,
                $content->getPhotos(),
            ))
            ->setManualTexts($this->normalizeManualTexts(
                $input['manualTexts'] ?? [],
                $content->getManualTexts(),
            ));

        $rawCoverDocumentId = $input['coverDocumentId'] ?? null;
        $coverDocumentId = $this->positiveId($rawCoverDocumentId);
        if ($rawCoverDocumentId !== null && $rawCoverDocumentId !== '' && $coverDocumentId === null) {
            throw new \InvalidArgumentException('Invalid cover image reference.');
        }
        $selectedDocumentIds = array_column(array_filter(
            $content->getPhotos(),
            fn (array $photo): bool => !$this->isExcludedPhotoEntry($photo),
        ), 'documentId');
        if ($coverDocumentId !== null && !in_array($coverDocumentId, $selectedDocumentIds, true)) {
            throw new \InvalidArgumentException('The cover image must belong to the report gallery.');
        }
        $content->setCoverDocumentId($coverDocumentId);
    }

    public function appendPhoto(FinalReportContent $content, ProjectDocument $document): void
    {
        if ($content->getProject() !== $document->getProject() || !$this->isSupportedImageDocument($document)) {
            throw new \InvalidArgumentException('Invalid final report image document.');
        }

        $documentId = $document->getId();
        if ($documentId === null) {
            throw new \LogicException('The image document must be persisted before being referenced.');
        }

        $photos = $content->getPhotos();
        foreach ($photos as $photo) {
            if (($photo['documentId'] ?? null) === $documentId) {
                return;
            }
        }
        $photos[] = [
            'documentId' => $documentId,
            'planMeasureId' => null,
            'caption' => null,
            'author' => null,
            'position' => count(array_filter(
                $photos,
                fn (array $photo): bool => !$this->isExcludedPhotoEntry($photo),
            )) + 1,
        ];
        $content->setPhotos($photos);
    }

    public function assertPhotoCanBeReplaced(
        FinalReportContent $content,
        Project $project,
        ProjectDocument $original,
    ): void {
        if ($content->getProject() !== $project
            || $original->getProject() !== $project
            || !$this->isSupportedImageDocument($original)) {
            throw new \InvalidArgumentException('The photograph does not belong to the active project.');
        }

        foreach ($content->getPhotos() as $photo) {
            if (($photo['documentId'] ?? null) === $original->getId()
                && !$this->isExcludedPhotoEntry($photo)) {
                return;
            }
        }

        throw new \InvalidArgumentException('The photograph is not included in the final report.');
    }

    public function replacePhoto(
        FinalReportContent $content,
        Project $project,
        ProjectDocument $original,
        ProjectDocument $replacement,
    ): void {
        $this->assertPhotoCanBeReplaced($content, $project, $original);
        if ($replacement->getProject() !== $project
            || !$this->isSupportedImageDocument($replacement)
            || $replacement->getId() === null) {
            throw new \InvalidArgumentException('Invalid replacement photograph.');
        }

        $photos = $content->getPhotos();
        foreach ($photos as &$photo) {
            if (($photo['documentId'] ?? null) === $original->getId()
                && !$this->isExcludedPhotoEntry($photo)) {
                $replacedDocumentIds = $this->normalizeDocumentIds($photo['replacedDocumentIds'] ?? []);
                $replacedDocumentIds[] = $original->getId();
                $photo['documentId'] = $replacement->getId();
                $photo['replacedDocumentIds'] = array_values(array_unique($replacedDocumentIds));
            }
        }
        unset($photo);
        $content->setPhotos($photos);

        if ($content->getCoverDocumentId() === $original->getId()) {
            $content->setCoverDocumentId($replacement->getId());
        }
    }

    public function excludePhoto(
        FinalReportContent $content,
        Project $project,
        ProjectDocument $document,
    ): void {
        if ($content->getProject() !== $project
            || $document->getProject() !== $project
            || !$this->isSupportedImageDocument($document)
            || $document->getId() === null) {
            throw new \InvalidArgumentException('The photograph does not belong to the active project.');
        }

        $photos = [];
        $replacedDocumentIds = [];
        foreach ($content->getPhotos() as $photo) {
            if (($photo['documentId'] ?? null) === $document->getId()) {
                $replacedDocumentIds = [
                    ...$replacedDocumentIds,
                    ...$this->normalizeDocumentIds($photo['replacedDocumentIds'] ?? []),
                ];
                continue;
            }
            $photos[] = $photo;
        }
        $exclusion = [
            'documentId' => $document->getId(),
            'excluded' => true,
        ];
        if ($replacedDocumentIds !== []) {
            $exclusion['replacedDocumentIds'] = array_values(array_unique($replacedDocumentIds));
        }
        $photos[] = $exclusion;
        $content->setPhotos($photos);

        if ($content->getCoverDocumentId() === $document->getId()) {
            $content->setCoverDocumentId(null);
        }
    }

    /** @return list<int> */
    public function excludedPhotoDocumentIds(FinalReportContent $content): array
    {
        $ids = [];
        foreach ($content->getPhotos() as $photo) {
            $id = $this->positiveId($photo['documentId'] ?? null);
            if ($id !== null && $this->isExcludedPhotoEntry($photo)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<int> */
    public function supersededPhotoDocumentIds(FinalReportContent $content): array
    {
        $ids = [];
        foreach ($content->getPhotos() as $photo) {
            $ids = [...$ids, ...$this->normalizeDocumentIds($photo['replacedDocumentIds'] ?? [])];
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $photo */
    public function isExcludedPhotoEntry(array $photo): bool
    {
        return ($photo['excluded'] ?? false) === true;
    }

    public function validateImageUpload(UploadedFile $file): void
    {
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || ($file->getSize() ?: 0) > ProjectDocumentStorage::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('backend.final_report.flash.photo_too_large');
        }
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('backend.final_report.flash.photo_required');
        }
        if (!$file->isValid()
            || $file->getPathname() === ''
            || !is_readable($file->getPathname())) {
            throw new \InvalidArgumentException('backend.final_report.flash.photo_upload_failed');
        }

        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $mime = $file->getMimeType();
        if (!isset(self::IMAGE_MIME_BY_EXTENSION[$extension])
            || $mime !== self::IMAGE_MIME_BY_EXTENSION[$extension]) {
            throw new \InvalidArgumentException('backend.final_report.flash.photo_invalid_format');
        }
    }

    public function isSupportedImageDocument(ProjectDocument $document): bool
    {
        if (!$document->isFile()
            || $document->getStoredName() === null) {
            return false;
        }

        $extension = strtolower((string) pathinfo($document->getStoredName(), PATHINFO_EXTENSION));

        return isset(self::IMAGE_MIME_BY_EXTENSION[$extension])
            && $document->getMimeType() === self::IMAGE_MIME_BY_EXTENSION[$extension];
    }

    /** @return array<int, PlanMeasure> */
    private function indexPlanMeasures(Plan $plan): array
    {
        $indexed = [];
        foreach ($plan->getPlanMeasures() as $planMeasure) {
            if ($planMeasure instanceof PlanMeasure && $planMeasure->getId() !== null) {
                $indexed[$planMeasure->getId()] = $planMeasure;
            }
        }

        return $indexed;
    }

    /** @return array<int, ProjectDocument> */
    private function indexImageDocuments(Project $project): array
    {
        $indexed = [];
        foreach ($project->getProjectDocuments() as $document) {
            if ($document instanceof ProjectDocument
                && $document->getId() !== null
                && $this->isSupportedImageDocument($document)) {
                $indexed[$document->getId()] = $document;
            }
        }

        return $indexed;
    }

    /**
     * @param mixed $submitted
     * @param array<int, PlanMeasure> $planMeasures
     * @return array<int, array{planMeasureId:int, text:string|null}>
     */
    private function normalizeFeaturedMeasures(mixed $submitted, array $planMeasures): array
    {
        if (!is_array($submitted)) {
            throw new \InvalidArgumentException('Invalid featured measures payload.');
        }

        $result = [];
        foreach ($submitted as $rawId => $values) {
            if (!is_array($values) || !isset($values['selected'])) {
                continue;
            }
            $id = $this->positiveId($rawId);
            if ($id === null || !isset($planMeasures[$id])) {
                throw new \InvalidArgumentException('A featured measure does not belong to the active plan.');
            }
            $result[] = [
                'planMeasureId' => $id,
                'text' => $this->text($values['text'] ?? null, 2000),
            ];
        }

        return $result;
    }

    /**
     * @param mixed $submitted
     * @param array<int, ProjectDocument> $documents
     * @param array<int, PlanMeasure> $planMeasures
     * @param array<int, array<string, mixed>> $current
     * @return array<int, array<string, mixed>>
     */
    private function normalizePhotos(mixed $submitted, array $documents, array $planMeasures, array $current): array
    {
        if (!is_array($submitted)) {
            throw new \InvalidArgumentException('Invalid gallery payload.');
        }

        $replacedByDocumentId = [];
        $excludedByDocumentId = [];
        foreach ($current as $photo) {
            $currentDocumentId = $this->positiveId($photo['documentId'] ?? null);
            if ($currentDocumentId === null || !isset($documents[$currentDocumentId])) {
                continue;
            }
            $replacedDocumentIds = $this->normalizeDocumentIds($photo['replacedDocumentIds'] ?? []);
            if ($this->isExcludedPhotoEntry($photo)) {
                $excludedByDocumentId[$currentDocumentId] = [
                    'documentId' => $currentDocumentId,
                    'excluded' => true,
                ];
                if ($replacedDocumentIds !== []) {
                    $excludedByDocumentId[$currentDocumentId]['replacedDocumentIds'] = $replacedDocumentIds;
                }
            } else {
                $replacedByDocumentId[$currentDocumentId] = $replacedDocumentIds;
            }
        }

        $result = [];
        foreach ($submitted as $rawDocumentId => $values) {
            if (!is_array($values) || !isset($values['selected'])) {
                continue;
            }
            $documentId = $this->positiveId($rawDocumentId);
            if ($documentId === null || !isset($documents[$documentId])) {
                throw new \InvalidArgumentException('A gallery image does not belong to the active project.');
            }
            if (isset($excludedByDocumentId[$documentId])) {
                continue;
            }
            $rawPlanMeasureId = $values['planMeasureId'] ?? null;
            $planMeasureId = $this->positiveId($rawPlanMeasureId);
            if ($rawPlanMeasureId !== null && $rawPlanMeasureId !== '' && $planMeasureId === null) {
                throw new \InvalidArgumentException('Invalid gallery measure reference.');
            }
            if ($planMeasureId !== null && !isset($planMeasures[$planMeasureId])) {
                throw new \InvalidArgumentException('A gallery image references a measure from another plan.');
            }
            $position = filter_var($values['position'] ?? 0, FILTER_VALIDATE_INT);
            if ($position === false || $position < 0 || $position > 10000) {
                throw new \InvalidArgumentException('Invalid gallery image position.');
            }
            $normalized = [
                'documentId' => $documentId,
                'planMeasureId' => $planMeasureId,
                'caption' => $this->text($values['caption'] ?? null, 500),
                'author' => $this->text($values['author'] ?? null, 160),
                'position' => $position,
            ];
            if (($replacedByDocumentId[$documentId] ?? []) !== []) {
                $normalized['replacedDocumentIds'] = $replacedByDocumentId[$documentId];
            }
            $result[] = $normalized;
        }
        usort($result, static fn (array $left, array $right): int =>
            $left['position'] <=> $right['position'] ?: $left['documentId'] <=> $right['documentId']);

        ksort($excludedByDocumentId);
        $result = [...$result, ...array_values($excludedByDocumentId)];

        return $result;
    }

    /** @return list<int> */
    private function normalizeDocumentIds(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $ids = [];
        foreach ($values as $value) {
            $id = $this->positiveId($value);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param mixed $submitted
     * @param array<string, mixed> $current
     * @return array<string, array{text:string|null, origin:string}>
     */
    private function normalizeManualTexts(mixed $submitted, array $current): array
    {
        if (!is_array($submitted)) {
            throw new \InvalidArgumentException('Invalid manual texts payload.');
        }

        $result = [];
        foreach (self::MANUAL_SECTIONS as $section) {
            $text = $this->text($submitted[$section] ?? null, 10000);
            $currentText = $this->text($current[$section]['text'] ?? null, 10000);
            $currentOrigin = $current[$section]['origin'] ?? 'manual';
            $result[$section] = [
                'text' => $text,
                'origin' => $text === $currentText && in_array($currentOrigin, ['manual', 'ai'], true)
                    ? $currentOrigin
                    : 'manual',
            ];
        }

        return $result;
    }

    private function positiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);

        return $id !== false && $id > 0 ? $id : null;
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new \InvalidArgumentException('Invalid editorial text.');
        }
        $text = trim((string) $value);
        if (mb_strlen($text) > $maxLength) {
            throw new \InvalidArgumentException('Editorial text is too long.');
        }

        return $text === '' ? null : $text;
    }
}
