<?php

declare(strict_types=1);

namespace App\Tests\Service\Bgos;

use App\Entity\BgosSubcategoryConfig;
use App\Entity\Project;
use App\Entity\ProjectPhaseDate;
use App\Service\Bgos\BgosCompletionAggregator;
use App\Service\Bgos\BgosCompletionResult;
use App\Service\Bgos\BgosCompletionCalculator;
use App\Service\Bgos\BgosDailyRecord;
use App\Service\Bgos\BgosPeriodAssembler;
use PHPUnit\Framework\TestCase;

final class BgosPeriodAssemblerTest extends TestCase
{
    public function testBuildsCategoryAndGlobalTotals(): void
    {
        $project = $this->project();

        $period = $this->assembler()->build(
            $project,
            $this->catalog(),
            $this->configs([
                $this->config($project, 'people', 'daily'),
            ]),
            [
                $this->record('2026-09-10', 30.0),
                $this->record('2026-09-11', 30.0),
            ],
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-11'),
            new \DateTimeImmutable('2026-09-11'),
        );

        self::assertSame(60.0, $period['totalKgCo2e']);

        $transport = $this->category($period['categories'], 'transport');
        self::assertSame(60.0, $transport['totalKgCo2e']);

        $people = $this->subcategory($transport['subcategories'], 'people');
        self::assertSame(60.0, $people['totalKgCo2e']);
        self::assertSame(100.0, $people['completion']->completionPercentage());
        self::assertCount(2, $people['dailyRecords']);
    }

    public function testInactiveConfigKeepsHistoricalEmissionButNoExpectation(): void
    {
        $project = $this->project();

        $config = $this->config($project, 'people', 'daily');
        $config->setActive(false);

        $period = $this->assembler()->build(
            $project,
            $this->catalog(),
            $this->configs([$config]),
            [$this->record('2026-09-10', 25.0)],
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
        );

        $transport = $this->category($period['categories'], 'transport');
        $people = $this->subcategory($transport['subcategories'], 'people');

        self::assertFalse($people['active']);
        self::assertSame(25.0, $people['totalKgCo2e']);
        self::assertSame(0, $people['completion']->expectedCount);
        self::assertNull($people['completion']->completionPercentage());
    }

    public function testDefaultDefinitionCreatesNoExpectation(): void
    {
        $project = $this->project();

        $period = $this->assembler()->build(
            $project,
            $this->catalog(),
            [],
            [],
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
        );

        $transport = $this->category($period['categories'], 'transport');
        $freight = $this->subcategory($transport['subcategories'], 'freight');

        self::assertTrue($freight['active']);
        self::assertFalse($freight['configured']);
        self::assertSame('unconfigured', $freight['trackingStatus']);
        self::assertNull($freight['totalKgCo2e']);
        self::assertSame(0, $freight['completion']->expectedCount);
        self::assertNull($freight['completion']->completionPercentage());
    }

    public function testPersistedNotApplicableIsDifferentFromUnconfigured(): void
    {
        $project = $this->project();

        $period = $this->assembler()->build(
            $project,
            $this->catalog(),
            $this->configs([
                $this->config(
                    $project,
                    'people',
                    BgosSubcategoryConfig::FREQUENCY_NOT_APPLICABLE,
                ),
            ]),
            [
                $this->record('2026-09-10', 12.5),
            ],
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
        );

        $transport = $this->category($period['categories'], 'transport');
        $people = $this->subcategory($transport['subcategories'], 'people');

        self::assertTrue($people['configured']);
        self::assertSame(
            BgosCompletionResult::STATUS_NOT_APPLICABLE,
            $people['trackingStatus'],
        );
        self::assertSame(12.5, $people['totalKgCo2e']);
        self::assertSame(0, $people['completion']->expectedCount);
        self::assertNull($people['completion']->completionPercentage());
    }

    public function testNullEmissionDoesNotBecomeZero(): void
    {
        $project = $this->project();

        $period = $this->assembler()->build(
            $project,
            $this->catalog(),
            $this->configs([
                $this->config($project, 'people', 'daily'),
            ]),
            [
                $this->record(
                    '2026-09-10',
                    null,
                    'PENDING_DATA',
                ),
            ],
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-10'),
        );

        $transport = $this->category($period['categories'], 'transport');
        $people = $this->subcategory($transport['subcategories'], 'people');

        self::assertNull($period['totalKgCo2e']);
        self::assertNull($people['totalKgCo2e']);
        self::assertSame(100.0, $people['completion']->completionPercentage());
    }

    private function assembler(): BgosPeriodAssembler
    {
        return new BgosPeriodAssembler(
            new BgosCompletionCalculator(),
            new BgosCompletionAggregator(),
        );
    }

    private function project(): Project
    {
        $project = new Project();

        $project->addPhaseDate(
            (new ProjectPhaseDate())
                ->setPhase('actividad')
                ->setStartDate(new \DateTimeImmutable('2026-09-10'))
                ->setEndDate(new \DateTimeImmutable('2026-09-20'))
        );

        return $project;
    }

    private function config(
        Project $project,
        string $subcategoryKey,
        string $frequency,
    ): BgosSubcategoryConfig {
        return (new BgosSubcategoryConfig())
            ->setProject($project)
            ->setCategoryKey('transport')
            ->setSubcategoryKey($subcategoryKey)
            ->setLabel($subcategoryKey)
            ->setActive(true)
            ->setActivityFrequency($frequency);
    }

    /**
     * @param list<BgosSubcategoryConfig> $configs
     * @return array<string, array<string, BgosSubcategoryConfig>>
     */
    private function configs(array $configs): array
    {
        $result = [];

        foreach ($configs as $config) {
            $result[$config->getCategoryKey()][$config->getSubcategoryKey()] = $config;
        }

        return $result;
    }

    private function record(
        string $date,
        ?float $kgCo2e,
        string $status = 'CALCULATED',
    ): BgosDailyRecord {
        return new BgosDailyRecord(
            recordId: random_int(1, 1000000),
            categoryKey: 'transport',
            subcategoryKey: 'people',
            phaseKey: 'actividad',
            date: new \DateTimeImmutable($date),
            kgCo2e: $kgCo2e,
            status: $status,
        );
    }

    private function catalog(): array
    {
        return [[
            'key' => 'transport',
            'labelKey' => 'transport',
            'subcategories' => [
                [
                    'categoryKey' => 'transport',
                    'subcategoryKey' => 'people',
                    'label' => 'Personas',
                    'labelKey' => null,
                    'groupKey' => null,
                    'groupLabel' => null,
                    'sourceKeys' => ['local', 'travel'],
                ],
                [
                    'categoryKey' => 'transport',
                    'subcategoryKey' => 'freight',
                    'label' => 'Mercancías',
                    'labelKey' => null,
                    'groupKey' => null,
                    'groupLabel' => null,
                    'sourceKeys' => ['freight'],
                ],
            ],
        ]];
    }

    private function category(array $categories, string $key): array
    {
        foreach ($categories as $category) {
            if ($category['key'] === $key) {
                return $category;
            }
        }

        self::fail(sprintf('Category "%s" not found.', $key));
    }

    private function subcategory(array $subcategories, string $key): array
    {
        foreach ($subcategories as $subcategory) {
            if ($subcategory['key'] === $key) {
                return $subcategory;
            }
        }

        self::fail(sprintf('Subcategory "%s" not found.', $key));
    }
}
