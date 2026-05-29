<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryGhg;
use App\Entity\Department;
use App\Entity\EsG;
use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\Scope;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class MeasureTemplateV23Importer
{
    private const TEMPLATE_VERSION = 'v23';

    /**
     * @var array<string, array<string, MeasureBlock>>
     */
    private array $measureBlockCache = [];
    private bool $validateCanonicalMeasures = true;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatableListener $translatableListener,
        private readonly MeasureCatalogAdminService $catalogAdminService,
    ) {
    }

    public function import(MeasureTemplateV23Report $report, bool $apply, bool $validateCanonical = true): MeasureTemplateV23Report
    {
        $this->measureBlockCache = [];
        $this->validateCanonicalMeasures = $validateCanonical;

        if (!$apply) {
            $report->setImportSummary([
                'mode' => 'dry-run',
                'status' => 'not-applied',
            ]);

            return $report;
        }

        if ($report->hasErrors()) {
            $report->setImportSummary([
                'mode' => 'apply',
                'status' => 'aborted',
                'reason' => 'validation-errors',
            ]);

            return $report;
        }

        $summary = [
            'mode' => 'apply',
            'status' => 'applied',
            'imported' => 0,
            'updated' => 0,
            'errors' => 0,
            'duplicates' => 0,
        ];

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $this->translatableListener->setTranslatableLocale('es');
            $this->translatableListener->setTranslationFallback(false);

            foreach ($report->getRows() as $rowData) {
                $this->importRow($rowData, $report, $summary);
            }

            if ($report->hasErrors()) {
                $connection->rollBack();
                $summary['status'] = 'aborted';
                $summary['reason'] = 'validation-errors';
                $summary['errors'] = count($report->getErrors());
                $report->setImportSummary($summary);

                return $report;
            }

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            $this->translatableListener->setTranslationFallback(true);
            $this->validateCanonicalMeasures = true;
        }

        $report->setImportSummary($summary);

        return $report;
    }

    /**
     * @param array<string, mixed> $rowData
     */
    private function importRow(array $rowData, MeasureTemplateV23Report $report, array &$summary): void
    {
        $rowNumber = (int) ($rowData['row'] ?? 0);
        $errorsBeforeRow = count($report->getErrors());

        $protocol = $this->resolveProtocol((string) ($rowData['protocol'] ?? ''), $report, $rowNumber);
        if (!$protocol) {
            $summary['errors']++;
            return;
        }

        $protocolType = trim((string) ($rowData['projectType'] ?? ''));
        if ($protocolType !== '' && !in_array($protocolType, [Protocol::TYPE_RODAJE, Protocol::TYPE_EVENTO, Protocol::TYPE_AMBOS], true)) {
            $report->addError('invalid_project_type', sprintf('Fila %d con tipo de proyecto inválido: "%s".', $rowNumber, $protocolType), ['row' => $rowNumber, 'value' => $protocolType]);
            $summary['errors']++;
            return;
        }
        if ($protocolType !== '' && $protocol->getType() !== null && $protocol->getType() !== $protocolType && $protocol->getType() !== Protocol::TYPE_AMBOS) {
            $report->addError('protocol_type_mismatch', sprintf('Fila %d: el protocolo "%s" no corresponde al tipo "%s".', $rowNumber, $protocol->getName(), $protocolType), ['row' => $rowNumber]);
            $summary['errors']++;
            return;
        }

        $categoryValue = trim((string) ($rowData['category'] ?? ''));
        $category = $this->resolveCategory($categoryValue);
        if ($categoryValue !== '' && !$category instanceof Category) {
            $report->addError('invalid_category', sprintf('Fila %d con categoría desconocida: "%s".', $rowNumber, $categoryValue), ['row' => $rowNumber, 'value' => $categoryValue]);
            $summary['errors']++;
            return;
        }

        $categoryGhgValue = trim((string) ($rowData['categoryGhg'] ?? ''));
        $categoryGhg = $this->resolveCategoryGhg($categoryGhgValue);
        if ($categoryGhgValue !== '' && !$categoryGhg instanceof CategoryGhg) {
            $report->addError('invalid_category_ghg', sprintf('Fila %d con categoría GHG desconocida: "%s".', $rowNumber, $categoryGhgValue), ['row' => $rowNumber, 'value' => $categoryGhgValue]);
            $summary['errors']++;
            return;
        }

        $esgValue = trim((string) ($rowData['esg'] ?? ''));
        $esg = $this->resolveEsg($esgValue);
        if ($esgValue !== '' && !$esg instanceof EsG) {
            $report->addError('invalid_esg', sprintf('Fila %d con ESG desconocido: "%s".', $rowNumber, $esgValue), ['row' => $rowNumber, 'value' => $esgValue]);
            $summary['errors']++;
            return;
        }

        $scopeValue = trim((string) ($rowData['scope'] ?? ''));
        $scope = $this->resolveScope($scopeValue);
        if ($scopeValue !== '' && !$scope instanceof Scope) {
            $report->addError('invalid_scope', sprintf('Fila %d con alcance desconocido: "%s".', $rowNumber, $scopeValue), ['row' => $rowNumber, 'value' => $scopeValue]);
            $summary['errors']++;
            return;
        }

        $measureBlock = $this->resolveMeasureBlock((string) ($rowData['measureBlock'] ?? ''), $protocol, $rowNumber);

        $departments = $this->resolveDepartments((string) ($rowData['departments'] ?? ''), $rowNumber, $report);
        $odsItems = $this->resolveOdsItems((string) ($rowData['odsItems'] ?? ''), $rowNumber, $report);
        $impactAreas = $this->resolveImpactAreas((string) ($rowData['impactAreas'] ?? ''), $rowNumber, $report);
        $tripleBalanceAxes = $this->resolveTripleBalanceAxes((string) ($rowData['tripleBalanceAxes'] ?? ''), $rowNumber, $report);
        $verificationSources = $this->resolveVerificationSources((array) ($rowData['verificationSources'] ?? []), $rowNumber, $report);

        if (count($report->getErrors()) > $errorsBeforeRow) {
            $summary['errors']++;
            return;
        }

        $name = trim((string) ($rowData['name'] ?? ''));
        $nameReview = trim((string) ($rowData['nameReview'] ?? '')) ?: null;
        $description = trim((string) ($rowData['description'] ?? '')) ?: null;
        $implementation = trim((string) ($rowData['implementation'] ?? '')) ?: null;
        $score = (int) ($rowData['score'] ?? 0);
        $mandatory = $this->parseMandatory((string) ($rowData['mandatory'] ?? ''));
        $nameEn = trim((string) ($rowData['nameEn'] ?? '')) ?: null;
        $nameReviewEn = trim((string) ($rowData['nameReviewEn'] ?? '')) ?: null;
        $descriptionEn = trim((string) ($rowData['descriptionEn'] ?? '')) ?: null;
        $implementationEn = trim((string) ($rowData['implementationEn'] ?? '')) ?: null;
        $verificationSourcesEn = trim((string) ($rowData['verificationSourcesEn'] ?? '')) ?: null;

        $measure = $this->em->getRepository(Measure::class)->findOneBy([
            'protocol' => $protocol,
            'sourceRow' => $rowNumber,
            'importVersion' => self::TEMPLATE_VERSION,
        ]);

        $created = false;
        if (!$measure instanceof Measure) {
            $measure = new Measure();
            $measure->setProtocol($protocol);
            $this->em->persist($measure);
            $created = true;
        }

        $measure
            ->setProtocol($protocol)
            ->setName($name)
            ->setNameReview($nameReview)
            ->setDescription($description)
            ->setImplementation($implementation)
            ->setCategory($category)
            ->setCategoryGhg($categoryGhg)
            ->setMeasureBlock($measureBlock)
            ->setEsg($esg)
            ->setScope($scope)
            ->setScore($score)
            ->setMandatory($mandatory)
            ->setSourceRow($rowNumber)
            ->setImportVersion(self::TEMPLATE_VERSION)
            ->setImportHash($this->buildImportHash([
                'protocol' => $protocol->getCode() ?? $protocol->getName(),
                'projectType' => $protocolType,
                'measureBlock' => $measureBlock?->getCode(),
                'category' => $category?->getName(),
                'categoryGhg' => $categoryGhg?->getName(),
                'name' => $name,
                'nameReview' => $nameReview,
                'description' => $description,
                'implementation' => $implementation,
                'score' => $score,
                'mandatory' => $mandatory,
                'departments' => array_map(static fn (Department $department): string => (string) ($department->getCode() ?? $department->getName()), $departments),
                'ods' => array_map(static fn (Ods $ods): string => (string) ($ods->getCode() ?? $ods->getName()), $odsItems),
                'esg' => $esg?->getName(),
                'scope' => $scope?->getName(),
                'impactAreas' => array_map(static fn (ImpactArea $impactArea): string => (string) $impactArea->getCode(), $impactAreas),
                'tripleBalanceAxes' => array_map(static fn (TripleBalanceAxis $axis): string => (string) $axis->getCode(), $tripleBalanceAxes),
                'verificationSources' => array_map(static fn (array $item): string => sprintf('%d:%s', $item['priority'], $item['source']->getCode()), $verificationSources),
                'nameEn' => $nameEn,
                'nameReviewEn' => $nameReviewEn,
                'descriptionEn' => $descriptionEn,
                'implementationEn' => $implementationEn,
                'verificationSourcesEn' => $verificationSourcesEn,
            ]));

        $this->syncDepartments($measure, $departments);
        $this->syncOdsItems($measure, $odsItems);
        $this->syncImpactAreas($measure, $impactAreas);
        $this->syncTripleBalanceAxes($measure, $tripleBalanceAxes);

        try {
            $this->catalogAdminService->syncVerificationSources($measure, $this->mapVerificationSources($verificationSources));
        } catch (\InvalidArgumentException $exception) {
            $report->addError('invalid_verification_sources', sprintf('Fila %d: %s', $rowNumber, $exception->getMessage()), ['row' => $rowNumber]);
            $summary['errors']++;
            return;
        }

        $measure->setDepartment($departments[0] ?? null);
        $measure->setOds($odsItems[0] ?? null);
        $measure->setVerificationSources($measure->getVerificationSourcesSummary());

        if (
            $this->validateCanonicalMeasures
            && $protocol->getCode() === PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE
            && $measure->getImportVersion() === PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION
        ) {
            $validationErrors = $this->catalogAdminService->validateV23Measure($measure, $this->mapVerificationSources($verificationSources));
            if ($validationErrors !== []) {
                foreach ($validationErrors as $error) {
                    $report->addError((string) ($error['field'] ?? 'v23_validation'), sprintf('Fila %d: %s', $rowNumber, (string) ($error['message'] ?? 'Error de validación.')), ['row' => $rowNumber]);
                }
                $summary['errors']++;
                return;
            }
        }

        $this->syncTranslations($measure, $nameEn, $nameReviewEn, $descriptionEn, $implementationEn, $verificationSourcesEn);

        $summary[$created ? 'imported' : 'updated']++;
    }

    private function resolveProtocol(string $value, MeasureTemplateV23Report $report, int $rowNumber): ?Protocol
    {
        foreach (MeasureTemplateV23Schema::lookupCandidates($value) as $candidate) {
            $protocol = $this->em->getRepository(Protocol::class)->findOneBy(['code' => $candidate])
                ?? $this->em->getRepository(Protocol::class)->findOneBy(['name' => $candidate]);

            if ($protocol instanceof Protocol) {
                return $protocol;
            }
        }

        $report->addError('invalid_protocol', sprintf('Fila %d con protocolo desconocido: "%s".', $rowNumber, $value), ['row' => $rowNumber, 'value' => $value]);
        return null;
    }

    private function resolveCategory(string $value): ?Category
    {
        if (trim($value) === '') {
            return null;
        }

        foreach (MeasureTemplateV23Schema::lookupCandidates($value) as $candidate) {
            $category = $this->em->getRepository(Category::class)->findOneBy(['name' => $candidate]);
            if ($category instanceof Category) {
                return $category;
            }
        }

        return null;
    }

    private function resolveCategoryGhg(string $value): ?CategoryGhg
    {
        if (trim($value) === '') {
            return null;
        }

        foreach (MeasureTemplateV23Schema::lookupCandidates($value) as $candidate) {
            $category = $this->em->getRepository(CategoryGhg::class)->findOneBy(['name' => $candidate]);
            if ($category instanceof CategoryGhg) {
                return $category;
            }
        }

        return null;
    }

    private function resolveEsg(string $value): ?EsG
    {
        if (trim($value) === '') {
            return null;
        }

        foreach (MeasureTemplateV23Schema::lookupCandidates($value) as $candidate) {
            $item = $this->em->getRepository(EsG::class)->findOneBy(['name' => $candidate]);
            if ($item instanceof EsG) {
                return $item;
            }
        }

        return null;
    }

    private function resolveScope(string $value): ?Scope
    {
        if (trim($value) === '') {
            return null;
        }

        foreach (MeasureTemplateV23Schema::lookupCandidates($value) as $candidate) {
            $item = $this->em->getRepository(Scope::class)->findOneBy(['name' => $candidate]);
            if ($item instanceof Scope) {
                return $item;
            }
        }

        return null;
    }

    private function resolveMeasureBlock(string $value, Protocol $protocol, int $rowNumber): ?MeasureBlock
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $repository = $this->em->getRepository(MeasureBlock::class);
        [$explicitCode, $explicitName] = $this->splitMeasureBlockValue($value);

        $candidates = array_values(array_unique(array_filter([
            $explicitCode,
            $explicitName,
            ...MeasureTemplateV23Schema::lookupCandidates($value),
        ])));

        foreach ($candidates as $candidate) {
            if ($cached = $this->getCachedMeasureBlock($protocol, $candidate)) {
                return $cached;
            }

            $block = $repository->findOneBy([
                'protocol' => $protocol,
                'name' => $candidate,
            ]);
            if ($block instanceof MeasureBlock) {
                $this->cacheMeasureBlock($protocol, $block, $value, $candidate, (string) $block->getCode(), (string) $block->getName());
                return $block;
            }

            $block = $repository->findOneBy([
                'protocol' => $protocol,
                'code' => $candidate,
            ]);
            if ($block instanceof MeasureBlock) {
                $this->cacheMeasureBlock($protocol, $block, $value, $candidate, (string) $block->getCode(), (string) $block->getName());
                return $block;
            }

            if (method_exists($repository, 'findEquivalentByProtocol')) {
                $block = $repository->findEquivalentByProtocol($protocol, $candidate);
                if ($block instanceof MeasureBlock) {
                    $this->cacheMeasureBlock($protocol, $block, $value, $candidate, (string) $block->getCode(), (string) $block->getName());
                    return $block;
                }
            }
        }

        $blockName = $explicitName !== '' ? $explicitName : ($explicitCode !== '' ? $explicitCode : $value);
        $code = $this->buildMeasureBlockCode($explicitCode !== '' ? $explicitCode : $blockName);

        $block = (new MeasureBlock())
            ->setProtocol($protocol)
            ->setCode($code)
            ->setName($blockName)
            ->setSortOrder($rowNumber)
            ->setHasScreeningQuestion(false)
            ->setScreeningQuestion(null)
            ->setActive(true);

        $this->em->persist($block);
        $this->cacheMeasureBlock($protocol, $block, $value, $code, $blockName, $explicitCode, $explicitName);

        return $block;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitMeasureBlockValue(string $value): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return ['', ''];
        }

        if (!str_contains($value, ' - ')) {
            return ['', $value];
        }

        [$left, $right] = array_pad(explode(' - ', $value, 2), 2, '');

        return [trim($left), trim($right)];
    }

    private function buildMeasureBlockCode(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'block';
        }

        if (preg_match('/\s/u', $value) || preg_match('/[^\pL\pN._-]/u', $value)) {
            $slugger = new AsciiSlugger();
            $value = (string) $slugger->slug($value)->lower()->toString();
        }

        return substr($value !== '' ? $value : 'block', 0, 120);
    }

    /**
     * @return Department[]
     */
    private function resolveDepartments(string $value, int $rowNumber, MeasureTemplateV23Report $report): array
    {
        return $this->resolveMultipleEntities($value, Department::class, 'departamento', $rowNumber, $report, static fn (Department $department): string => $department->getDisplayName());
    }

    /**
     * @return Ods[]
     */
    private function resolveOdsItems(string $value, int $rowNumber, MeasureTemplateV23Report $report): array
    {
        $resolved = [];
        foreach (MeasureTemplateV23Schema::splitMultiValueCell($value) as $itemValue) {
            $entity = null;
            foreach (MeasureTemplateV23Schema::lookupCandidates($itemValue) as $candidate) {
                $entity = $this->em->getRepository(Ods::class)->findOneBy(['code' => $candidate])
                    ?? $this->em->getRepository(Ods::class)->findOneBy(['name' => $candidate]);
                if ($entity instanceof Ods) {
                    break;
                }

                if (!str_starts_with(mb_strtoupper($candidate), 'ODS')) {
                    $entity = $this->em->getRepository(Ods::class)->findOneBy(['code' => 'ODS' . mb_strtoupper($candidate)]);
                    if ($entity instanceof Ods) {
                        break;
                    }
                }
            }

            if (!$entity instanceof Ods) {
                $report->addError('invalid_ods', sprintf('Fila %d con ODS desconocido: "%s".', $rowNumber, $itemValue), ['row' => $rowNumber, 'value' => $itemValue]);
                continue;
            }

            $resolved[] = $entity;
        }

        return $resolved;
    }

    /**
     * @return ImpactArea[]
     */
    private function resolveImpactAreas(string $value, int $rowNumber, MeasureTemplateV23Report $report): array
    {
        return $this->resolveMultipleEntities($value, ImpactArea::class, 'área de impacto', $rowNumber, $report);
    }

    /**
     * @return TripleBalanceAxis[]
     */
    private function resolveTripleBalanceAxes(string $value, int $rowNumber, MeasureTemplateV23Report $report): array
    {
        return $this->resolveMultipleEntities($value, TripleBalanceAxis::class, 'eje de triple balance', $rowNumber, $report);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param T[] $resolved
     *
     * @return T[]
     */
    private function resolveMultipleEntities(string $value, string $class, string $label, int $rowNumber, MeasureTemplateV23Report $report, ?callable $labelCallback = null): array
    {
        $resolved = [];
        foreach (MeasureTemplateV23Schema::splitMultiValueCell($value) as $itemValue) {
            $entity = null;
            foreach (MeasureTemplateV23Schema::lookupCandidates($itemValue) as $candidate) {
                $entity = $this->em->getRepository($class)->findOneBy(['code' => $candidate])
                    ?? $this->em->getRepository($class)->findOneBy(['name' => $candidate]);
                if ($entity instanceof $class) {
                    break;
                }
            }

            if (!$entity instanceof $class) {
                $report->addError('invalid_' . str_replace(' ', '_', $label), sprintf('Fila %d con %s desconocido: "%s".', $rowNumber, $label, $itemValue), ['row' => $rowNumber, 'value' => $itemValue]);
                continue;
            }

            $resolved[] = $entity;
        }

        return $resolved;
    }

    /**
     * @param array<int, array{priority:int, value:string}> $values
     *
     * @return array<int, array{priority:int, source:VerificationSource}>
     */
    private function resolveVerificationSources(array $values, int $rowNumber, MeasureTemplateV23Report $report): array
    {
        $resolved = [];
        foreach ($values as $item) {
            $priority = (int) ($item['priority'] ?? 0);
            $value = trim((string) ($item['value'] ?? ''));
            if ($priority < 1 || $priority > 3 || $value === '') {
                $report->addError('invalid_verification_source', sprintf('Fila %d con fuente de verificación inválida.', $rowNumber), ['row' => $rowNumber, 'value' => $value, 'priority' => $priority]);
                continue;
            }

            $source = null;
            foreach (MeasureTemplateV23Schema::lookupCandidates($value) as $candidate) {
                $source = $this->em->getRepository(VerificationSource::class)->findOneBy(['code' => $candidate])
                    ?? $this->em->getRepository(VerificationSource::class)->findOneBy(['name' => $candidate]);
                if ($source instanceof VerificationSource) {
                    break;
                }
            }

            if (!$source instanceof VerificationSource) {
                $report->addError('invalid_verification_source', sprintf('Fila %d con fuente de verificación desconocida: "%s".', $rowNumber, $value), ['row' => $rowNumber, 'value' => $value]);
                continue;
            }

            $resolved[] = [
                'priority' => $priority,
                'source' => $source,
            ];
        }

        usort($resolved, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return $resolved;
    }

    /**
     * @param array<int, array{priority:int, source:VerificationSource}> $resolved
     *
     * @return array<int, VerificationSource|null>
     */
    private function mapVerificationSources(array $resolved): array
    {
        $mapped = [1 => null, 2 => null, 3 => null];
        foreach ($resolved as $item) {
            $mapped[(int) $item['priority']] = $item['source'];
        }

        return $mapped;
    }

    private function parseMandatory(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['sí', 'si', 'yes', 'y', 'true', '1'], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildImportHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param Department[] $departments
     */
    private function syncDepartments(Measure $measure, array $departments): void
    {
        foreach ($measure->getDepartments()->toArray() as $existing) {
            $measure->removeDepartment($existing);
        }

        foreach ($departments as $department) {
            $measure->addDepartment($department);
        }
    }

    /**
     * @param Ods[] $odsItems
     */
    private function syncOdsItems(Measure $measure, array $odsItems): void
    {
        foreach ($measure->getOdsItems()->toArray() as $existing) {
            $measure->removeOdsItem($existing);
        }

        foreach ($odsItems as $ods) {
            $measure->addOdsItem($ods);
        }
    }

    /**
     * @param ImpactArea[] $impactAreas
     */
    private function syncImpactAreas(Measure $measure, array $impactAreas): void
    {
        foreach ($measure->getImpactAreas()->toArray() as $existing) {
            $measure->removeImpactArea($existing);
        }

        foreach ($impactAreas as $impactArea) {
            $measure->addImpactArea($impactArea);
        }
    }

    /**
     * @param TripleBalanceAxis[] $axes
     */
    private function syncTripleBalanceAxes(Measure $measure, array $axes): void
    {
        foreach ($measure->getTripleBalanceAxes()->toArray() as $existing) {
            $measure->removeTripleBalanceAxis($existing);
        }

        foreach ($axes as $axis) {
            $measure->addTripleBalanceAxis($axis);
        }
    }

    private function syncTranslations(
        Measure $measure,
        ?string $nameEn,
        ?string $nameReviewEn,
        ?string $descriptionEn,
        ?string $implementationEn,
        ?string $verificationSourcesEn
    ): void {
        /** @var \Gedmo\Translatable\Entity\Repository\TranslationRepository $translationRepository */
        $translationRepository = $this->em->getRepository(Translation::class);

        $translations = [
            'name' => $nameEn,
            'nameReview' => $nameReviewEn,
            'description' => $descriptionEn,
            'implementation' => $implementationEn,
            'verificationSources' => $verificationSourcesEn,
        ];

        foreach ($translations as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $translationRepository->translate($measure, $field, 'en', $value);
        }
    }

    private function getCachedMeasureBlock(Protocol $protocol, string $value): ?MeasureBlock
    {
        $protocolKey = (string) ($protocol->getCode() ?? $protocol->getName() ?? spl_object_id($protocol));
        $cacheKey = $this->normalizeCacheKey($value);

        return $this->measureBlockCache[$protocolKey][$cacheKey] ?? null;
    }

    /**
     * @param array<int, string> $aliases
     */
    private function cacheMeasureBlock(Protocol $protocol, MeasureBlock $block, string ...$aliases): void
    {
        $protocolKey = (string) ($protocol->getCode() ?? $protocol->getName() ?? spl_object_id($protocol));

        foreach ($aliases as $alias) {
            $cacheKey = $this->normalizeCacheKey($alias);
            if ($cacheKey === '') {
                continue;
            }

            $this->measureBlockCache[$protocolKey][$cacheKey] = $block;
        }
    }

    private function normalizeCacheKey(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_strtolower($value);
    }
}
