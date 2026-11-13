<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\FrancProjectPlanData;
use PHPUnit\Framework\TestCase;

final class FrancDemoFixtureDataTest extends TestCase
{
    public function testTwoElaborationOnlyDemoProjectsHaveCanonicalCatalogs(): void
    {
        $projects = FrancProjectPlanData::PROJECTS;
        self::assertSame(["JACOB'S CREEK", 'ECOEMBES'], array_keys($projects));
        $expected = ["JACOB'S CREEK" => ['basic', 50, 4, 1], 'ECOEMBES' => ['standard', 100, 3, 3]];
        foreach ($projects as $name => $fixture) {
            [$tier, $measureCount, $logoCount, $blockCount] = $expected[$name];
            self::assertSame($tier, $fixture['tier']);
            self::assertCount($measureCount, $fixture['measures']);
            self::assertCount($logoCount, $fixture['companies']);
            self::assertCount($blockCount, $fixture['blockAnswers']);
            $expectedSelected = $name === "JACOB'S CREEK" ? 37 : 45;
            $expectedDiscarded = $name === "JACOB'S CREEK" ? 11 : 24;
            $expectedNotApplicable = $name === "JACOB'S CREEK" ? 2 : 31;
            $expectedCritical = $name === "JACOB'S CREEK" ? 19 : 23;
            self::assertSame($expectedSelected, count(array_filter($fixture['measures'], static fn (array $m): bool => $m['isApplicable'] && $m['willImplement'] === true)));
            self::assertSame($expectedDiscarded, count(array_filter($fixture['measures'], static fn (array $m): bool => $m['isApplicable'] && $m['willImplement'] === false)));
            self::assertSame($expectedNotApplicable, count(array_filter($fixture['measures'], static fn (array $m): bool => $m['isApplicable'] === false)));
            self::assertSame($expectedCritical, count(array_filter($fixture['measures'], static fn (array $m): bool => $m['isCritical'] === true)));
            $sortOrders = array_column($fixture['measures'], 'sortOrder');
            self::assertCount($measureCount, array_unique($sortOrders));
            foreach ($fixture['measures'] as $measure) {
                self::assertArrayNotHasKey('implemented', $measure);
                self::assertArrayNotHasKey('actionTaken', $measure);
                self::assertArrayNotHasKey('executionIncident', $measure);
                self::assertArrayNotHasKey('evidence', $measure);
                self::assertNotNull($measure['isApplicable']);
                if ($measure['isApplicable']) {
                    self::assertNotNull($measure['willImplement']);
                    self::assertGreaterThanOrEqual(50, mb_strlen(trim($measure['observations'] ?? '')));
                }
            }
        }
    }
}
