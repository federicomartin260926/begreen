<?php

namespace App\Tests\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class CommercialPlanModalTextTest extends TestCase
{
    public function testFeatureInfoModalTextsReflectTheFunctionalModel(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../templates/admin/commercial_plan/form.html.twig');

        self::assertNotFalse($template);

        self::assertSame(2, substr_count($template, 'backend.commercial_plans.form.coming_soon'));

        $catalogues = [
            Yaml::parseFile(__DIR__ . '/../../../translations/messages.es.yaml'),
            Yaml::parseFile(__DIR__ . '/../../../translations/messages.en.yaml'),
        ];
        $keys = ['checklist', 'custom_measures', 'validation_summary', 'branding'];

        foreach ($keys as $key) {
            self::assertStringContainsString(
                sprintf('backend.commercial_plans.form.%s', $key),
                $template
            );
            self::assertStringContainsString(
                sprintf('backend.commercial_plans.form.features_info_modal.%s', $key),
                $template
            );
        }

        foreach ($catalogues as $catalogue) {
            $form = $catalogue['backend']['commercial_plans']['form'];
            self::assertSame([], array_diff($keys, array_keys($form)));
            self::assertSame([], array_diff($keys, array_keys($form['features_info_modal'])));
        }
    }
}
