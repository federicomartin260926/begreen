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
use App\Service\MeasureTemplateV23Importer;
use App\Service\MeasureTemplateV23Report;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gedmo\Translatable\TranslatableListener;
use PHPUnit\Framework\TestCase;

final class MeasureTemplateV23ImporterTest extends TestCase
{
    public function testImportPersistsMeasuresForMultipleProtocolsWithoutMixingTaxonomies(): void
    {
        $protocolPeach = (new Protocol())->setCode('peach')->setName('Peach')->setType(Protocol::TYPE_RODAJE);
        $protocolGreenFilm = (new Protocol())->setCode('green-film')->setName('Green Film')->setType(Protocol::TYPE_RODAJE);

        $category = (new Category())->setName('Movilidad');
        $categoryGhg = (new CategoryGhg())->setName('Emisiones indirectas de GEI debido al transporte');
        $departmentProd = (new Department())->setCode('prod')->setName('Producción');
        $departmentPost = (new Department())->setCode('post')->setName('Postproducción');
        $departmentDir = (new Department())->setCode('dir')->setName('Dirección');
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
            Category::class => $this->createRepository(static fn (array $criteria): ?Category => ($criteria['name'] ?? null) === 'Movilidad' ? (new Category())->setName('Movilidad') : null),
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

        $importer = new MeasureTemplateV23Importer(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateV23Report();
        $report->addRow([
            'row' => 2,
            'protocol' => 'peach - Peach',
            'projectType' => Protocol::TYPE_RODAJE,
            'measureBlock' => 'peach__movilidad - Movilidad',
            'category' => 'Movilidad',
            'categoryGhg' => 'Emisiones indirectas de GEI debido al transporte',
            'name' => 'Reducir consumo de combustible',
            'nameReview' => 'Se redujo el consumo de combustible',
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
        self::assertSame('1. Foto | 2. Factura / Albarán | 3. Certif. / Licencia', $persistedMeasures[0]->getVerificationSourcesSummary());
        self::assertSame('v23', $persistedMeasures[0]->getImportVersion());
        self::assertSame(3, $persistedMeasures[1]->getSourceRow());
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

        $importer = new MeasureTemplateV23Importer(
            $entityManager,
            $this->createMock(TranslatableListener::class),
            new MeasureCatalogAdminService(),
        );

        $report = new MeasureTemplateV23Report();
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

    private function createRepository(callable $resolver): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturnCallback($resolver);

        return $repository;
    }
}
