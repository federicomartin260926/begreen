<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignCatalog;
use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignRenderer;
use App\Service\ReportDesign\ReportDesignStorage;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ReportDesignRendererTest extends TestCase
{
    private string $storageDirectory;

    protected function setUp(): void
    {
        $this->storageDirectory = sys_get_temp_dir()
            .'/begreen-report-renderer-'
            .bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storageDirectory);
    }

    public function testNoApprovedDesignReturnsNull(): void
    {
        $renderer = $this->renderer();

        $result = $renderer->renderApproved(
            (new ReportDesignCatalog())->sustainabilityPlanGeneral(),
            $this->context(),
        );

        self::assertNull($result);
    }

    public function testApprovedDesignRendersTrustedComponents(): void
    {
        $catalog = new ReportDesignCatalog();
        $definition = $catalog->sustainabilityPlanGeneral();

        $storage = new ReportDesignStorage(
            $this->storageDirectory
        );

        $storage->saveDraft(
            $definition,
            <<<'HTML'
    [COMPONENT:cover]
    [COMPONENT:analytics]
    [COMPONENT:ai_report]
    [COMPONENT:departments]
    [COMPONENT:final_conclusion]
HTML,
            '.report-design-cover { background: #f3faf5; }'
        );

        $storage->approve($definition);

        $renderer = $this->renderer($storage);

        $result = $renderer->renderApproved(
            $definition,
            $this->context(),
        );

        self::assertNotNull($result);


        self::assertStringContainsString(
            '<article id="cover">COVER</article>',
            $result['html']
        );

        self::assertStringContainsString(
            '<article id="analytics">ANALYTICS</article>',
            $result['html']
        );

        self::assertStringContainsString(
            '<article id="ai">AI</article>',
            $result['html']
        );

        self::assertStringContainsString(
            '<article id="departments">DEPARTMENTS</article>',
            $result['html']
        );

        self::assertStringContainsString(
            '<article id="conclusion">CONCLUSION</article>',
            $result['html']
        );


        self::assertSame(
            '.report-design-cover { background: #f3faf5; }',
            $result['css']
        );
    }

    private function renderer(
        ?ReportDesignStorage $storage = null,
    ): ReportDesignRenderer {
        $components = new ReportDesignComponentCatalog();
        $data = new ReportDesignDataCatalog();

        $twig = new Environment(
            new ArrayLoader([
                'backend/plan/pdf/_cover.html.twig'
                    => '<article id="cover">COVER</article>',
                'backend/plan/pdf/_analytics.html.twig'
                    => '<article id="analytics">ANALYTICS</article>',
                'backend/plan/pdf/_ai_report.html.twig'
                    => '<article id="ai">AI</article>',
                'backend/plan/pdf/_departments.html.twig'
                    => '<article id="departments">DEPARTMENTS</article>',
                'backend/plan/pdf/_final_conclusion.html.twig'
                    => '<article id="conclusion">CONCLUSION</article>',
            ])
        );

        return new ReportDesignRenderer(
            $storage ?? new ReportDesignStorage(
                $this->storageDirectory
            ),
            new ReportDesignValidator($components, $data),
            $components,
            $data,
            $twig,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $project = new class {
            public function getName(): string
            {
                return 'Proyecto <QA>';
            }
        };

        return [
            'project' => $project,
            'currentUserLabel' => 'Fede',
            'scoreGained' => 12,
            'scoreMax' => 20,
            'scorePct' => 60,
            'commitmentSummary' => [
                'planned' => [
                    'percentageRounded' => 75,
                ],
            ],
            'aiGeneralConclusion' => 'Resumen',
            'aiFinalConclusion' => 'Conclusión',
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
