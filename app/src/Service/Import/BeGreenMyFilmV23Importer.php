<?php

namespace App\Service\Import;

use App\Entity\Category;
use App\Entity\Department;
use App\Entity\ImpactArea;
use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\MeasureVerificationSource;
use App\Entity\Ods;
use App\Entity\Protocol;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use App\Repository\CategoryRepository;
use App\Repository\DepartmentRepository;
use App\Repository\MeasureRepository;
use App\Repository\OdsRepository;
use App\Repository\ProtocolRepository;
use Doctrine\ORM\EntityManagerInterface;

final class BeGreenMyFilmV23Importer
{
    private const PROTOCOL_CODE = 'be-green-my-film';
    private const IMPORT_VERSION = 'v23';

    private const DEPARTMENT_NAME_BY_CODE = [
        'prod' => 'Producción',
        'dir' => 'Dirección',
        'foto-cam' => 'Cámara',
        'elec' => 'Iluminación',
        'maq-grip' => 'Maquinistas',
        'son' => 'Sonido',
        'arte' => 'Decoración',
        'const' => 'Construcción decorados',
        'vest' => 'Estilismo',
        'maq-pel' => 'Maquillaje',
        'sfx' => 'SFX',
        'loca' => 'Localizaciones',
        'trans' => 'Transporte',
        'atz' => 'Atrezzo',
        'cast' => 'Casting',
        'cate' => 'Catering',
        'he' => 'HE',
        'post' => 'Montaje',
        'cont' => 'Contabilidad',
        'sost' => 'Sostenibilidad',
        'vet-anim' => 'Vet/Anim',
        'guion' => 'Guionistas',
    ];

    private const ODS_NAME_BY_CODE = [
        '1' => 'Fin de la pobreza',
        '2' => 'Hambre cero',
        '3' => 'Salud y bienestar',
        '4' => 'Educación de calidad',
        '5' => 'Igualdad de género',
        '6' => 'Agua limpia y saneamiento',
        '7' => 'Energía asequible y no contaminante',
        '8' => 'Trabajo decente y crecimiento económico',
        '9' => 'Industria, innovación e infraestructura',
        '10' => 'Reducción de las desigualdades',
        '11' => 'Ciudades y comunidades sostenibles',
        '12' => 'Producción y consumo responsables',
        '13' => 'Acción por el clima',
        '14' => 'Vida submarina',
        '15' => 'Vida de ecosistemas terrestres',
        '16' => 'Paz, justicia e instituciones sólidas',
        '17' => 'Alianzas para lograr los objetivos',
    ];

    private const IMPACT_AREA_NAME_BY_CODE = [
        'a' => 'Cambio Climático',
        'b' => 'Agotamiento Recursos Nat.',
        'c' => 'Biodiversidad',
        'd' => 'Contaminación',
        'e' => 'Cambio Uso Suelo',
        'f' => 'Comunicación y Sensib.',
    ];

    private const TRIPLE_BALANCE_NAME_BY_CODE = [
        'bj' => 'Ambiental',
        'bk' => 'Social',
        'bl' => 'Económico',
    ];

    private const VERIFICATION_SOURCE_NAME_BY_CODE = [
        'af' => 'Factura / Albarán',
        'ag' => 'Foto',
        'ah' => 'Captura / Email',
        'ai' => 'Declaración Resp.',
        'aj' => 'Informe Técnico',
        'ak' => 'Certif. / Licencia',
        'al' => 'Listado / Invent.',
        'am' => 'Ficha Técnica',
        'an' => 'Contrato / Acuerdo',
        'ao' => 'Doc. Producción',
        'ap' => 'Plan / Protocolo',
        'aq' => 'Acta / Registro',
        'ar' => 'Permiso Admin.',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProtocolRepository $protocolRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly DepartmentRepository $departmentRepository,
        private readonly OdsRepository $odsRepository,
        private readonly MeasureRepository $measureRepository,
    ) {
    }

