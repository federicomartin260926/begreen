<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignDefinition;
use App\Service\ReportDesign\ReportDesignManager;
use App\Service\ReportDesign\ReportDesignRenderer;
use App\Service\ReportDesign\ReportDesignStorage;
use App\Service\ReportDesign\ReportDesignValidator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ReportDesignDraftRendererTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            .'/begreen-report-design-draft-'
            .bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }

        @rmdir($this->directory);
    }

    public function testDraftCanBeRenderedWithoutBeingApproved(): void
    {
        $storage = new ReportDesignStorage($this->directory);
        $components = new ReportDesignComponentCatalog();
        $data = new ReportDesignDataCatalog();
        $validator = new ReportDesignValidator($components, $data);

        $manager = new ReportDesignManager(
            $storage,
            $validator,
        );

        $definition = new ReportDesignDefinition(
            'sustainability-plan',
            'general',
            'Plan de Sostenibilidad — PDF general',
        );

        $manager->saveDraft(
            $definition,
            <<<'HTML'
<section class="draft-layout">
[COMPONENT:cover]
[COMPONENT:analytics]
[COMPONENT:ai_report]
[COMPONENT:departments]
[COMPONENT:final_conclusion]
</section>
HTML,
            '.draft-layout { padding: 10px; }',
            ['source' => 'test'],
        );

        $twig = new Environment(new ArrayLoader([
            'backend/plan/pdf/_cover.html.twig' => '<div>COVER</div>',
            'backend/plan/pdf/_analytics.html.twig' => '<div>ANALYTICS</div>',
            'backend/plan/pdf/_ai_report.html.twig' => '<div>AI</div>',
            'backend/plan/pdf/_departments.html.twig' => '<div>DEPARTMENTS</div>',
            'backend/plan/pdf/_final_conclusion.html.twig' => '<div>FINAL</div>',
        ]));

        $renderer = new ReportDesignRenderer(
            $storage,
            $validator,
            $components,
            $data,
            $twig,
        );

        $project = new class {
            public function getName(): string
            {
                return 'Proyecto <QA>';
            }
        };

        $draft = $renderer->renderDraft(
            $definition,
            ['project' => $project],
        );

        self::assertNotNull($draft);
        self::assertStringContainsString(
            '<div>COVER</div>',
            $draft['html'],
        );
        self::assertSame(
            '.draft-layout { padding: 10px; }',
            $draft['css'],
        );

        self::assertNull(
            $renderer->renderApproved(
                $definition,
                ['project' => $project],
            )
        );
    }
}
