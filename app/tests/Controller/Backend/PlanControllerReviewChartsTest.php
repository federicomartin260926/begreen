<?php

namespace App\Tests\Controller\Backend;

use App\Controller\Backend\PlanController;
use App\Entity\Measure;
use App\Entity\PlanMeasure;
use App\Entity\Protocol;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanControllerReviewChartsTest extends KernelTestCase
{
    public function testBuildReviewChartsConfigCalculatesApplicabilityByPoints(): void
    {
        $controller = $this->getController();
        $protocol = (new Protocol());
        $this->setEntityId($protocol, 101);

        $filteredMeasures = [
            $this->makePlanMeasure($protocol, 10, true, false, false),
        ];
        $measures = [
            $this->makePlanMeasure($protocol, 10, true, false, false),
            $this->makePlanMeasure($protocol, 90, false, false, false),
        ];

        $config = $this->invokeBuildReviewChartsConfig($controller, $filteredMeasures, 101, $measures);

        self::assertEquals([100, 10.0, 90.0], $config['applicability']['datasets'][0]['data']);
        self::assertFalse($config['applicability']['showDataLabels']);
        self::assertFalse($config['applicability']['showLegend']);
        self::assertFalse($config['applicability']['showTitle']);
    }

    public function testBuildReviewChartsConfigUsesPointsAndPercentLabels(): void
    {
        $controller = $this->getController();
        $protocol = (new Protocol());
        $this->setEntityId($protocol, 101);

        $measures = [
            $this->makePlanMeasure($protocol, 10, true, true, true),
            $this->makePlanMeasure($protocol, 20, true, false, false),
            $this->makePlanMeasure($protocol, 15, true, true, false),
            $this->makePlanMeasure($protocol, 5, false, false, false),
        ];

        $config = $this->invokeBuildReviewChartsConfig($controller, $measures, 101);

        self::assertArrayHasKey('applicability', $config);
        self::assertArrayHasKey('commitment', $config);
        self::assertArrayHasKey('compliance', $config);
        self::assertArrayHasKey('achievements', $config);

        self::assertEquals([100, 90.0, 10.0], $config['applicability']['datasets'][0]['data']);
        self::assertEquals([100, 55.6], $config['commitment']['datasets'][0]['data']);
        self::assertEquals([100, 0.0, 60.0, 40.0, 0.0], $config['compliance']['datasets'][0]['data']);
        self::assertEquals([100, 22.2, 77.8], $config['achievements']['datasets'][0]['data']);

        self::assertTrue($config['applicability']['percentValues']);
        self::assertTrue($config['commitment']['percentValues']);
        self::assertTrue($config['compliance']['percentValues']);
        self::assertTrue($config['achievements']['percentValues']);

        self::assertSame('y', $config['commitment']['options']['indexAxis']);
        self::assertSame('y', $config['achievements']['options']['indexAxis']);
        self::assertSame(24, $config['applicability']['options']['layout']['padding']['top']);
        self::assertSame(24, $config['compliance']['options']['layout']['padding']['top']);
        self::assertFalse($config['applicability']['showLegend']);
        self::assertFalse($config['commitment']['showLegend']);
        self::assertFalse($config['compliance']['showLegend']);
        self::assertFalse($config['achievements']['showLegend']);
        self::assertFalse($config['applicability']['showDataLabels']);
        self::assertFalse($config['commitment']['showDataLabels']);
        self::assertFalse($config['compliance']['showDataLabels']);
        self::assertFalse($config['achievements']['showDataLabels']);
        self::assertFalse($config['applicability']['showTitle']);
        self::assertFalse($config['commitment']['showTitle']);
        self::assertFalse($config['compliance']['showTitle']);
        self::assertFalse($config['achievements']['showTitle']);
        self::assertSame(100, $config['applicability']['options']['scales']['y']['max']);
        self::assertSame(100, $config['commitment']['options']['scales']['x']['max']);
        self::assertSame(100, $config['compliance']['options']['scales']['y']['max']);
    }

    private function getController(): PlanController
    {
        self::bootKernel();

        /** @var PlanController $controller */
        $controller = self::getContainer()->get(PlanController::class);

        return $controller;
    }

    /**
     * @param array<int, PlanMeasure> $planMeasures
     *
     * @return array<string, mixed>
     */
    private function invokeBuildReviewChartsConfig(
        PlanController $controller,
        array $planMeasures,
        ?int $protocolId,
        ?array $allPlanMeasures = null
    ): array
    {
        $reflection = new \ReflectionMethod($controller, 'buildReviewChartsConfig');
        $reflection->setAccessible(true);

        /** @var array<string, mixed> $config */
        $config = $reflection->invoke($controller, $planMeasures, $protocolId, $allPlanMeasures);

        return $config;
    }

    private function makePlanMeasure(Protocol $protocol, int $score, bool $applicable, bool $willImplement, bool $implemented): PlanMeasure
    {
        $measure = (new Measure())
            ->setProtocol($protocol)
            ->setScore($score);

        return (new PlanMeasure())
            ->setMeasure($measure)
            ->setIsApplicable($applicable)
            ->setWillImplement($willImplement)
            ->setImplemented($implemented);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        while (!$reflection->hasProperty('id') && ($parent = $reflection->getParentClass())) {
            $reflection = $parent;
        }

        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