    public function import(BeGreenMyFilmV23Report $report, bool $apply): BeGreenMyFilmV23Report
    {
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
            'protocol' => $this->emptyCounters(),
            'categories' => $this->emptyCounters(),
            'blocks' => $this->emptyCounters(),
            'departments' => $this->emptyCounters(),
            'ods' => $this->emptyCounters(),
            'impactAreas' => $this->emptyCounters(),
            'tripleBalanceAxes' => $this->emptyCounters(),
            'verificationSources' => $this->emptyCounters(),
            'measures' => $this->emptyMeasureCounters(),
        ];

        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $protocol = $this->upsertProtocol($summary);
            $categories = $this->upsertCategories($report, $summary);
            $measureBlocks = $this->upsertMeasureBlocks($report, $protocol, $summary);
            $impactAreas = $this->upsertImpactAreas($report, $summary);
            $tripleBalanceAxes = $this->upsertTripleBalanceAxes($report, $summary);
            $verificationSources = $this->upsertVerificationSources($report, $summary);
            $departments = $this->upsertDepartments($report, $summary);
            $odsItems = $this->upsertOdsItems($report, $summary);

            foreach ($report->getMeasureRows() as $measureData) {
                $this->upsertMeasure(
                    $protocol,
                    $measureData,
                    $categories,
                    $measureBlocks,
                    $departments,
                    $odsItems,
                    $impactAreas,
                    $tripleBalanceAxes,
                    $verificationSources,
                    $summary
                );
            }

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }

        $report->setImportSummary($summary);

        return $report;
    }

    private function upsertProtocol(array &$summary): Protocol
    {
        $protocol = $this->protocolRepository->findOneBy(['code' => self::PROTOCOL_CODE])
            ?? $this->protocolRepository->findOneBy(['name' => 'Be Green My Film']);

        $created = false;
        if (!$protocol) {
            $protocol = new Protocol();
            $this->em->persist($protocol);
            $created = true;
        }

        $protocol
            ->setCode(self::PROTOCOL_CODE)
            ->setName('Be Green My Film')
            ->setType(Protocol::TYPE_RODAJE)
            ->setGroupingBy(Protocol::GROUP_BY_CATEGORY);

        $summary['protocol'][$created ? 'created' : 'updated']++;
        $summary['protocol']['resolved']++;

        return $protocol;
    }

    /**
     * @return array<string, Category>
     */
    private function upsertCategories(BeGreenMyFilmV23Report $report, array &$summary): array
    {
        $items = [];
        foreach ($report->jsonSerialize()['categories'] as $categoryData) {
            $name = (string) ($categoryData['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $category = $this->categoryRepository->findOneBy(['name' => $name]);
            $created = false;
            if (!$category) {
                $category = new Category();
                $this->em->persist($category);
                $created = true;
            }

            $category->setName($name);
            $items[$this->normalizeKey($name)] = $category;
            $summary['categories'][$created ? 'created' : 'updated']++;
            $summary['categories']['resolved']++;
        }

        return $items;
    }

    /**
     * @return array<string, MeasureBlock>
     */
    private function upsertMeasureBlocks(BeGreenMyFilmV23Report $report, Protocol $protocol, array &$summary): array
    {
        $blocks = [];
        $sections = $report->getSectionRows();
        usort($sections, static fn (array $left, array $right): int => ($left['row'] ?? 0) <=> ($right['row'] ?? 0));

        foreach ($sections as $section) {
            $code = (string) ($section['code'] ?? '');
            $name = (string) ($section['name'] ?? '');
            if ($code === '' || $name === '') {
                continue;
            }

            $block = $this->em->getRepository(MeasureBlock::class)->findOneBy(['code' => $code]);
            $created = false;
            if (!$block) {
                $block = new MeasureBlock();
                $block->setProtocol($protocol);
                $this->em->persist($block);
                $created = true;
            }

            $block
                ->setProtocol($protocol)
                ->setCode($code)
                ->setName($name)
                ->setSortOrder((int) ($section['row'] ?? 0))
                ->setSourceRow(isset($section['row']) ? (int) $section['row'] : null);

            $blocks[$code] = $block;
            $summary['blocks'][$created ? 'created' : 'updated']++;
            $summary['blocks']['resolved']++;
        }

        foreach ($sections as $section) {
            $code = (string) ($section['code'] ?? '');
            $parentCode = $section['parentCode'] ?? null;
            if ($code === '' || $parentCode === null || $parentCode === '') {
                continue;
            }

            if (isset($blocks[$code], $blocks[$parentCode])) {
                $blocks[$code]->setParent($blocks[$parentCode]);
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, ImpactArea>
     */
    private function upsertImpactAreas(BeGreenMyFilmV23Report $report, array &$summary): array
    {
        $items = [];
        foreach ($report->jsonSerialize()['impactAreas'] as $item) {
            $code = (string) ($item['code'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($code === '' || $name === '') {
                continue;
            }

            $impactArea = $this->em->getRepository(ImpactArea::class)->findOneBy(['code' => $code]);
            $created = false;
            if (!$impactArea) {
                $impactArea = new ImpactArea();
                $this->em->persist($impactArea);
                $created = true;
            }

            $impactArea
                ->setCode($code)
                ->setName($name)
                ->setSortOrder(count($items) + 1);

            $items[$code] = $impactArea;
            $summary['impactAreas'][$created ? 'created' : 'updated']++;
            $summary['impactAreas']['resolved']++;
        }

        return $items;
    }

    /**
     * @return array<string, TripleBalanceAxis>
     */
    private function upsertTripleBalanceAxes(BeGreenMyFilmV23Report $report, array &$summary): array
    {
        $items = [];
        foreach ($report->jsonSerialize()['tripleBalanceAxes'] as $item) {
            $code = (string) ($item['code'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($code === '' || $name === '') {
                continue;
            }

            $axis = $this->em->getRepository(TripleBalanceAxis::class)->findOneBy(['code' => $code]);
            $created = false;
            if (!$axis) {
                $axis = new TripleBalanceAxis();
                $this->em->persist($axis);
                $created = true;
            }

            $axis
                ->setCode($code)
                ->setName($name)
                ->setSortOrder(count($items) + 1);

            $items[$code] = $axis;
            $summary['tripleBalanceAxes'][$created ? 'created' : 'updated']++;
            $summary['tripleBalanceAxes']['resolved']++;
        }

        return $items;
    }

    /**
     * @return array<string, VerificationSource>
     */
    private function upsertVerificationSources(BeGreenMyFilmV23Report $report, array &$summary): array
    {
        $items = [];
        foreach ($report->jsonSerialize()['verificationSources'] as $item) {
            $code = (string) ($item['code'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($code === '' || $name === '') {
                continue;
            }

            $source = $this->em->getRepository(VerificationSource::class)->findOneBy(['code' => $code]);
            $created = false;
            if (!$source) {
                $source = new VerificationSource();
                $this->em->persist($source);
                $created = true;
            }

            $source
                ->setCode($code)
                ->setName($name)
                ->setSortOrder((int) ($item['firstRow'] ?? 0));

            $items[$code] = $source;
            $summary['verificationSources'][$created ? 'created' : 'updated']++;
            $summary['verificationSources']['resolved']++;
        }

        return $items;
    }

    /**
     * @return array<string, Department>
     */
    private function upsertDepartments(BeGreenMyFilmV23Report $report, array &$summary): array
    {
        $items = [];
        foreach ($report->jsonSerialize()['departments'] as $item) {
            $code = (string) ($item['code'] ?? '');
            $name = $this->departmentNameForCode($code, (string) ($item['name'] ?? ''));
            if ($code === '' || $name === '') {
                continue;
            }

            $department = $this->departmentRepository->findOneBy(['code' => $code])
                ?? $this->departmentRepository->findOneBy(['name' => $name]);
            $created = false;
            if (!$department) {
                $department = new Department();
                $this->em->persist($department);
                $created = true;
            }

            $department
                ->setCode($code)
                ->setName($name);

            if ($code !== 'he' && $department->getProjectType() === null) {
                $department->setProjectType($this->projectTypeForDepartment($code));
            }

            $items[$code] = $department;
            $summary['departments'][$created ? 'created' : 'updated']++;
            $summary['departments']['resolved']++;
        }

        return $items;
    }

    /**
     * @return array<string, Ods>
     */
    private function upsertOdsItems(BeGreenMyFilmV23Report $report, array &$summary): array
    {
        $items = [];
        foreach ($report->jsonSerialize()['ods'] as $item) {
            $sheetCode = (string) ($item['code'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($sheetCode === '' || $name === '') {
                continue;
            }

            $code = 'ODS' . $sheetCode;
            $ods = $this->odsRepository->findOneBy(['code' => $code]);
            $created = false;
            if (!$ods) {
                $ods = new Ods();
                $this->em->persist($ods);
                $created = true;
            }

            $ods
                ->setCode($code)
                ->setName($name);

            $items[$sheetCode] = $ods;
            $summary['ods'][$created ? 'created' : 'updated']++;
            $summary['ods']['resolved']++;
        }

        return $items;
    }

    /**
     * @param array<string, Category> $categories
     * @param array<string, MeasureBlock> $measureBlocks
     * @param array<string, Department> $departments
     * @param array<string, Ods> $odsItems
     * @param array<string, ImpactArea> $impactAreas
     * @param array<string, TripleBalanceAxis> $tripleBalanceAxes
     * @param array<string, VerificationSource> $verificationSources
     */
    private function upsertMeasure(
        Protocol $protocol,
        array $measureData,
        array $categories,
        array $measureBlocks,
        array $departments,
        array $odsItems,
        array $impactAreas,
        array $tripleBalanceAxes,
        array $verificationSources,
        array &$summary
    ): void {
        $row = (int) ($measureData['row'] ?? 0);
        $name = (string) ($measureData['name'] ?? '');
        $categoryName = (string) ($measureData['category'] ?? '');

        $measure = $this->measureRepository->findOneBy([
            'protocol' => $protocol,
            'sourceRow' => $row,
            'importVersion' => self::IMPORT_VERSION,
        ]);

        $created = false;
        if (!$measure) {
            $measure = new Measure();
            $measure->setProtocol($protocol);
            $this->em->persist($measure);
            $created = true;
        }

        $measure
            ->setProtocol($protocol)
            ->setName($name)
            ->setCategory($categories[$this->normalizeKey($categoryName)] ?? $this->resolveCategoryFallback($categoryName))
            ->setMeasureBlock($measureBlocks[$this->normalizeKey((string) ($measureData['blockName'] ?? ''))] ?? $this->resolveMeasureBlockFromData($measureData, $measureBlocks))
            ->setScore((int) ($measureData['score'] ?? 0))
            ->setDescription((string) ($measureData['description'] ?? ''))
            ->setSourceRow($row)
            ->setImportVersion(self::IMPORT_VERSION)
            ->setImportHash($this->buildImportHash($protocol, $measureData));

        $resolvedDepartments = $this->resolveDepartmentsForMeasure($measureData, $departments);
        $resolvedOds = $this->resolveOdsForMeasure($measureData, $odsItems);
        $resolvedImpactAreas = $this->resolveImpactAreasForMeasure($measureData, $impactAreas);
        $resolvedTripleBalanceAxes = $this->resolveTripleBalanceAxesForMeasure($measureData, $tripleBalanceAxes);
        $resolvedVerificationSources = $this->resolveVerificationSourcesForMeasure($measureData, $verificationSources);

        $this->syncMeasureCollections($measure, $resolvedDepartments, $resolvedOds, $resolvedImpactAreas, $resolvedTripleBalanceAxes, $resolvedVerificationSources);

        $measure->setDepartment($resolvedDepartments[0] ?? null);
        $measure->setOds($resolvedOds[0] ?? null);
        $measure->setVerificationSources($this->buildVerificationSourcesString($resolvedVerificationSources));

        $summary['measures'][$created ? 'created' : 'updated']++;
        $summary['measures']['resolved']++;
    }

    /**
     * @param array<string, MeasureBlock> $measureBlocks
     */
    private function resolveMeasureBlockFromData(array $measureData, array $measureBlocks): ?MeasureBlock
    {
        $blockCode = $this->normalizeKey((string) ($measureData['blockCode'] ?? ''));
        return $blockCode !== '' ? ($measureBlocks[$blockCode] ?? null) : null;
    }

    /**
     * @param array<string, Department> $departments
     * @return Department[]
     */
    private function resolveDepartmentsForMeasure(array $measureData, array $departments): array
    {
        $resolved = [];
        foreach ($measureData['departments'] ?? [] as $departmentData) {
            $department = $departments[$departmentData['code']] ?? null;
            if ($department) {
                $resolved[] = $department;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, Ods> $odsItems
     * @return Ods[]
     */
    private function resolveOdsForMeasure(array $measureData, array $odsItems): array
    {
        $resolved = [];
        foreach ($measureData['ods'] ?? [] as $odsData) {
            $ods = $odsItems[(string) ($odsData['code'] ?? '')] ?? null;
            if ($ods) {
                $resolved[] = $ods;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, ImpactArea> $impactAreas
     * @return ImpactArea[]
     */
    private function resolveImpactAreasForMeasure(array $measureData, array $impactAreas): array
    {
        $resolved = [];
        foreach ($measureData['impactAreas'] ?? [] as $impactAreaData) {
            $impactArea = $impactAreas[(string) ($impactAreaData['code'] ?? '')] ?? null;
            if ($impactArea) {
                $resolved[] = $impactArea;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, TripleBalanceAxis> $tripleBalanceAxes
     * @return TripleBalanceAxis[]
     */
    private function resolveTripleBalanceAxesForMeasure(array $measureData, array $tripleBalanceAxes): array
    {
        $resolved = [];
        foreach ($measureData['tripleBalanceAxes'] ?? [] as $axisData) {
            $axis = $tripleBalanceAxes[(string) ($axisData['code'] ?? '')] ?? null;
            if ($axis) {
                $resolved[] = $axis;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, VerificationSource> $verificationSources
     * @return array<int, array{source: VerificationSource, priority: int, code: string, name: string}>
     */
    private function resolveVerificationSourcesForMeasure(array $measureData, array $verificationSources): array
    {
        $resolved = [];
        foreach ($measureData['verificationSources'] ?? [] as $sourceData) {
            $source = $verificationSources[(string) ($sourceData['code'] ?? '')] ?? null;
            if (!$source) {
                continue;
            }

            $resolved[] = [
                'source' => $source,
                'priority' => (int) ($sourceData['priority'] ?? 0),
                'code' => (string) ($sourceData['code'] ?? ''),
                'name' => (string) ($sourceData['name'] ?? ''),
            ];
        }

        usort($resolved, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return $resolved;
    }

    /**
     * @param Department[] $departments
     * @param Ods[] $odsItems
     * @param ImpactArea[] $impactAreas
     * @param TripleBalanceAxis[] $tripleBalanceAxes
     * @param array<int, array{source: VerificationSource, priority: int, code: string, name: string}> $verificationSources
     */
    private function syncMeasureCollections(
        Measure $measure,
        array $departments,
        array $odsItems,
        array $impactAreas,
        array $tripleBalanceAxes,
        array $verificationSources
    ): void {
        foreach ($measure->getDepartments()->toArray() as $existing) {
            $measure->removeDepartment($existing);
        }
        foreach ($departments as $department) {
            $measure->addDepartment($department);
        }

        foreach ($measure->getOdsItems()->toArray() as $existing) {
            $measure->removeOdsItem($existing);
        }
        foreach ($odsItems as $ods) {
            $measure->addOdsItem($ods);
        }

        foreach ($measure->getImpactAreas()->toArray() as $existing) {
            $measure->removeImpactArea($existing);
        }
        foreach ($impactAreas as $impactArea) {
            $measure->addImpactArea($impactArea);
        }

        foreach ($measure->getTripleBalanceAxes()->toArray() as $existing) {
            $measure->removeTripleBalanceAxis($existing);
        }
        foreach ($tripleBalanceAxes as $axis) {
            $measure->addTripleBalanceAxis($axis);
        }

        foreach ($measure->getVerificationSourceLinks()->toArray() as $link) {
            $measure->removeVerificationSourceLink($link);
        }

        foreach ($verificationSources as $item) {
            $link = new MeasureVerificationSource();
            $link
                ->setVerificationSource($item['source'])
                ->setPriority($item['priority']);
            $measure->addVerificationSourceLink($link);
            $this->em->persist($link);
        }
    }

    /**
     * @param array<int, array{source: VerificationSource, priority: int, code: string, name: string}> $verificationSources
     */
    private function buildVerificationSourcesString(array $verificationSources): string
    {
        $parts = [];
        foreach ($verificationSources as $item) {
            $parts[] = sprintf('%d %s', $item['priority'], $item['name']);
        }

        return implode('; ', $parts);
    }

    private function buildImportHash(Protocol $protocol, array $measureData): string
    {
        $payload = [
            'protocol' => $protocol->getCode() ?? $protocol->getName(),
            'row' => $measureData['row'] ?? null,
            'name' => $measureData['name'] ?? null,
            'category' => $measureData['category'] ?? null,
            'score' => $measureData['score'] ?? null,
            'description' => $measureData['description'] ?? null,
            'departments' => array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), $measureData['departments'] ?? []),
            'ods' => array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), $measureData['ods'] ?? []),
            'impactAreas' => array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), $measureData['impactAreas'] ?? []),
            'tripleBalanceAxes' => array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), $measureData['tripleBalanceAxes'] ?? []),
            'verificationSources' => array_map(
                static fn (array $item): string => (string) ($item['priority'] ?? '') . ':' . (string) ($item['code'] ?? ''),
                $measureData['verificationSources'] ?? []
            ),
        ];

        return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function departmentNameForCode(string $code, string $fallback): string
    {
        return self::DEPARTMENT_NAME_BY_CODE[$code] ?? $fallback;
    }

    private function projectTypeForDepartment(string $code): ?string
    {
        return match ($code) {
            'prod', 'dir', 'foto-cam', 'elec', 'maq-grip', 'son', 'arte', 'const', 'vest', 'maq-pel', 'sfx', 'loca', 'trans', 'atz', 'cast', 'guion' => 'rodaje',
            'cate' => null,
            'post', 'cont', 'sost', 'he', 'vet-anim' => null,
            default => null,
        };
    }

    private function resolveCategoryFallback(string $name): ?Category
    {
        if ($name === '') {
            return null;
        }

        $category = $this->categoryRepository->findOneBy(['name' => $name]);
        if ($category) {
            return $category;
        }

        $category = new Category();
        $category->setName($name);
        $this->em->persist($category);
        return $category;
    }

    private function normalizeKey(string $value): string
    {
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $normalized) ?: $value);
        return trim($normalized, '-');
    }

    private function emptyCounters(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'resolved' => 0,
        ];
    }

    private function emptyMeasureCounters(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'resolved' => 0,
        ];
    }
}
