<?php

namespace App\Tests\Template;

use PHPUnit\Framework\TestCase;

final class PlanD4OdsUiTest extends TestCase
{
    private function template(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/templates/backend/plan/' . $file;
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testTaxonomyShowsOdsAndTripleBalanceButNotImpactAreas(): void
    {
        $source = $this->template('_measure_taxonomy.html.twig');
        self::assertStringContainsString('taxonomyPresenter.odsBadges(measure)', $source);
        self::assertStringContainsString('>ODS</dt>', $source);
        self::assertStringContainsString('taxonomyPresenter.tripleBalanceAxes(measure)', $source);
        self::assertStringContainsString('>Triple balance</dt>', $source);
        self::assertStringNotContainsString('taxonomyPresenter.impactAreas(measure)', $source);
        self::assertStringNotContainsString('<span>Áreas de impacto</span>', $source);
    }

    public function testFilterShowsOdsAndTripleBalanceWithoutImpactAreaSelector(): void
    {
        $source = $this->template('_filters.html.twig');
        self::assertStringContainsString('id="filter-ods"', $source);
        self::assertStringContainsString('id="filter-triple-balance-axis"', $source);
        self::assertStringNotContainsString('id="filter-impact-area"', $source);
        self::assertStringNotContainsString('name="impact_area"', $source);
    }

    public function testLegacyImpactAreaFilterSummaryRemainsVisible(): void
    {
        $source = $this->template('_measure_filters_panel.html.twig');
        self::assertStringContainsString("filters.impact_area|default('')", $source);
    }

    public function testOdsGroupingAndExportAreNotChangedByUiPatch(): void
    {
        $source = $this->template('done.html.twig');
        self::assertStringContainsString("grouping: 'ods'", $source);
        self::assertStringContainsString("grouping: 'impact_area'", $source);
    }
}
