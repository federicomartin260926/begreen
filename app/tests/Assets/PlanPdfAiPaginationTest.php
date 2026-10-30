<?php

declare(strict_types=1);

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class PlanPdfAiPaginationTest extends TestCase
{
    public function testConsecutiveFragmentsOfSameCategorySharePageWhenSafe(): void
    {
        $html = $this->render([ $this->category(4, str_repeat('Resumen ', 10), str_repeat('Futuro ', 10)) ]);

        self::assertSame(1, substr_count($html, 'class="pdf-ai-page report-design-ai-report'));
        self::assertSame(1, substr_count($html, 'class="pdf-ai-page__category-card"'));
        foreach (range(1, 4) as $index) {
            self::assertSame(1, substr_count($html, 'Medida de prueba '.$index));
        }
    }

    public function testLongRealMeasureNamesPreventUnsafeFragmentPacking(): void
    {
        $html = $this->render([ $this->category(5, '', '', 400) ]);

        self::assertSame(2, substr_count($html, 'class="pdf-ai-page report-design-ai-report'));
        foreach (range(1, 5) as $index) {
            self::assertSame(1, substr_count($html, 'Medida de prueba '.$index));
        }
    }

    public function testAtMostTwoCompactFragmentsPerPhysicalPage(): void
    {
        $html = $this->render([ $this->category(9) ]);

        self::assertSame(2, substr_count($html, 'class="pdf-ai-page report-design-ai-report'));
        self::assertSame(2, substr_count($html, 'class="pdf-ai-page__category-card"'));
    }

    private function render(array $categories): string
    {
        $source = file_get_contents(__DIR__.'/../../templates/backend/plan/pdf/_ai_report.html.twig');
        self::assertIsString($source);

        $twig = new Environment(new ArrayLoader([
            'report' => $source,
            'backend/plan/pdf/_watermark.html.twig' => '',
            'backend/plan/pdf/_header.html.twig' => '',
        ]));
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));

        return $twig->render('report', [
            'aiCategorySummaries' => $categories,
            'projectTier' => 'basic',
        ]);
    }

    private function category(int $count, string $summary = '', string $future = '', int $extraNameLength = 0): array
    {
        $measures = [];
        for ($i = 1; $i <= $count; ++$i) {
            $measures[] = [
                'name' => 'Medida de prueba '.$i.str_repeat(' X', intdiv($extraNameLength, 2)),
                'observations' => '',
                'critical' => false,
            ];
        }

        return [
            'key' => 'category:100',
            'name' => 'Oficina',
            'summary' => $summary,
            'futureSummary' => $future,
            'visual' => [
                'color' => '#356859',
                'background' => '#edf5f1',
                'icon' => '',
            ],
            'committedMeasures' => $measures,
        ];
    }
}
