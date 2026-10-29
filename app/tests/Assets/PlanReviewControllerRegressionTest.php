<?php

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class PlanReviewControllerRegressionTest extends TestCase
{
    public function testUpdateSelectionConflictIsShownWithoutReloadingReview(): void
    {
        $source = file_get_contents(__DIR__.'/../../assets/controllers/plan_review_controller.js');
        self::assertIsString($source);

        $start = strpos($source, 'async saveInlineEdit(event)');
        $end = strpos($source, '  updateActionTakenUi(eventOrInput', $start ?: 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString("fetch('/index.php/backend/plan/update-selection'", $method);
        self::assertStringContainsString('if (!res.ok || !data.success)', $method);
        self::assertStringContainsString('throw new Error(msg);', $method);
        self::assertStringContainsString("this.showModal(this.t('modal.error_title')", $method);
        self::assertStringContainsString('btn.disabled = false;', $method);
    }

    public function testMeasuresConflictIsShownInlineAndAllowsRetry(): void
    {
        $source = file_get_contents(__DIR__.'/../../assets/controllers/plan_measures_controller.js');
        self::assertIsString($source);

        $start = strpos($source, 'async postField(');
        $end = strpos($source, "\n    buildUpdates(", $start ?: 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString('if (!res.ok || !data.success)', $method);
        self::assertStringContainsString("this.showInlineError(message, card);", $method);
        self::assertStringContainsString('triggerButton.disabled = false;', $method);
    }

    public function testEvidenceConflictKeepsModalOpenAndAllowsRetry(): void
    {
        $source = file_get_contents(__DIR__.'/../../assets/controllers/plan_review_controller.js');
        self::assertIsString($source);

        $start = strpos($source, 'async submitEvidenceModal(event)');
        $end = strpos($source, '  hideEvidenceModal(modalEl) {', $start ?: 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString('if (!res.ok || !data.success)', $method);
        self::assertStringContainsString("this.showModal(this.t('modal.error_title')", $method);
        self::assertStringContainsString('btn.disabled = false;', $method);
        self::assertStringContainsString('await this.hideEvidenceModal(modalEl);', $method);
        self::assertTrue(
            strpos($method, 'throw new Error(data.error') < strpos($method, 'await this.hideEvidenceModal(modalEl);')
        );
    }

    public function testSuccessfulEvidenceUploadReplacesOnlyEvidenceBlock(): void
    {
        $source = file_get_contents(__DIR__.'/../../assets/controllers/plan_review_controller.js');
        self::assertIsString($source);

        $start = strpos($source, 'async submitEvidenceModal(event)');
        $end = strpos($source, 'isEvidenceModalSourceRequiredError(error)', $start ?: 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString('this.replaceEvidenceBlock(measureId, data);', $method);
        self::assertStringNotContainsString('this.reloadReviewWithQuery(', $method);
        self::assertStringContainsString('current.replaceWith(replacement);', $method);
        self::assertStringContainsString("'[data-project-evidence-usage]'", $method);
    }
}
