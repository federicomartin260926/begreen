<?php

namespace App\Service\Animation;

use App\Entity\AnimationMeasureMetadata;
use App\Entity\Category;
use App\Entity\CategoryGhg;
use App\Entity\Department;
use App\Entity\EsG;
use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\MeasureVerificationSource;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\Scope;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use RuntimeException;

final class AnimationCatalogImporter
{
    public const string PROTOCOL_CODE = 'be-green-my-animation';
    public const string PROTOCOL_NAME = 'Be Green My Animation';
    public const string IMPORT_VERSION = 'animation-v42';

    private const array CATEGORY_DEFINITIONS = [
        'OFICINA' => ['name' => 'Oficina', 'sortOrder' => 10],
        'CONTENIDOS' => ['name' => 'Contenidos', 'sortOrder' => 110],
        'IA' => ['name' => 'IA', 'sortOrder' => 150],
        'RODAJE' => ['name' => 'Rodaje', 'sortOrder' => 160],
        'TÉCNICAS' => ['name' => 'Técnicas', 'sortOrder' => 170],
        'COMUNES' => ['name' => 'Comunes', 'sortOrder' => 180],
    ];

    private const array ESG_NAMES = [
        'Environmental' => 'Ambiental',
        'Social' => 'Social',
        'Governance' => 'Gobernanza',
    ];

    private const array TRIPLE_BALANCE_CODES = [
        'Ambiental (E)' => 'ambiental',
        'Económico (M)' => 'economico',
        'Social (S)' => 'social',
    ];

    /** @var array<string, CategoryGhg> */
    private array $categoryGhgCache = [];

