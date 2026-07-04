<?php

namespace App\Tests\Service;

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
use App\Service\MeasureCatalogAdminService;
use App\Service\MeasureTemplateImporter;
use App\Service\MeasureTemplateReport;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gedmo\Translatable\TranslatableListener;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateImporterTest extends TestCase
{
    public function testImportPersistsMeasuresForMultipleProtocolsWithoutMixingTaxonomies(): void
    {
        $protocolPeach = (new Protocol())->setCode('peach')->setName('Peach')->setType(Protocol::TYPE_RODAJE);
        $protocolGreenFilm = (new Protocol())->setCode('green-film')->setName('Green Film')->setType(Protocol::TYPE_RODAJE);

        $category = (new Category())->setName('Movilidad')->setSortOrder(70);
        $categoryGhg = (new CategoryGhg())->setName('Emisiones indirectas de GEI debido al transporte');
        $departmentProd = (new Department())->setCode('prod')->setName('Producción')->setSortOrder(10);
        $departmentPost = (new Department())->setCode('post')->setName('Postproducción')->setSortOrder(20);
        $departmentDir = (new Department())->setCode('dir')->setName('Dirección')->setSortOrder(30);
        $ods12 = (new Ods())->setCode('ODS12')->setName('Producción y consumo responsables');
        $ods13 = (new Ods())->setCode('ODS13')->setName('Acción por el clima');
        $ods14 = (new Ods())->setCode('ODS14')->setName('Vida submarina');
        $esg = (new EsG())->setName('Ambiental');
        $scope = (new Scope())->setName('Alcance 1');
        $impactA = (new ImpactArea())->setCode('a')->setName('Cambio Climático');
        $impactB = (new ImpactArea())->setCode('b')->setName('Recursos');
        $impactC = (new ImpactArea())->setCode('c')->setName('Biodiversidad');
        $axisEnv = (new TripleBalanceAxis())->setCode('ambiental')->setName('Ambiental');
        $axisSoc = (new TripleBalanceAxis())->setCode('social')->setName('Social');
        $axisEco = (new TripleBalanceAxis())->setCode('economico')->setName('Económico');
        $sourceFoto = (new VerificationSource())->setCode('foto')->setName('Foto');
        $sourceFactura = (new VerificationSource())->setCode('factura')->setName('Factura / Albarán');
        $sourceCert = (new VerificationSource())->setCode('certificado')->setName('Certif. / Licencia');

        $blockPeach = (new MeasureBlock())
            ->setProtocol($protocolPeach)
            ->setCode('peach__movilidad')
            ->setName('Movilidad')
            ->setSortOrder(1);
        $blockGreen = (new MeasureBlock())
            ->setProtocol($protocolGreenFilm)
            ->setCode('green-film__energia')
            ->setName('Energía')
            ->setSortOrder(2);

        $persistedMeasures = [];
        $repositories = [
            Protocol::class => $this->createRepository(static function (array $criteria) use ($protocolPeach, $protocolGreenFilm): ?Protocol {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;

                if ($code === 'peach' || $name === 'Peach') {
                    return $protocolPeach;
                }
                if ($code === 'green-film' || $name === 'Green Film') {
                    return $protocolGreenFilm;
                }

                return null;
            }),
            Category::class => $this->createRepository(static function (array $criteria) use ($category): ?Category {
                return ($criteria['name'] ?? null) === 'Movilidad' ? $category : null;
            }),
            CategoryGhg::class => $this->createRepository(static fn (array $criteria): ?CategoryGhg => ($criteria['name'] ?? null) === 'Emisiones indirectas de GEI debido al transporte' ? (new CategoryGhg())->setName('Emisiones indirectas de GEI debido al transporte') : null),
            Department::class => $this->createRepository(static function (array $criteria) use ($departmentProd, $departmentPost, $departmentDir): ?Department {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;
                if ($code === 'prod' || $name === 'Producción') {
                    return $departmentProd;
                }
                if ($code === 'post' || $name === 'Postproducción') {
                    return $departmentPost;
                }
                if ($code === 'dir' || $name === 'Dirección') {
                    return $departmentDir;
                }

                return null;
            }),
            Ods::class => $this->createRepository(static function (array $criteria) use ($ods12, $ods13, $ods14): ?Ods {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;
                if ($code === 'ODS12' || $code === '12' || $name === 'Producción y consumo responsables') {
                    return $ods12;
                }
                if ($code === 'ODS13' || $code === '13' || $name === 'Acción por el clima') {
                    return $ods13;
                }
                if ($code === 'ODS14' || $code === '14' || $name === 'Vida submarina') {
                    return $ods14;
                }

                return null;
            }),
            EsG::class => $this->createRepository(static fn (array $criteria): ?EsG => ($criteria['name'] ?? null) === 'Ambiental' ? (new EsG())->setName('Ambiental') : null),
            Scope::class => $this->createRepository(static fn (array $criteria): ?Scope => ($criteria['name'] ?? null) === 'Alcance 1' ? (new Scope())->setName('Alcance 1') : null),
            ImpactArea::class => $this->createRepository(static function (array $criteria) use ($impactA, $impactB, $impactC): ?ImpactArea {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;
                if ($code === 'a' || $name === 'Cambio Climático') {
                    return $impactA;
                }
                if ($code === 'b' || $name === 'Recursos') {
                    return $impactB;
                }
                if ($code === 'c' || $name === 'Biodiversidad') {
                    return $impactC;
                }

                return null;
            }),
            TripleBalanceAxis::class => $this->createRepository(static function (array $criteria) use ($axisEnv, $axisSoc, $axisEco): ?TripleBalanceAxis {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;
                if ($code === 'ambiental' || $name === 'Ambiental') {
                    return $axisEnv;
                }
                if ($code === 'social' || $name === 'Social') {
                    return $axisSoc;
                }
                if ($code === 'economico' || $name === 'Económico') {
                    return $axisEco;
                }

                return null;
            }),
            VerificationSource::class => $this->createRepository(static function (array $criteria) use ($sourceFoto, $sourceFactura, $sourceCert): ?VerificationSource {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;
                if ($code === 'foto' || $name === 'Foto') {
                    return $sourceFoto;
                }
                if ($code === 'factura' || $name === 'Factura / Albarán') {
                    return $sourceFactura;
                }
                if ($code === 'certificado' || $name === 'Certif. / Licencia') {
                    return $sourceCert;
                }

                return null;
            }),
            MeasureBlock::class => $this->createRepository(static function (array $criteria) use ($blockPeach, $blockGreen): ?MeasureBlock {
                $protocol = $criteria['protocol'] ?? null;
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;

                if ($protocol === $blockPeach->getProtocol() && ($code === 'peach__movilidad' || $name === 'Movilidad')) {
                    return $blockPeach;
                }
                if ($protocol === $blockGreen->getProtocol() && ($code === 'green-film__energia' || $name === 'Energía')) {
                    return $blockGreen;
                }

                return null;
            }),
            Measure::class => $this->createRepository(static fn (): ?Measure => null),
            \Gedmo\Translatable\Entity\Translation::class => $this->createRepository(static fn (): ?object => null),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class])) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function ($entity) use (&$persistedMeasures): void {
                if ($entity instanceof Measure) {
                    $persistedMeasures[] = $entity;
                }
            });
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');

        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new MeasureTemplateImporter(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateReport();
        $report->addRow([
            'row' => 2,
            'protocol' => 'peach - Peach',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'peach__movilidad - Movilidad',
            'category' => 'Movilidad',
            'categoryGhg' => 'Emisiones indirectas de GEI debido al transporte',
            'name' => 'Reducir consumo de combustible',
            'nameReview' => 'Se redujo el consumo de combustible',
            'questionText' => '¿Realizarás un inventario?',
            'description' => 'Descripción Peach',
            'implementation' => 'Impl Peach',
            'score' => 5,
            'mandatory' => 'Sí',
            'departments' => 'prod; post',
            'odsItems' => 'ODS12; ODS13',
            'esg' => 'Ambiental',
            'scope' => 'Alcance 1',
            'impactAreas' => 'a; b',
            'tripleBalanceAxes' => 'ambiental; social',
            'verificationSources' => [
                ['priority' => 1, 'value' => 'Foto'],
                ['priority' => 2, 'value' => 'Factura / Albarán'],
                ['priority' => 3, 'value' => 'Certif. / Licencia'],
            ],
            'nameEn' => '',
            'nameReviewEn' => '',
            'questionTextEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);
        $report->addRow([
            'row' => 3,
            'protocol' => 'green-film - Green Film',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'green-film__energia - Energía',
            'category' => 'Movilidad',
            'categoryGhg' => 'Emisiones indirectas de GEI debido al transporte',
            'name' => 'Reducir consumo energético',
            'nameReview' => 'Se redujo el consumo energético',
            'questionText' => '¿Registrarás el consumo?',
            'description' => 'Descripción Green Film',
            'implementation' => 'Impl Green',
            'score' => 4,
            'mandatory' => 'No',
            'departments' => 'dir',
            'odsItems' => 'ODS14',
            'esg' => 'Ambiental',
            'scope' => 'Alcance 1',
            'impactAreas' => 'c',
            'tripleBalanceAxes' => 'economico',
            'verificationSources' => [
                ['priority' => 1, 'value' => 'Foto'],
                ['priority' => 2, 'value' => 'Factura / Albarán'],
                ['priority' => 3, 'value' => 'Certif. / Licencia'],
            ],
            'nameEn' => '',
            'nameReviewEn' => '',
            'questionTextEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);

        $result = $importer->import($report, true);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertCount(2, $persistedMeasures);

        self::assertSame('peach', $persistedMeasures[0]->getProtocol()?->getCode());
        self::assertSame('green-film', $persistedMeasures[1]->getProtocol()?->getCode());
        self::assertCount(2, $persistedMeasures[0]->getResolvedDepartments());
        self::assertCount(2, $persistedMeasures[0]->getResolvedOdsItems());
        self::assertCount(2, $persistedMeasures[0]->getResolvedImpactAreas());
        self::assertCount(2, $persistedMeasures[0]->getResolvedTripleBalanceAxes());
        self::assertCount(3, $persistedMeasures[0]->getResolvedVerificationSourceLinks());
        self::assertSame(1, $persistedMeasures[0]->getResolvedVerificationSourceLinks()[0]->getPriority());
        self::assertSame('¿Realizarás un inventario?', $persistedMeasures[0]->getQuestionText());
        self::assertSame('1. Foto | 2. Factura / Albarán | 3. Certif. / Licencia', $persistedMeasures[0]->getVerificationSourcesSummary());
        self::assertSame('v23', $persistedMeasures[0]->getImportVersion());
        self::assertSame(70, $category->getSortOrder());
        self::assertSame(10, $departmentProd->getSortOrder());
        self::assertSame(20, $departmentPost->getSortOrder());
        self::assertSame(30, $departmentDir->getSortOrder());
        self::assertSame(2, $persistedMeasures[0]->getSortOrder());
        self::assertSame(3, $persistedMeasures[1]->getSourceRow());
        self::assertSame(3, $persistedMeasures[1]->getSortOrder());
    }

    public function testImportUpdatesExistingMeasureWithZeroSortOrder(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-film')->setName('Be Green My Film')->setType(Protocol::TYPE_RODAJE);
        $category = (new Category())->setName('Energía')->setSortOrder(10);
        $department = (new Department())->setCode('prod')->setName('Producción')->setSortOrder(10);
        $existingMeasure = (new Measure())->setProtocol($protocol)->setSortOrder(0);

        $repositories = [
            Protocol::class => $this->createRepository(static fn (array $criteria): ?Protocol => (($criteria['code'] ?? null) === 'be-green-my-film' || ($criteria['name'] ?? null) === 'Be Green My Film') ? $protocol : null),
            Category::class => $this->createRepository(static fn (array $criteria): ?Category => ($criteria['name'] ?? null) === 'Energía' ? $category : null),
            Department::class => $this->createRepository(static fn (array $criteria): ?Department => (($criteria['code'] ?? null) === 'prod' || ($criteria['name'] ?? null) === 'Producción') ? $department : null),
            Measure::class => $this->createRepository(static fn (): ?Measure => $existingMeasure),
            MeasureBlock::class => $this->createRepository(static fn (): ?MeasureBlock => null),
            \Gedmo\Translatable\Entity\Translation::class => $this->createRepository(static fn (): ?object => null),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class])) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new MeasureTemplateImporter(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateReport();
        $report->addRow([
            'row' => 7,
            'protocol' => 'be-green-my-film - Be Green My Film',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => '',
            'category' => 'Energía',
            'categoryGhg' => '',
            'name' => 'Medida existente',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 4,
            'mandatory' => 'No',
            'departments' => 'prod',
            'odsItems' => '',
            'esg' => '',
            'scope' => '',
            'impactAreas' => '',
            'tripleBalanceAxes' => '',
            'verificationSources' => [],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
            'departmentActionText' => '',
            'departmentActionTextEn' => '',
        ]);

        $result = $importer->import($report, true, false);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertSame(7, $existingMeasure->getSourceRow());
        self::assertSame(7, $existingMeasure->getSortOrder());
    }

    public function testImportPersistsDepartmentActionTextAndEnglishTranslation(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-film')->setName('Be Green My Film')->setType(Protocol::TYPE_RODAJE);
        $department = (new Department())->setCode('prod')->setName('Producción');

        $persistedMeasures = [];
        $repositories = [
            Protocol::class => $this->createRepository(static fn (array $criteria): ?Protocol => (($criteria['code'] ?? null) === 'be-green-my-film' || ($criteria['name'] ?? null) === 'Be Green My Film') ? $protocol : null),
            Department::class => $this->createRepository(static fn (array $criteria): ?Department => (($criteria['code'] ?? null) === 'prod' || ($criteria['name'] ?? null) === 'Producción') ? $department : null),
            Measure::class => $this->createRepository(static fn (): ?Measure => null),
            MeasureBlock::class => $this->createRepository(static fn (): ?MeasureBlock => null),
            \Gedmo\Translatable\Entity\Translation::class => $this->createTranslationRepository(),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class])) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->method('persist')->willReturnCallback(static function ($entity) use (&$persistedMeasures): void {
            if ($entity instanceof Measure) {
                $persistedMeasures[] = $entity;
            }
        });
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new MeasureTemplateImporter(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateReport();
        $report->addRow([
            'row' => 2,
            'protocol' => 'be-green-my-film - Be Green My Film',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => '',
            'category' => '',
            'categoryGhg' => '',
            'name' => 'Medida con acción por departamento',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 5,
            'mandatory' => 'No',
            'departments' => 'prod',
            'odsItems' => '',
            'esg' => '',
            'scope' => '',
            'impactAreas' => '',
            'tripleBalanceAxes' => '',
            'verificationSources' => [],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
            'departmentActionText' => 'Acción por departamento',
            'departmentActionTextEn' => 'Department action text EN',
        ]);

        $result = $importer->import($report, true, false);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertCount(1, $persistedMeasures);
        self::assertSame('Acción por departamento', $persistedMeasures[0]->getDepartmentActionText());
    }

    public function testImportReusesMeasureBlockWithinSameBatch(): void
    {
        $protocolPeach = (new Protocol())->setCode('peach')->setName('Peach')->setType(Protocol::TYPE_RODAJE);
        $blockPeach = (new MeasureBlock())
            ->setProtocol($protocolPeach)
            ->setCode('peach__movilidad')
            ->setName('Movilidad')
            ->setSortOrder(1);

        $persistedMeasures = [];
        $persistedBlocks = [];

        $repositories = [
            Protocol::class => $this->createRepository(static function (array $criteria) use ($protocolPeach): ?Protocol {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;

                return $code === 'peach' || $name === 'Peach' ? $protocolPeach : null;
            }),
            MeasureBlock::class => $this->createRepository(static fn (): ?MeasureBlock => null),
            Measure::class => $this->createRepository(static fn (): ?Measure => null),
            \Gedmo\Translatable\Entity\Translation::class => $this->createRepository(static fn (): ?object => null),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class])) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->method('persist')->willReturnCallback(static function ($entity) use (&$persistedMeasures, &$persistedBlocks): void {
            if ($entity instanceof Measure) {
                $persistedMeasures[] = $entity;
            }

            if ($entity instanceof MeasureBlock) {
                $persistedBlocks[] = $entity;
            }
        });
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new MeasureTemplateImporter(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateReport();
        $report->addRow([
            'row' => 2,
            'protocol' => 'peach - Peach',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'peach__movilidad - Movilidad',
            'category' => '',
            'categoryGhg' => '',
            'name' => 'Medida 1',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 5,
            'mandatory' => 'No',
            'departments' => '',
            'odsItems' => '',
            'esg' => '',
            'scope' => '',
            'impactAreas' => '',
            'tripleBalanceAxes' => '',
            'verificationSources' => [],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);
        $report->addRow([
            'row' => 3,
            'protocol' => 'peach - Peach',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'peach__movilidad - Movilidad',
            'category' => '',
            'categoryGhg' => '',
            'name' => 'Medida 2',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 4,
            'mandatory' => 'No',
            'departments' => '',
            'odsItems' => '',
            'esg' => '',
            'scope' => '',
            'impactAreas' => '',
            'tripleBalanceAxes' => '',
            'verificationSources' => [],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);

        $result = $importer->import($report, true);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertCount(1, $persistedBlocks);
        self::assertCount(2, $persistedMeasures);
        self::assertSame('peach__movilidad', $persistedBlocks[0]->getCode());
    }

    public function testImportParsesExplicitMeasureBlockCodeAndLeavesBlankBlockNull(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-film')->setName('Be Green My Film')->setType(Protocol::TYPE_RODAJE);
        $category = (new Category())->setName('Energía');
        $categoryGhg = (new CategoryGhg())->setName('Emisiones indirectas de GEI debido al consumo de energía importada');
        $department = (new Department())->setCode('prod')->setName('Producción');
        $ods = (new Ods())->setCode('ODS7')->setName('Energía asequible y no contaminante');
        $impactArea = (new ImpactArea())->setCode('a')->setName('Cambio Climático');
        $axis = (new TripleBalanceAxis())->setCode('ambiental')->setName('Ambiental');
        $source = (new VerificationSource())->setCode('foto')->setName('Foto');

        $persistedMeasures = [];
        $persistedBlocks = [];

        $repositories = [
            Protocol::class => $this->createRepository(static fn (array $criteria): ?Protocol => (($criteria['code'] ?? null) === 'be-green-my-film' || ($criteria['name'] ?? null) === 'Be Green My Film') ? $protocol : null),
            Category::class => $this->createRepository(static fn (array $criteria): ?Category => ($criteria['name'] ?? null) === 'Energía' ? $category : null),
            CategoryGhg::class => $this->createRepository(static fn (array $criteria): ?CategoryGhg => ($criteria['name'] ?? null) === 'Emisiones indirectas de GEI debido al consumo de energía importada' ? $categoryGhg : null),
            Department::class => $this->createRepository(static fn (array $criteria): ?Department => (($criteria['code'] ?? null) === 'prod' || ($criteria['name'] ?? null) === 'Producción') ? $department : null),
            Ods::class => $this->createRepository(static fn (array $criteria): ?Ods => (($criteria['code'] ?? null) === 'ODS7' || ($criteria['code'] ?? null) === '7' || ($criteria['name'] ?? null) === 'Energía asequible y no contaminante') ? $ods : null),
            EsG::class => $this->createRepository(static fn (array $criteria): ?EsG => ($criteria['name'] ?? null) === 'Ambiental' ? (new EsG())->setName('Ambiental') : null),
            Scope::class => $this->createRepository(static fn (array $criteria): ?Scope => ($criteria['name'] ?? null) === 'Alcance 1' ? (new Scope())->setName('Alcance 1') : null),
            ImpactArea::class => $this->createRepository(static fn (array $criteria): ?ImpactArea => (($criteria['code'] ?? null) === 'a' || ($criteria['name'] ?? null) === 'Cambio Climático') ? $impactArea : null),
            TripleBalanceAxis::class => $this->createRepository(static fn (array $criteria): ?TripleBalanceAxis => (($criteria['code'] ?? null) === 'ambiental' || ($criteria['name'] ?? null) === 'Ambiental') ? $axis : null),
            VerificationSource::class => $this->createRepository(static fn (array $criteria): ?VerificationSource => (($criteria['code'] ?? null) === 'foto' || ($criteria['name'] ?? null) === 'Foto') ? $source : null),
            MeasureBlock::class => $this->createRepository(static fn (): ?MeasureBlock => null),
            Measure::class => $this->createRepository(static fn (): ?Measure => null),
            \Gedmo\Translatable\Entity\Translation::class => $this->createRepository(static fn (): ?object => null),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class])) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->method('persist')->willReturnCallback(static function ($entity) use (&$persistedMeasures, &$persistedBlocks): void {
            if ($entity instanceof Measure) {
                $persistedMeasures[] = $entity;
            }

            if ($entity instanceof MeasureBlock) {
                $persistedBlocks[] = $entity;
            }
        });
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new MeasureTemplateImporter(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateReport();
        $report->addRow([
            'row' => 2,
            'protocol' => 'be-green-my-film - Be Green My Film',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'inventario-y-planificacion - Inventario y planificación',
            'category' => 'Energía',
            'categoryGhg' => 'Emisiones indirectas de GEI debido al consumo de energía importada',
            'name' => 'Medida con bloque',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 5,
            'mandatory' => 'No',
            'departments' => 'prod',
            'odsItems' => 'ODS7',
            'esg' => 'Ambiental',
            'scope' => 'Alcance 1',
            'impactAreas' => 'a',
            'tripleBalanceAxes' => 'ambiental',
            'verificationSources' => [
                ['priority' => 1, 'value' => 'Foto'],
            ],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);
        $report->addRow([
            'row' => 3,
            'protocol' => 'be-green-my-film - Be Green My Film',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => '',
            'category' => 'Energía',
            'categoryGhg' => 'Emisiones indirectas de GEI debido al consumo de energía importada',
            'name' => 'Medida sin bloque',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 4,
            'mandatory' => 'No',
            'departments' => 'prod',
            'odsItems' => 'ODS7',
            'esg' => 'Ambiental',
            'scope' => 'Alcance 1',
            'impactAreas' => 'a',
            'tripleBalanceAxes' => 'ambiental',
            'verificationSources' => [
                ['priority' => 1, 'value' => 'Foto'],
            ],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);

        $result = $importer->import($report, true);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertCount(1, $persistedBlocks);
        self::assertSame('inventario-y-planificacion', $persistedBlocks[0]->getCode());
        self::assertSame('Inventario y planificación', $persistedBlocks[0]->getName());
        self::assertCount(2, $persistedMeasures);
        self::assertSame($persistedBlocks[0], $persistedMeasures[0]->getMeasureBlock());
        self::assertNull($persistedMeasures[1]->getMeasureBlock());
    }

    public function testImportKeepsMeasureBlocksScopedByProtocol(): void
    {
        $protocolPeach = (new Protocol())->setCode('peach')->setName('Peach')->setType(Protocol::TYPE_RODAJE);
        $protocolGreenFilm = (new Protocol())->setCode('green-film')->setName('Green Film')->setType(Protocol::TYPE_RODAJE);

        $blockPeach = (new MeasureBlock())
            ->setProtocol($protocolPeach)
            ->setCode('shared__block')
            ->setName('Bloque compartido')
            ->setSortOrder(1);
        $blockGreen = (new MeasureBlock())
            ->setProtocol($protocolGreenFilm)
            ->setCode('shared__block')
            ->setName('Bloque compartido')
            ->setSortOrder(1);

        $persistedMeasures = [];
        $repositories = [
            Protocol::class => $this->createRepository(static function (array $criteria) use ($protocolPeach, $protocolGreenFilm): ?Protocol {
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;

                if ($code === 'peach' || $name === 'Peach') {
                    return $protocolPeach;
                }
                if ($code === 'green-film' || $name === 'Green Film') {
                    return $protocolGreenFilm;
                }

                return null;
            }),
            MeasureBlock::class => $this->createRepository(static function (array $criteria) use ($blockPeach, $blockGreen): ?MeasureBlock {
                $protocol = $criteria['protocol'] ?? null;
                $code = $criteria['code'] ?? null;
                $name = $criteria['name'] ?? null;

                if ($protocol === $blockPeach->getProtocol() && ($code === 'shared__block' || $name === 'Bloque compartido')) {
                    return $blockPeach;
                }
                if ($protocol === $blockGreen->getProtocol() && ($code === 'shared__block' || $name === 'Bloque compartido')) {
                    return $blockGreen;
                }

                return null;
            }),
            Measure::class => $this->createRepository(static fn (): ?Measure => null),
            \Gedmo\Translatable\Entity\Translation::class => $this->createRepository(static fn (): ?object => null),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class])) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->method('persist')->willReturnCallback(static function ($entity) use (&$persistedMeasures): void {
            if ($entity instanceof Measure) {
                $persistedMeasures[] = $entity;
            }
        });
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new MeasureTemplateImporter(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateReport();
        $report->addRow([
            'row' => 2,
            'protocol' => 'peach - Peach',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'shared__block - Bloque compartido',
            'category' => '',
            'categoryGhg' => '',
            'name' => 'Medida Peach',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 5,
            'mandatory' => 'No',
            'departments' => '',
            'odsItems' => '',
            'esg' => '',
            'scope' => '',
            'impactAreas' => '',
            'tripleBalanceAxes' => '',
            'verificationSources' => [],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);
        $report->addRow([
            'row' => 3,
            'protocol' => 'green-film - Green Film',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'shared__block - Bloque compartido',
            'category' => '',
            'categoryGhg' => '',
            'name' => 'Medida Green',
            'nameReview' => '',
            'description' => '',
            'implementation' => '',
            'score' => 4,
            'mandatory' => 'No',
            'departments' => '',
            'odsItems' => '',
            'esg' => '',
            'scope' => '',
            'impactAreas' => '',
            'tripleBalanceAxes' => '',
            'verificationSources' => [],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
        ]);

        $result = $importer->import($report, true);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertCount(2, $persistedMeasures);
        self::assertNotSame(
            $persistedMeasures[0]->getMeasureBlock(),
            $persistedMeasures[1]->getMeasureBlock(),
            'Each protocol must keep its own block instance even if code/name match'
        );
        self::assertSame('peach', $persistedMeasures[0]->getProtocol()?->getCode());
        self::assertSame('green-film', $persistedMeasures[1]->getProtocol()?->getCode());
    }

    public function testImportResolvesAlojamientoCategoryAliasToAlojamientos(): void
    {
        $protocol = (new Protocol())->setCode('be-green-my-film')->setName('Be Green My Film')->setType(Protocol::TYPE_RODAJE);
        $category = (new Category())->setName('Alojamientos');
        $department = (new Department())->setCode('prod')->setName('Producción');

        $persistedMeasures = [];
        $persistedBlocks = [];
        $repositories = [
            Protocol::class => $this->createRepository(static fn (array $criteria): ?Protocol => (($criteria['code'] ?? null) === 'be-green-my-film' || ($criteria['name'] ?? null) === 'Be Green My Film') ? $protocol : null),
            Category::class => $this->createRepository(static fn (array $criteria): ?Category => ($criteria['name'] ?? null) === 'Alojamientos' ? $category : null),
            Department::class => $this->createRepository(static fn (array $criteria): ?Department => (($criteria['code'] ?? null) === 'prod' || ($criteria['name'] ?? null) === 'Producción') ? $department : null),
            MeasureBlock::class => $this->createRepository(static fn (): ?MeasureBlock => null),
            Measure::class => $this->createRepository(static fn (): ?Measure => null),
            \Gedmo\Translatable\Entity\Translation::class => $this->createRepository(static fn (): ?object => null),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($repositories) {
                if (!isset($repositories[$class])) {
                    throw new \RuntimeException(sprintf('Unexpected repository request for %s', $class));
                }

                return $repositories[$class];
            });

        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function ($entity) use (&$persistedMeasures, &$persistedBlocks): void {
                if ($entity instanceof Measure) {
                    $persistedMeasures[] = $entity;
                }
                if ($entity instanceof MeasureBlock) {
                    $persistedBlocks[] = $entity;
                }
            });
        $entityManager->expects(self::once())->method('flush');

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $entityManager->method('getConnection')->willReturn($connection);

        $importer = new MeasureTemplateImporter(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateReport();
        $report->addRow([
            'row' => 22,
            'protocol' => 'be-green-my-film - Be Green My Film',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'ALOJAMIENTO',
            'category' => 'ALOJAMIENTO',
            'name' => 'Prioriza alojamientos a menos de 10km del rodaje.',
            'description' => 'Descripción',
            'implementation' => '',
            'score' => 1,
            'mandatory' => 'No',
            'departments' => 'prod',
            'odsItems' => '',
            'esg' => '',
            'scope' => '',
            'impactAreas' => '',
            'tripleBalanceAxes' => '',
            'verificationSources' => [],
            'nameEn' => '',
            'nameReviewEn' => '',
            'descriptionEn' => '',
            'implementationEn' => '',
            'verificationSourcesEn' => '',
            'departmentActionText' => 'Texto de acción',
            'departmentActionTextEn' => '',
        ]);

        $result = $importer->import($report, true, false);

        self::assertSame('applied', $result->getImportSummary()['status'] ?? null);
        self::assertCount(1, $persistedMeasures);
        self::assertSame('Alojamientos', $persistedMeasures[0]->getCategory()?->getName());
        self::assertSame('ALOJAMIENTO', $persistedMeasures[0]->getMeasureBlock()?->getName());
    }

    private function createRepository(callable $resolver): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback($resolver);

        return $repository;
    }

    private function createTranslationRepository(): EntityRepository
    {
        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->addMethods(['translate'])
            ->getMock();

        $repository->expects(self::once())
            ->method('translate')
            ->with(
                self::isInstanceOf(Measure::class),
                'departmentActionText',
                'en',
                'Department action text EN'
            );

        return $repository;
    }
}
