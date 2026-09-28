<?php

namespace App\Tests\Service\Animation;

use App\Entity\AnimationMeasureMetadata;
use App\Entity\AnimationProjectConfiguration;
use App\Entity\Measure;
use App\Entity\Project;
use App\Entity\Protocol;
use App\Enum\ProjectCatalog;
use App\Service\Animation\AnimationCatalogEntry;
use App\Service\Animation\AnimationCatalogImporter;
use App\Service\Animation\AnimationCatalogSourceReader;
use App\Service\Animation\AnimationConfiguration;
use App\Service\Animation\AnimationConfigurationCatalog;
use App\Service\Animation\AnimationConfigurationFactory;
use App\Service\Animation\AnimationMeasure;
use App\Service\Animation\AnimationMeasureDatasetReader;
use App\Service\Animation\AnimationMeasureProvider;
use App\Service\Animation\AnimationMeasureSelector;
use App\Service\Animation\AnimationProjectMeasureResolver;
use App\Service\MeasureTemplateParser;
use App\Tests\Support\CommercialPlanTestHelpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnimationCatalogSourceReaderTest extends TestCase
{
    use CommercialPlanTestHelpers;

    private const string WORKBOOK = __DIR__.'/../../../public/fixtures/BE_GREEN_MY_ANIMATION_v42_ENTREGA_INFORMATICO.xlsx';

    public function testTransformationReconcilesTheCompleteActiveCatalogByStableId(): void
    {
        $entries = self::entries();

        self::assertCount(239, $entries);
        self::assertCount(239, array_unique(array_keys($entries)));
        self::assertSame(range(1, 239), array_column(array_map(
            static fn (AnimationCatalogEntry $entry): array => ['order' => $entry->filter->visualOrder],
            $entries,
        ), 'order'));

        $categoryCounts = array_count_values(array_map(
            static fn (AnimationCatalogEntry $entry): string => $entry->categoryCode,
            $entries,
        ));
        self::assertSame([
            'OFICINA' => 43,
            'CONTENIDOS' => 10,
            'IA' => 23,
            'RODAJE' => 8,
            'TÉCNICAS' => 64,
            'COMUNES' => 91,
        ], $categoryCounts);

        foreach ($entries as $catalogId => $entry) {
            self::assertSame($catalogId, $entry->catalogId);
            self::assertNotSame('', trim((string) $entry->editorial['name']));
            self::assertNotSame('', trim((string) $entry->editorial['nameEn']));
            self::assertTrue($entry->filter->active);
        }

        self::assertArrayNotHasKey('ANI-U201', $entries);
        self::assertArrayNotHasKey('ANI-U121', $entries);
        self::assertArrayNotHasKey('ANI-U135', $entries);
    }

    public function testRepresentativeSpanishAndEnglishEditorialTextMatchesV42Exactly(): void
    {
        $entry = self::entries()['ANI-U058'];

        self::assertSame('Mide el consumo eléctrico del estudio.', $entry->editorial['name']);
        self::assertSame('Measure studio electricity consumption.', $entry->editorial['nameEn']);
        self::assertSame(
            'Mide el consumo eléctrico del estudio y utiliza submedición cuando aporte valor. Compara los datos con ocupación, climatización, iluminación y cargas técnicas para detectar tendencias y oportunidades de mejora.',
            $entry->editorial['description'],
        );
        self::assertSame(
            'Measure studio electricity consumption and use submetering where useful. Compare data with occupancy, HVAC, lighting and technical loads to identify trends and improvement opportunities.',
            $entry->editorial['descriptionEn'],
        );
        self::assertSame('Alto', $entry->expectedImpact);
        self::assertSame('Medio', $entry->effortCost);
        self::assertSame('Media', $entry->complexity);
    }

    public function testPersistibleMetadataReconstructsTheExactSelectorContractWithoutXlsx(): void
    {
        $entry = self::entries()['ANI-U058'];
        $measure = (new Measure())
            ->setCatalogId($entry->catalogId)
            ->setSortOrder($entry->filter->visualOrder);
        $metadata = (new AnimationMeasureMetadata())
            ->setMeasure($measure)
            ->syncFrom($entry->filter, $entry->expectedImpact, $entry->effortCost, $entry->complexity);

        self::assertEquals($entry->filter, $metadata->toAnimationMeasure());
        self::assertSame($entry->expectedImpact, $metadata->getExpectedImpact());
        self::assertSame($entry->effortCost, $metadata->getEffortCost());
        self::assertSame($entry->complexity, $metadata->getComplexity());
        self::assertSame($entry->filter->visualOrder, $measure->getSortOrder());
    }

    public function testRepeatedSourceTransformationRemainsKeyedWithoutDuplicates(): void
    {
        $first = self::entries();
        $second = self::reader()->read(self::WORKBOOK);

        self::assertSame(array_keys($first), array_keys($second));
        self::assertCount(239, array_replace($first, $second));
    }

    public function testFilmAndEventCanKeepTheirExistingNullableIdentityStrategy(): void
    {
        $measure = (new Measure())
            ->setSourceRow(42)
            ->setImportVersion('v23');

        self::assertNull($measure->getCatalogId());
        self::assertSame(42, $measure->getSourceRow());
        self::assertSame('v23', $measure->getImportVersion());
        self::assertSame('animation-v42', AnimationCatalogImporter::IMPORT_VERSION);
    }

    /**
     * @param list<string> $techniqueLabels
     * @param list<string> $infrastructureLabels
     * @param list<string> $expectedIds
     */
    #[DataProvider('acceptanceCases')]
    public function testPersistibleModelResolverKeepsEveryV42AcceptanceScenarioExact(
        string $plan,
        array $techniqueLabels,
        string $structureLabel,
        string $shootingAnswered,
        array $infrastructureLabels,
        string $processingLevelLabel,
        string $usesAi,
        string $interactive,
        array $expectedIds,
    ): void {
        $configuration = new AnimationConfiguration(
            techniques: self::mapLabels($techniqueLabels, AnimationConfigurationCatalog::TECHNIQUE_LABELS),
            structure: self::mapLabel($structureLabel, AnimationConfigurationCatalog::STRUCTURE_LABELS),
            shootingAnswered: self::yesNo($shootingAnswered),
            processingInfrastructures: self::mapLabels(
                $infrastructureLabels,
                AnimationConfigurationCatalog::PROCESSING_INFRASTRUCTURE_LABELS,
            ),
            processingLevel: self::mapLabel($processingLevelLabel, AnimationConfigurationCatalog::PROCESSING_LEVEL_LABELS),
            usesAi: self::yesNo($usesAi),
            distribution: self::yesNo($interactive) ? [AnimationConfigurationCatalog::INTERACTIVE_DISTRIBUTION] : [],
            plan: strtolower($plan),
        );

        $protocol = (new Protocol())
            ->setCode(AnimationCatalogImporter::PROTOCOL_CODE)
            ->setType(Protocol::TYPE_RODAJE);
        $project = (new Project())
            ->setType(Protocol::TYPE_RODAJE)
            ->setFilmingGenre(ProjectCatalog::FILMING_GENRE_ANIMATION)
            ->setDistributionMedia(self::yesNo($interactive) ? [AnimationConfigurationCatalog::INTERACTIVE_DISTRIBUTION] : ['tv']);
        (new AnimationProjectConfiguration())
            ->setProject($project)
            ->setTechniques($configuration->techniques())
            ->setStructure($configuration->structure())
            ->setShootingAnswered($configuration->shootingAnswered())
            ->setProcessingInfrastructures($configuration->processingInfrastructures())
            ->setProcessingLevel($configuration->processingLevel())
            ->setUsesAi($configuration->usesAi());

        $resolver = new AnimationProjectMeasureResolver(
            new class implements AnimationMeasureProvider {
                public function forProtocol(Protocol $protocol): array { return []; }
            },
            new AnimationConfigurationFactory(),
            new AnimationMeasureSelector(),
            $this->makeProjectFeatureGate(),
        );
        $selected = $resolver->resolveFromMeasures($project, $protocol, self::persistibleMeasures($protocol), strtolower($plan));

        self::assertSame($expectedIds, array_map(static fn (Measure $measure): ?string => $measure->getCatalogId(), $selected));
        self::assertCount(count($expectedIds), $selected);
    }

    /** @return iterable<string, array{string, list<string>, string, string, list<string>, string, string, string, list<string>}> */
    public static function acceptanceCases(): iterable
    {
        yield from AnimationMeasureSelectorTest::acceptanceCases();
    }

    /** @return array<string, AnimationCatalogEntry> */
    private static function entries(): array
    {
        static $entries;
        return $entries ??= self::reader()->read(self::WORKBOOK);
    }

    /** @return list<Measure> */
    private static function persistibleMeasures(Protocol $protocol): array
    {
        $measures = [];
        foreach (self::entries() as $entry) {
            $measure = (new Measure())
                ->setCatalogId($entry->catalogId)
                ->setSortOrder($entry->filter->visualOrder)
                ->setProtocol($protocol);
            (new AnimationMeasureMetadata())
                ->setMeasure($measure)
                ->syncFrom($entry->filter, $entry->expectedImpact, $entry->effortCost, $entry->complexity);
            $measures[] = $measure;
        }

        return $measures;
    }

    private static function reader(): AnimationCatalogSourceReader
    {
        return new AnimationCatalogSourceReader(new MeasureTemplateParser(), new AnimationMeasureDatasetReader());
    }

    /** @param list<string> $labels @param array<string, string> $mapping @return list<string> */
    private static function mapLabels(array $labels, array $mapping): array
    {
        return array_map(static fn (string $label): string => self::mapLabel($label, $mapping), $labels);
    }

    /** @param array<string, string> $mapping */
    private static function mapLabel(string $label, array $mapping): string
    {
        $label = trim($label);
        if (!isset($mapping[$label])) {
            throw new RuntimeException(sprintf('Etiqueta del Excel sin mapeo: "%s".', $label));
        }

        return $mapping[$label];
    }

    private static function yesNo(string $value): bool
    {
        return match (trim($value)) {
            'Sí' => true,
            'No' => false,
            default => throw new RuntimeException(sprintf('Valor Sí/No desconocido: "%s".', $value)),
        };
    }
}