    /** @var array<string, Department> */
    private array $departmentCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatableListener $translatableListener,
    ) {
    }

    /**
     * @param array<string, AnimationCatalogEntry> $entries
     *
     * @return array{created: int, updated: int, active: int}
     */
    public function import(array $entries): array
    {
        if (AnimationCatalogSourceReader::EXPECTED_ACTIVE_COUNT !== count($entries)) {
            throw new RuntimeException('El importador Animation requiere exactamente 239 entradas activas.');
        }

        $this->categoryGhgCache = [];
        $this->departmentCache = [];
        $this->translatableListener->setTranslatableLocale('es');
        $this->translatableListener->setTranslationFallback(false);

        try {
            $protocol = $this->upsertProtocol();
            $categories = $this->upsertCategories();
            $blocks = $this->upsertBlocks($protocol, $entries);
            $this->deactivateExistingMetadata($protocol);
            $summary = ['created' => 0, 'updated' => 0, 'active' => 0];

            foreach ($entries as $catalogId => $entry) {
                if ($catalogId !== $entry->catalogId) {
                    throw new RuntimeException(sprintf('Clave de importación incoherente para %s.', $entry->catalogId));
                }
                $this->upsertMeasure($protocol, $categories, $blocks, $entry, $summary);
            }

            $this->em->flush();

            return $summary;
        } finally {
            $this->translatableListener->setTranslationFallback(true);
        }
    }

    private function upsertProtocol(): Protocol
    {
        $repository = $this->em->getRepository(Protocol::class);
        $protocol = $repository->findOneBy(['code' => self::PROTOCOL_CODE])
            ?? $repository->findOneBy(['name' => self::PROTOCOL_NAME]);

        if (!$protocol instanceof Protocol) {
            $protocol = new Protocol();
            $this->em->persist($protocol);
        }

        return $protocol
            ->setCode(self::PROTOCOL_CODE)
            ->setName(self::PROTOCOL_NAME)
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);
    }

    /** @return array<string, Category> */
    private function upsertCategories(): array
    {
        $repository = $this->em->getRepository(Category::class);
        $categories = [];

        foreach (self::CATEGORY_DEFINITIONS as $code => $definition) {
            $category = $repository->findOneBy(['name' => $definition['name']]);
            if (!$category instanceof Category) {
                $category = (new Category())
                    ->setName($definition['name'])
                    ->setSortOrder($definition['sortOrder'])
                    ->setEnabledInEmissionCalculator(false);
                $this->em->persist($category);
            }
            $categories[$code] = $category;
        }

        return $categories;
    }

    /**
     * @param array<string, AnimationCatalogEntry> $entries
     *
     * @return array<string, MeasureBlock>
     */
    private function upsertBlocks(Protocol $protocol, array $entries): array
    {
        $repository = $this->em->getRepository(MeasureBlock::class);
        $blocks = [];
        $sortOrder = 0;

        foreach ($entries as $entry) {
            if (isset($blocks[$entry->blockCode])) {
                continue;
            }
            ++$sortOrder;
            $block = $repository->findOneBy(['protocol' => $protocol, 'code' => $entry->blockCode]);
            if (!$block instanceof MeasureBlock) {
                $block = (new MeasureBlock())->setProtocol($protocol)->setCode($entry->blockCode);
                $this->em->persist($block);
            }
            $block
                ->setName($entry->blockName)
                ->setSortOrder($sortOrder)
                ->setHasScreeningQuestion(false)
                ->setScreeningQuestion(null)
                ->setActive(true);
            $blocks[$entry->blockCode] = $block;
        }

        return $blocks;
    }

    private function deactivateExistingMetadata(Protocol $protocol): void
    {
        foreach ($this->em->getRepository(Measure::class)->findBy(['protocol' => $protocol]) as $measure) {
            if ($measure instanceof Measure && $measure->getAnimationMetadata() instanceof AnimationMeasureMetadata) {
                $measure->getAnimationMetadata()->setActive(false);
            }
        }
    }

    /**
     * @param array<string, Category>     $categories
     * @param array<string, MeasureBlock> $blocks
     * @param array{created: int, updated: int, active: int} $summary
     */
    private function upsertMeasure(
        Protocol $protocol,
        array $categories,
        array $blocks,
        AnimationCatalogEntry $entry,
        array &$summary,
    ): void {
        $repository = $this->em->getRepository(Measure::class);
        $measure = $repository->findOneBy(['catalogId' => $entry->catalogId]);
        $created = false;

        if ($measure instanceof Measure && $measure->getProtocol()?->getCode() !== self::PROTOCOL_CODE) {
            throw new RuntimeException(sprintf('Colisión global de catalogId "%s".', $entry->catalogId));
        }
        if (!$measure instanceof Measure) {
            $measure = (new Measure())->setCatalogId($entry->catalogId);
            $this->em->persist($measure);
            $created = true;
        }

        $data = $entry->editorial;
        $category = $categories[$entry->categoryCode] ?? null;
        $block = $blocks[$entry->blockCode] ?? null;
        if (!$category instanceof Category || !$block instanceof MeasureBlock) {
            throw new RuntimeException(sprintf('Categoría o bloque sin resolver para %s.', $entry->catalogId));
        }

        $measure
            ->setCatalogId($entry->catalogId)
            ->setProtocol($protocol)
            ->setCategory($category)
            ->setMeasureBlock($block)
            ->setName(trim((string) $data['name']))
            ->setNameReview($this->nullable($data['nameReview'] ?? null))
            ->setQuestionText($this->nullable($data['questionText'] ?? null))
            ->setGamificationMessage($this->nullable($data['gamificationMessage'] ?? null))
            ->setDescription($this->nullable($data['description'] ?? null))
            ->setImplementation($this->nullable($data['implementation'] ?? null))
            ->setDepartmentActionText($this->nullable($data['departmentActionText'] ?? null))
            ->setScore((int) ($data['score'] ?? 0))
            ->setMandatory($this->boolean($data['mandatory'] ?? null))
            ->setSourceRow($entry->sourceRow)
            ->setSortOrder($entry->filter->visualOrder)
            ->setImportVersion(self::IMPORT_VERSION)
            ->setImportHash($this->importHash($entry));

        $measure->setCategoryGhg($this->resolveCategoryGhg((string) ($data['categoryGhg'] ?? '')));
        $measure->setEsg($this->resolveEsg((string) ($data['esg'] ?? '')));
        $measure->setScope($this->resolveScope((string) ($data['scope'] ?? '')));

        $departments = $this->resolveDepartments((string) ($data['departments'] ?? ''));
        $odsItems = $this->resolveOds((string) ($data['odsItems'] ?? ''));
        $impactAreas = $this->resolveImpactAreas((string) ($data['impactAreas'] ?? ''));
        $axes = $this->resolveTripleBalanceAxes((string) ($data['tripleBalanceAxes'] ?? ''));
        $verificationSources = $this->resolveVerificationSources((array) ($data['verificationSources'] ?? []));

        $this->syncCollections($measure, $departments, $odsItems, $impactAreas, $axes, $verificationSources);
        $measure
            ->setDepartment($departments[0] ?? null)
            ->setOds($odsItems[0] ?? null)
            ->setVerificationSources($this->verificationSourcesText($verificationSources));

        $metadata = $measure->getAnimationMetadata();
        if (!$metadata instanceof AnimationMeasureMetadata) {
            $metadata = (new AnimationMeasureMetadata())->setMeasure($measure);
            $this->em->persist($metadata);
        }
        $metadata->syncFrom($entry->filter, $entry->expectedImpact, $entry->effortCost, $entry->complexity);

        $this->syncTranslations($measure, $data);
        ++$summary[$created ? 'created' : 'updated'];
        ++$summary['active'];
    }

    private function resolveCategoryGhg(string $name): ?CategoryGhg
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }
        if (isset($this->categoryGhgCache[$name])) {
            return $this->categoryGhgCache[$name];
        }
        $repository = $this->em->getRepository(CategoryGhg::class);
        $entity = $repository->findOneBy(['name' => $name]);
        if (!$entity instanceof CategoryGhg) {
            $entity = (new CategoryGhg())->setName($name)->setDescription(null);
            $this->em->persist($entity);
        }

        return $this->categoryGhgCache[$name] = $entity;
    }

    private function resolveEsg(string $sourceName): ?EsG
    {
        $sourceName = trim($sourceName);
        if ('' === $sourceName) {
            return null;
        }
        $name = self::ESG_NAMES[$sourceName] ?? $sourceName;
        $entity = $this->em->getRepository(EsG::class)->findOneBy(['name' => $name]);
        if (!$entity instanceof EsG) {
            throw new RuntimeException(sprintf('ESG Animation sin resolver: "%s".', $sourceName));
        }

        return $entity;
    }

    private function resolveScope(string $name): ?Scope
    {
        $name = trim($name);
        if ('' === $name) {
            return null;
        }
        $entity = $this->em->getRepository(Scope::class)->findOneBy(['name' => $name]);
        if (!$entity instanceof Scope) {
            throw new RuntimeException(sprintf('Alcance Animation sin resolver: "%s".', $name));
        }

        return $entity;
    }

    /** @return list<Department> */
    private function resolveDepartments(string $raw): array
    {
        $repository = $this->em->getRepository(Department::class);
        $resolved = [];
        foreach ($this->split($raw) as $position => $name) {
            $cacheKey = mb_strtolower($name);
            $department = $this->departmentCache[$cacheKey]
                ?? $repository->findOneBy(['name' => $name, 'projectType' => Protocol::TYPE_RODAJE]);
            if (!$department instanceof Department) {
                $department = (new Department())
                    ->setCode($this->slug('animation-department-'.$name))
                    ->setName($name)
                    ->setProjectType(Protocol::TYPE_RODAJE)
                    ->setSortOrder(1000 + $position);
                $this->em->persist($department);
            }
            $this->departmentCache[$cacheKey] = $department;
            $resolved[] = $department;
        }

        return $resolved;
    }

    /** @return list<Ods> */
    private function resolveOds(string $raw): array
    {
        $resolved = [];
        foreach ($this->split($raw) as $code) {
            $ods = $this->em->getRepository(Ods::class)->findOneBy(['code' => 'ODS'.$code]);
            if (!$ods instanceof Ods) {
                throw new RuntimeException(sprintf('ODS Animation sin resolver: "%s".', $code));
            }
            $resolved[] = $ods;
        }

        return $resolved;
    }

    /** @return list<ImpactArea> */
    private function resolveImpactAreas(string $raw): array
    {
        $resolved = [];
        foreach ($this->split($raw) as $value) {
            $code = trim(explode(' - ', $value, 2)[0]);
            $impactArea = $this->em->getRepository(ImpactArea::class)->findOneBy(['code' => $code]);
            if (!$impactArea instanceof ImpactArea) {
                throw new RuntimeException(sprintf('Área de impacto Animation sin resolver: "%s".', $value));
            }
            $resolved[] = $impactArea;
        }

        return $resolved;
    }

    /** @return list<TripleBalanceAxis> */
    private function resolveTripleBalanceAxes(string $raw): array
    {
        $resolved = [];
        foreach ($this->split($raw) as $value) {
            $code = self::TRIPLE_BALANCE_CODES[$value] ?? null;
            $axis = null === $code ? null : $this->em->getRepository(TripleBalanceAxis::class)->findOneBy(['code' => $code]);
            if (!$axis instanceof TripleBalanceAxis) {
                throw new RuntimeException(sprintf('Triple balance Animation sin resolver: "%s".', $value));
            }
            $resolved[] = $axis;
        }

        return $resolved;
    }

    /** @param list<array{priority: int, value: string}> $sourceItems @return list<array{source: VerificationSource, priority: int}> */
    private function resolveVerificationSources(array $sourceItems): array
    {
        $resolved = [];
        $repository = $this->em->getRepository(VerificationSource::class);
        foreach ($sourceItems as $item) {
            $name = trim((string) ($item['value'] ?? ''));
            $source = $repository->findOneBy(['name' => $name]);
            if (!$source instanceof VerificationSource) {
                throw new RuntimeException(sprintf('Fuente de verificación Animation sin resolver: "%s".', $name));
            }
            $resolved[] = ['source' => $source, 'priority' => (int) ($item['priority'] ?? 0)];
        }

        return $resolved;
    }

    /**
     * @param list<Department> $departments
     * @param list<Ods> $odsItems
     * @param list<ImpactArea> $impactAreas
     * @param list<TripleBalanceAxis> $axes
     * @param list<array{source: VerificationSource, priority: int}> $verificationSources
     */
    private function syncCollections(Measure $measure, array $departments, array $odsItems, array $impactAreas, array $axes, array $verificationSources): void
    {
        foreach ($measure->getDepartments()->toArray() as $existing) { $measure->removeDepartment($existing); }
        foreach ($departments as $department) { $measure->addDepartment($department); }
        foreach ($measure->getOdsItems()->toArray() as $existing) { $measure->removeOdsItem($existing); }
        foreach ($odsItems as $ods) { $measure->addOdsItem($ods); }
        foreach ($measure->getImpactAreas()->toArray() as $existing) { $measure->removeImpactArea($existing); }
        foreach ($impactAreas as $impactArea) { $measure->addImpactArea($impactArea); }
        foreach ($measure->getTripleBalanceAxes()->toArray() as $existing) { $measure->removeTripleBalanceAxis($existing); }
        foreach ($axes as $axis) { $measure->addTripleBalanceAxis($axis); }
        $wantedSources = array_map(static fn (array $item): VerificationSource => $item['source'], $verificationSources);
        foreach ($measure->getVerificationSourceLinks()->toArray() as $existing) {
            if (!in_array($existing->getVerificationSource(), $wantedSources, true)) {
                $measure->removeVerificationSourceLink($existing);
            }
        }
        foreach ($verificationSources as $item) {
            $existingLink = null;
            foreach ($measure->getVerificationSourceLinks() as $candidate) {
                if ($candidate->getVerificationSource() === $item['source']) {
                    $existingLink = $candidate;
                    break;
                }
            }
            if ($existingLink instanceof MeasureVerificationSource) {
                $existingLink->setPriority($item['priority']);
                continue;
            }
            $link = (new MeasureVerificationSource())
                ->setVerificationSource($item['source'])
                ->setPriority($item['priority']);
            $measure->addVerificationSourceLink($link);
            $this->em->persist($link);
        }
    }

    /** @param list<array{source: VerificationSource, priority: int}> $items */
    private function verificationSourcesText(array $items): ?string
    {
        $parts = array_map(
            static fn (array $item): string => sprintf('%d. %s', $item['priority'], $item['source']->getName()),
            $items,
        );

        return [] === $parts ? null : implode(' | ', $parts);
    }

    /** @param array<string, mixed> $data */
    private function syncTranslations(Measure $measure, array $data): void
    {
        /** @var \Gedmo\Translatable\Entity\Repository\TranslationRepository $repository */
        $repository = $this->em->getRepository(Translation::class);
        foreach ([
            'name' => 'nameEn',
            'nameReview' => 'nameReviewEn',
            'questionText' => 'questionTextEn',
            'gamificationMessage' => 'gamificationMessageEn',
            'description' => 'descriptionEn',
            'implementation' => 'implementationEn',
            'verificationSources' => 'verificationSourcesEn',
            'departmentActionText' => 'departmentActionTextEn',
        ] as $field => $sourceKey) {
            $value = trim((string) ($data[$sourceKey] ?? ''));
            if ('' !== $value) {
                $repository->translate($measure, $field, 'en', $value);
            }
        }
    }

    private function importHash(AnimationCatalogEntry $entry): string
    {
        return hash('sha256', serialize([
            $entry->catalogId,
            $entry->sourceRow,
            $entry->categoryCode,
            $entry->blockCode,
            $entry->editorial,
            $entry->expectedImpact,
            $entry->effortCost,
            $entry->complexity,
            get_object_vars($entry->filter),
        ]));
    }

    /** @return list<string> */
    private function split(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(';', $value)),
            static fn (string $item): bool => '' !== $item,
        ));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return '' === $value ? null : $value;
    }

    private function boolean(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'sí', 'si', 'x', 'true'], true);
    }

    private function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = false === $ascii ? $value : $ascii;
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }
}
