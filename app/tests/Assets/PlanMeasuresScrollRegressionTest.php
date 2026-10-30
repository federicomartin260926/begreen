<?php

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class PlanMeasuresScrollRegressionTest extends TestCase
{
    public function testAnswerSelectionKeepsCurrentScrollAndPreservesContinuation(): void
    {
        $source = file_get_contents(__DIR__.'/../../assets/controllers/plan_measures_controller.js');
        self::assertIsString($source);

        $start = strpos($source, '    async postField(');
        self::assertNotFalse($start);
        $end = strpos($source, "\n    buildUpdates(", $start);
        self::assertNotFalse($end);
        $method = substr($source, $start, $end - $start);

        self::assertStringContainsString('this.applyDecisionState(measureId, value);', $method);
        self::assertStringContainsString('this.setSelectedButtonState(measureId, field, value);', $method);
        self::assertStringContainsString('this.syncMeasureState(measureId);', $method);
        self::assertStringNotContainsString('scrollToSection(', $method);
        self::assertStringNotContainsString('scrollToElement(', $method);
        self::assertStringNotContainsString('window.scrollTo(', $method);
        self::assertStringContainsString('window.location.assign(nextUrl);', $method);
        self::assertStringContainsString('this.navigateAfterSave(field, value);', $method);
    }

    public function testOldScrollToCommercialSummaryWasRemoved(): void
    {
        $source = file_get_contents(__DIR__.'/../../assets/controllers/plan_measures_controller.js');
        self::assertIsString($source);
        self::assertStringNotContainsString('scrollToSection(', $source);
        self::assertStringNotContainsString("'#plan-commercial-summary'", $source);
        self::assertStringNotContainsString('window.scrollTo(', $source);
        self::assertStringContainsString("return field === 'completeDecision';", $source);
    }
}
