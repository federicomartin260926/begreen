<?php

namespace App\Tests\Service\Animation;

use App\Service\Animation\AnimationConfiguration;
use App\Service\Animation\AnimationConfigurationCatalog;
use App\Service\Animation\AnimationMeasure;
use App\Service\Animation\AnimationMeasureDatasetReader;
use App\Service\Animation\AnimationMeasureSelector;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnimationMeasureSelectorTest extends TestCase
{
    private const string WORKBOOK = __DIR__.'/../../../resources/animation/BE_GREEN_MY_ANIMATION_v42_ENTREGA_INFORMATICO.xlsx';

    /**
     * @param list<string> $techniqueLabels
     * @param list<string> $infrastructureLabels
     * @param list<string> $expectedIds
     */
    #[DataProvider('acceptanceCases')]
    public function testSelectsExactlyTheMeasuresExpectedByV42(
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
        $dataset = self::dataset();
        self::assertCount(239, $dataset);
        self::assertCount(239, array_filter($dataset, static fn (AnimationMeasure $measure): bool => $measure->active));

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

        $selected = (new AnimationMeasureSelector())->select($configuration, $dataset);
        self::assertSame($expectedIds, array_column($selected, 'id'));
        self::assertCount(count($expectedIds), $selected);
    }

    /** @return iterable<string, array{string, list<string>, string, string, list<string>, string, string, string, list<string>}> */
    public static function acceptanceCases(): iterable
    {
        $sheet = IOFactory::load(self::WORKBOOK)->getSheetByName('03_CASOS_PRUEBA_DEV');
        if (null === $sheet) {
            throw new RuntimeException('No existe la hoja 03_CASOS_PRUEBA_DEV.');
        }

        $rows = $sheet->toArray(null, true, true, false);
        $headerRow = null;
        $columns = [];
        foreach ($rows as $index => $row) {
            if ('Caso' !== trim((string) ($row[0] ?? ''))) {
                continue;
            }
            $headerRow = $index;
            foreach ($row as $column => $header) {
                $columns[trim((string) $header)] = $column;
            }
            break;
        }

        if (null === $headerRow) {
            throw new RuntimeException('No se ha encontrado la cabecera de casos de prueba.');
        }

        for ($index = $headerRow + 1; $index < count($rows); ++$index) {
            $row = $rows[$index];
            $case = trim((string) ($row[$columns['Caso']] ?? ''));
            if (!str_starts_with($case, 'TC')) {
                break;
            }

            $expectedIds = self::split((string) $row[$columns['IDs esperados en ORDEN_VISUAL']]);
            $expectedCount = (int) $row[$columns['Nº esperado']];
            if ($expectedCount !== count($expectedIds)) {
                throw new RuntimeException(sprintf('%s tiene un total esperado incoherente en el Excel.', $case));
            }

            yield $case => [
                (string) $row[$columns['Plan']],
                self::split((string) $row[$columns['Técnica(s)']]),
                (string) $row[$columns['Perfil']],
                (string) $row[$columns['Rodaje respondido']],
                self::split((string) $row[$columns['Infra procesamiento']]),
                (string) $row[$columns['Nivel']],
                (string) $row[$columns['IA']],
                (string) $row[$columns['Interactivo']],
                $expectedIds,
            ];
        }
    }

    public function testRejectsAnIncompleteConfigurationBeforeSelecting(): void
    {
        $configuration = new AnimationConfiguration([], null, null, [], null, null, null, null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuración Animation incompleta');
        (new AnimationMeasureSelector())->select($configuration, []);
    }

    public function testRejectsAnUnknownConfigurationCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Código desconocido de técnica');

        new AnimationConfiguration(
            ['TEC_DESCONOCIDA'],
            'ESC_MICRO',
            false,
            ['PROC_INFRA_EQUIPOS'],
            'PROC_NIVEL_BASICO',
            false,
            [],
            'basic',
        );
    }

    public function testNeverReturnsAnInactiveMeasure(): void
    {
        $measure = self::measure(['active' => false]);

        self::assertSame([], (new AnimationMeasureSelector())->select(self::completeConfiguration(), [$measure]));
    }

    public function testTechniqueExceptionCannotSkipStructuralIncompatibility(): void
    {
        $measure = self::measure([
            'planCompatibility' => ['PLAN_BASIC_POR_SCORE' => false],
            'forceIfTechniqueMatches' => true,
            'structureCompatibility' => ['ESC_MICRO' => false],
        ]);

        self::assertSame([], (new AnimationMeasureSelector())->select(self::completeConfiguration(), [$measure]));
    }

    public function testOperationalConditionIsInformationalAndDoesNotChangeEligibility(): void
    {
        $withoutCondition = self::measure(['id' => 'ANI-A', 'visualOrder' => 1]);
        $withCondition = self::measure([
            'id' => 'ANI-B',
            'visualOrder' => 2,
            'operationalCondition' => 'Texto humano que no debe interpretarse como filtro.',
        ]);

        self::assertSame(
            ['ANI-A', 'ANI-B'],
            array_column((new AnimationMeasureSelector())->select(
                self::completeConfiguration(),
                [$withoutCondition, $withCondition],
            ), 'id'),
        );
    }

    /** @return list<AnimationMeasure> */
    private static function dataset(): array
    {
        static $dataset;

        return $dataset ??= (new AnimationMeasureDatasetReader())->read(self::WORKBOOK);
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

    /** @return list<string> */
    private static function split(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode('|', $value)),
            static fn (string $item): bool => '' !== $item,
        ));
    }

    private static function completeConfiguration(): AnimationConfiguration
    {
        return new AnimationConfiguration(
            ['TEC_2D_DIGITAL'],
            'ESC_MICRO',
            false,
            ['PROC_INFRA_EQUIPOS'],
            'PROC_NIVEL_BASICO',
            false,
            [],
            'basic',
        );
    }

    /** @param array<string, mixed> $overrides */
    private static function measure(array $overrides = []): AnimationMeasure
    {
        $values = array_replace([
            'id' => 'ANI-TEST',
            'active' => true,
            'visualOrder' => 1,
            'planCompatibility' => ['PLAN_BASIC_POR_SCORE' => true],
            'forceIfTechniqueMatches' => false,
            'forceIfShooting' => false,
            'notApplicableAllowed' => true,
            'operationalCondition' => null,
            'techniqueCompatibility' => ['TEC_2D_DIGITAL' => true],
            'structureCompatibility' => ['ESC_MICRO' => true],
            'processingLevelCompatibility' => ['PROC_NIVEL_BASICO' => true],
            'processingInfrastructureCompatibility' => ['PROC_INFRA_EQUIPOS' => true],
            'shootingCompatibility' => ['RODAJE_NO' => true],
            'aiCompatibility' => ['IA_NO' => true],
            'interactiveCompatibility' => ['INTERACTIVO_NO' => true],
        ], $overrides);

        return new AnimationMeasure(...$values);
    }
}
