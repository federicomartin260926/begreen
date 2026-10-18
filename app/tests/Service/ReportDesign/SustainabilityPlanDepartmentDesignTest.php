<?php

declare(strict_types=1);

namespace App\Tests\Service\ReportDesign;

use App\Controller\Admin\ReportDesignController;
use App\Controller\Backend\SustainabilityPlanExportController;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\PlanRepository;
use App\Service\ActiveProjectService;
use App\Service\SustainabilityPlanGroupedPdfExporter;
use App\Service\ReportDesign\ReportDesignCatalog;
use App\Service\ReportDesign\ReportDesignAiGenerator;
use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignManager;
use App\Service\ReportDesign\ReportDesignRenderer;
use App\Service\ReportDesign\ReportDesignStorage;
use App\Service\ReportDesign\ReportDesignValidator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SustainabilityPlanDepartmentDesignTest extends KernelTestCase
{
    public function testSelectorAndPostActionsResolveTheSameDepartmentDefinition(): void
    {
        $controller = new ReportDesignController();
        $catalog = new ReportDesignCatalog();
        $method = new \ReflectionMethod($controller, 'definition');
        $method->setAccessible(true);

        $fromSelector = $method->invoke(
            $controller,
            Request::create('/admin/report-design/?design=sustainability-plan/department'),
            $catalog,
        );
        $fromPost = $method->invoke(
            $controller,
            Request::create('/admin/report-design/generate', 'POST', [
                'document' => 'sustainability-plan',
                'variant' => 'department',
            ]),
            $catalog,
        );
        $parameters = (new \ReflectionMethod($controller, 'definitionParameters'))
            ->invoke($controller, $fromPost);

        self::assertSame('sustainability-plan/department', $fromSelector->key());
        self::assertSame($fromSelector->key(), $fromPost->key());
        self::assertSame([
            'document' => 'sustainability-plan',
            'variant' => 'department',
        ], $parameters);
    }

    public function testDepartmentDraftPreviewRequiresSuperAdmin(): void
    {
        self::bootKernel();
        self::getContainer()->get('security.token_storage')->setToken(null);
        $controller = new ReportDesignController();
        $controller->setContainer(self::getContainer());

        $this->expectException(AccessDeniedException::class);
        $controller->previewGrouped(
            ReportDesignCatalog::VARIANT_DEPARTMENT,
            $this->createMock(ActiveProjectService::class),
            $this->createMock(PlanRepository::class),
        );
    }

    public function testDepartmentDraftPreviewUsesActivePlanAndExactGrouping(): void
    {
        self::bootKernel();
        $project = (new Project())->setName('Proyecto activo');
        $plan = (new Plan())->setProject($project);
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, 41);
        (new \ReflectionProperty(Plan::class, 'id'))->setValue($plan, 51);
        $user = (new User())
            ->setEmail('report-design-superadmin@example.test')
            ->setName('Superadmin')
            ->setRoles(['ROLE_SUPER_ADMIN']);
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
        );
        $activeProject = $this->createMock(ActiveProjectService::class);
        $activeProject->expects(self::exactly(5))->method('getActiveProject')->willReturn($project);
        $plans = $this->createMock(PlanRepository::class);
        $plans->expects(self::exactly(5))->method('findOneBy')
            ->with(['project' => $project])
            ->willReturn($plan);
        $controller = new ReportDesignController();
        $controller->setContainer(self::getContainer());

        $variants = [
            ReportDesignCatalog::VARIANT_DEPARTMENT => '/backend/plan/51/closure/export/department/pdf',
            ReportDesignCatalog::VARIANT_ODS => '/backend/plan/51/closure/export/ods/pdf',
            ReportDesignCatalog::VARIANT_IMPACT_AREA => '/backend/plan/51/closure/export/impact_area/pdf',
            ReportDesignCatalog::VARIANT_CATEGORY => '/backend/plan/51/export/category/pdf',
            ReportDesignCatalog::VARIANT_TRIPLE_BALANCE => '/backend/plan/51/closure/export/triple_balance/pdf',
        ];
        foreach ($variants as $variant => $expectedPath) {
            $response = $controller->previewGrouped($variant, $activeProject, $plans);
            self::assertSame(302, $response->getStatusCode());
            self::assertStringContainsString($expectedPath, $response->getTargetUrl());
            self::assertStringContainsString('report_design=draft', $response->getTargetUrl());
        }
    }

    public function testAllGroupedVariantsShareTheContractAndUseCanonicalKeys(): void
    {
        $catalog = new ReportDesignCatalog();
        $components = new ReportDesignComponentCatalog();
        $expected = [
            'department' => 'sustainability-plan/department',
            'ods' => 'sustainability-plan/ods',
            'impact_area' => 'sustainability-plan/impact-area',
            'category' => 'sustainability-plan/category',
            'triple_balance' => 'sustainability-plan/triple-balance',
        ];

        foreach ($expected as $grouping => $key) {
            $grouped = $catalog->sustainabilityPlanGrouped($grouping);
            self::assertSame($key, $grouped->key());
            self::assertArrayHasKey($key, $catalog->all());
            self::assertSame(['cover', 'grouped_analytics', 'grouped_report'], $components->requiredKeys($grouped));
            self::assertSame($grouping, ReportDesignCatalog::groupingForVariant($grouped->variant));
        }
        self::assertSame(
            ['cover', 'analytics', 'ai_report', 'departments', 'final_conclusion'],
            $components->requiredKeys($catalog->sustainabilityPlanGeneral()),
        );
        self::assertCount(9, $components->requiredKeys($catalog->finalSustainabilityReportGeneral()));
    }

    public function testGroupedValidatorRejectsHtmlWrappersButAcceptsNakedTokens(): void
    {
        $definition = (new ReportDesignCatalog())->sustainabilityPlanDepartment();
        $validator = new ReportDesignValidator(new ReportDesignComponentCatalog(), new ReportDesignDataCatalog());
        $html = '[COMPONENT:cover] [COMPONENT:grouped_analytics] [COMPONENT:grouped_report]';
        $validator->validate($html, '', $definition);
        $this->expectException(\InvalidArgumentException::class);
        $validator->validate('<div>'.$html.'</div>', '', $definition);
    }

    public function testGroupedCssCannotChangePageGeometryOrPagination(): void
    {
        $definition = (new ReportDesignCatalog())->sustainabilityPlanGrouped('ods');
        $validator = new ReportDesignValidator(new ReportDesignComponentCatalog(), new ReportDesignDataCatalog());
        $html = '[COMPONENT:cover] [COMPONENT:grouped_analytics] [COMPONENT:grouped_report]';
        $validator->validate(
            $html,
            '.report-design-grouped-report .pdf-group-measure__title { color: #123456; }',
            $definition,
        );

        foreach ([
            '.report-design-grouped-report { width: 100mm; }',
            '.report-design-grouped-report .pdf-group-measure { page-break-after: always; }',
            '.pdf-group-page__title { color: #123456; }',
        ] as $css) {
            try {
                $validator->validate($html, $css, $definition);
                self::fail('Unsafe grouped CSS was accepted: '.$css);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAiInstructionsApplyTheNakedComponentContractToEveryGroupedVariant(): void
    {
        $generator = (new \ReflectionClass(ReportDesignAiGenerator::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($generator, 'instructions');
        $catalog = new ReportDesignCatalog();

        foreach (['department', 'ods', 'impact_area', 'category', 'triple_balance'] as $grouping) {
            $instructions = $method->invoke($generator, $catalog->sustainabilityPlanGrouped($grouping));
            self::assertStringContainsString('GROUPED PDF CONTRACT (mandatory)', $instructions);
            self::assertStringContainsString(
                '[COMPONENT:cover], [COMPONENT:grouped_analytics] and [COMPONENT:grouped_report]',
                $instructions,
            );
        }
    }

    public function testGroupedApprovedDesignsArePersistedIndependently(): void
    {
        $root = sys_get_temp_dir().'/begreen-73-isolation-'.bin2hex(random_bytes(7));
        try {
            $catalog = new ReportDesignCatalog();
            $storage = new ReportDesignStorage($root);
            $validator = new ReportDesignValidator(new ReportDesignComponentCatalog(), new ReportDesignDataCatalog());
            $manager = new ReportDesignManager($storage, $validator);
            $html = '[COMPONENT:cover] [COMPONENT:grouped_analytics] [COMPONENT:grouped_report]';

            foreach (['department', 'ods', 'impact_area', 'category', 'triple_balance'] as $grouping) {
                $definition = $catalog->sustainabilityPlanGrouped($grouping);
                $manager->saveDraft($definition, $html, '', ['grouping' => $grouping]);
                $manager->approve($definition);
            }

            foreach (['department', 'ods', 'impact_area', 'category', 'triple_balance'] as $grouping) {
                $approved = $storage->loadApproved($catalog->sustainabilityPlanGrouped($grouping));
                self::assertNotNull($approved);
                self::assertSame($grouping, $approved['metadata']['grouping']);
            }
            self::assertNull($storage->loadApproved($catalog->sustainabilityPlanGeneral()));
            self::assertNull($storage->loadApproved($catalog->finalSustainabilityReportGeneral()));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCorruptGroupedSnapshotsAreRejectedAtReadTime(): void
    {
        $root = sys_get_temp_dir().'/begreen-73-corrupt-'.bin2hex(random_bytes(7));
        try {
            $catalog = new ReportDesignCatalog();
            $storage = new ReportDesignStorage($root);
            $components = new ReportDesignComponentCatalog();
            $data = new ReportDesignDataCatalog();
            $validator = new ReportDesignValidator($components, $data);
            $manager = new ReportDesignManager($storage, $validator);
            $renderer = new ReportDesignRenderer(
                $storage,
                $validator,
                $components,
                $data,
                new Environment(new ArrayLoader([])),
            );
            $html = '[COMPONENT:cover] [COMPONENT:grouped_analytics] [COMPONENT:grouped_report]';
            $ods = $catalog->sustainabilityPlanGrouped('ods');
            $manager->saveDraft($ods, $html, '');
            file_put_contents($root.'/sustainability-plan/ods/draft/design.html', 'corrupt');
            try {
                $renderer->renderDraft($ods, []);
                self::fail('A corrupt draft was rendered.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }

            $category = $catalog->sustainabilityPlanGrouped('category');
            $manager->saveDraft($category, $html, '');
            $manager->approve($category);
            file_put_contents($root.'/sustainability-plan/category/approved/design.css', 'corrupt');
            try {
                $renderer->renderApproved($category, []);
                self::fail('A corrupt approved design was rendered.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testExportContextUsesEachApprovedOrDraftVariantWithSafeCorruptionFallback(): void
    {
        $root = sys_get_temp_dir().'/begreen-73-export-'.bin2hex(random_bytes(7));
        try {
            $catalog = new ReportDesignCatalog();
            $storage = new ReportDesignStorage($root);
            $components = new ReportDesignComponentCatalog();
            $data = new ReportDesignDataCatalog();
            $validator = new ReportDesignValidator($components, $data);
            $manager = new ReportDesignManager($storage, $validator);
            $twig = new Environment(new ArrayLoader([
                'backend/plan/pdf/_cover.html.twig' => '<section class="report-design-cover">COVER</section>',
                'backend/plan/pdf/_grouped_analytics.html.twig' => '<section class="report-design-grouped-analytics">ANALYTICS</section>',
                'backend/plan/pdf/_grouped_report.html.twig' => '{% for page in groupedDetailPages %}<section class="report-design-grouped-report">{{ page.groupLabel }}</section>{% endfor %}',
            ]));
            $renderer = new ReportDesignRenderer($storage, $validator, $components, $data, $twig);
            $controller = (new \ReflectionClass(SustainabilityPlanExportController::class))
                ->newInstanceWithoutConstructor();
            (new \ReflectionProperty($controller, 'reportDesignCatalog'))->setValue($controller, $catalog);
            (new \ReflectionProperty($controller, 'reportDesignRenderer'))->setValue($controller, $renderer);
            $apply = new \ReflectionMethod($controller, 'applyGroupedReportDesign');
            $context = [
                'project' => new class { public function getName(): string { return 'QA'; } },
                'groupedDetailPages' => [['groupLabel' => 'Grupo correcto']],
            ];
            $html = '[COMPONENT:cover] [COMPONENT:grouped_analytics] [COMPONENT:grouped_report]';

            foreach (['department', 'ods', 'impact_area', 'category', 'triple_balance'] as $grouping) {
                $definition = $catalog->sustainabilityPlanGrouped($grouping);
                $manager->saveDraft($definition, $html, '', ['grouping' => $grouping]);
                $manager->approve($definition);
                $rendered = $apply->invoke($controller, $grouping, $context, false);
                self::assertStringContainsString('Grupo correcto', $rendered['reportDesignHtml']);
                self::assertSame('', $rendered['reportDesignCss']);
            }

            $category = $catalog->sustainabilityPlanGrouped('category');
            $manager->saveDraft($category, $html, '.report-design-cover .pdf-cover__title { color: #123456; }');
            $draft = $apply->invoke($controller, 'category', $context, true);
            self::assertStringContainsString('Grupo correcto', $draft['reportDesignHtml']);
            self::assertStringContainsString('#123456', $draft['reportDesignCss']);

            file_put_contents($root.'/sustainability-plan/ods/approved/design.html', 'corrupt');
            $fallback = $apply->invoke($controller, 'ods', $context, false);
            self::assertNull($fallback['reportDesignHtml']);
            self::assertSame('', $fallback['reportDesignCss']);

            file_put_contents($root.'/sustainability-plan/category/draft/design.html', 'corrupt');
            $this->expectException(NotFoundHttpException::class);
            $apply->invoke($controller, 'category', $context, true);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDraftApprovalKeepsPhysicalPagesAndOtherDesignsSeparate(): void
    {
        $root = sys_get_temp_dir().'/begreen-73-'.bin2hex(random_bytes(7));
        try {
            $catalog = new ReportDesignCatalog();
            $definition = $catalog->sustainabilityPlanDepartment();
            $storage = new ReportDesignStorage($root);
            $components = new ReportDesignComponentCatalog();
            $data = new ReportDesignDataCatalog();
            $validator = new ReportDesignValidator($components, $data);
            $manager = new ReportDesignManager($storage, $validator);
            $twig = new Environment(new ArrayLoader([
                'backend/plan/pdf/_cover.html.twig' => '<section class="report-design-cover">COVER</section>',
                'backend/plan/pdf/_grouped_analytics.html.twig' => '<section class="report-design-grouped-analytics">ANALYTICS</section>',
                'backend/plan/pdf/_grouped_report.html.twig' => '{% for page in groupedDetailPages %}<section class="report-design-grouped-report">{{ page.groupLabel }}</section>{% endfor %}',
            ]));
            $renderer = new ReportDesignRenderer($storage, $validator, $components, $data, $twig);
            $context = [
                'project' => new class { public function getName(): string { return 'QA'; } },
                'groupedDetailPages' => [['groupLabel' => 'A'], ['groupLabel' => 'B']],
            ];

            self::assertNull($renderer->renderApproved($definition, $context));
            self::assertSame('base', $manager->startingPoint($definition)['source']);
            $manager->saveDraft(
                $definition,
                '[COMPONENT:cover] [COMPONENT:grouped_analytics] [COMPONENT:grouped_report]',
                '',
            );
            self::assertNotNull($renderer->renderDraft($definition, $context));
            self::assertSame('draft', $manager->startingPoint($definition)['source']);
            self::assertNull($renderer->renderApproved($definition, $context));
            $manager->approve($definition);
            self::assertSame('approved', $manager->startingPoint($definition)['source']);
            $rendered = $renderer->renderApproved($definition, $context);
            self::assertNotNull($rendered);
            self::assertSame(2, substr_count($rendered['html'], 'report-design-grouped-report'));
            self::assertStringContainsString('>A</section>', $rendered['html']);
            self::assertStringContainsString('>B</section>', $rendered['html']);
            self::assertNull($storage->loadDraft($definition));
            self::assertNull($storage->loadApproved($catalog->sustainabilityPlanGeneral()));
            self::assertNull($storage->loadApproved($catalog->finalSustainabilityReportGeneral()));
            foreach (['ods', 'impact_area', 'category', 'triple_balance'] as $grouping) {
                self::assertNull($storage->loadApproved($catalog->sustainabilityPlanGrouped($grouping)));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testApprovedDesignPreservesRealDompdfPaginationAndPageContent(): void
    {
        self::bootKernel();
        $context = $this->groupedPdfContext();
        $root = sys_get_temp_dir().'/begreen-73-pdf-'.bin2hex(random_bytes(7));
        mkdir($root, 0777, true);

        try {
            $definition = (new ReportDesignCatalog())->sustainabilityPlanDepartment();
            $storage = new ReportDesignStorage($root.'/designs');
            $components = new ReportDesignComponentCatalog();
            $data = new ReportDesignDataCatalog();
            $validator = new ReportDesignValidator($components, $data);
            $manager = new ReportDesignManager($storage, $validator);
            $manager->saveDraft(
                $definition,
                '[COMPONENT:cover] [COMPONENT:grouped_analytics] [COMPONENT:grouped_report]',
                '.report-design-cover .pdf-cover__title { color: #123456; }'
                .' .report-design-grouped-analytics .pdf-analytics-page__title { color: #123456; }'
                .' .report-design-grouped-report .pdf-group-page__title { color: #123456; }',
            );
            $manager->approve($definition);
            $renderer = new ReportDesignRenderer(
                $storage,
                $validator,
                $components,
                $data,
                self::getContainer()->get('twig'),
            );
            $design = $renderer->renderApproved($definition, $context);
            self::assertNotNull($design);

            $exporter = self::getContainer()->get(SustainabilityPlanGroupedPdfExporter::class);
            $basePdf = $exporter->generate('backend/plan/export/grouped_pdf_visual.html.twig', $context);
            $designedPdf = $exporter->generate('backend/plan/export/grouped_pdf_visual.html.twig', [
                ...$context,
                'reportDesignHtml' => $design['html'],
                'reportDesignCss' => $design['css'],
            ]);
            $basePath = $root.'/base.pdf';
            $designedPath = $root.'/designed.pdf';
            file_put_contents($basePath, $basePdf);
            file_put_contents($designedPath, $designedPdf);

            $basePages = $this->pdfPageCount($basePath);
            $designedPages = $this->pdfPageCount($designedPath);
            self::assertSame(4, $basePages);
            self::assertSame($basePages, $designedPages);

            $markers = [
                1 => 'Proyecto paginado QA',
                2 => 'Resumen por departamentos',
                3 => 'Medida Alfa',
                4 => 'Medida Beta',
            ];
            foreach ($markers as $page => $marker) {
                self::assertSame('A4', $this->pdfPageFormat($basePath, $page));
                self::assertSame('A4', $this->pdfPageFormat($designedPath, $page));
                self::assertStringContainsString($marker, $this->pdfPageText($basePath, $page));
                self::assertStringContainsString($marker, $this->pdfPageText($designedPath, $page));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    /** @return array<string, mixed> */
    private function groupedPdfContext(): array
    {
        $project = (new Project())->setName('Proyecto paginado QA');
        $plan = (new Plan())->setProject($project)->setStatus('completo');
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $fontDataUri = static fn (string $filename): string => sprintf(
            'data:font/ttf;base64,%s',
            base64_encode((string) file_get_contents($projectDir.'/public/fonts/poppins/'.$filename)),
        );

        return [
            'project' => $project,
            'plan' => $plan,
            'grouping' => 'department',
            'groupingLabel' => 'Departamentos',
            'groupingSummaryTitle' => 'Resumen por departamentos',
            'groups' => [],
            'pdfGroupSummary' => [[
                'name' => 'Producción',
                'total' => 2,
                'applicable' => 2,
                'selected' => 2,
                'critical' => 0,
            ]],
            'groupedDetailPages' => [
                [
                    'groupLabel' => 'Producción Alfa',
                    'groupTotal' => 1,
                    'rows' => [[
                        'measureTitle' => 'Medida Alfa',
                        'displayName' => 'Medida Alfa',
                        'observations' => 'Observación Alfa',
                    ]],
                ],
                [
                    'groupLabel' => 'Producción Beta',
                    'groupTotal' => 1,
                    'rows' => [[
                        'measureTitle' => 'Medida Beta',
                        'displayName' => 'Medida Beta',
                        'observations' => 'Observación Beta',
                    ]],
                ],
            ],
            'projectTierLabel' => 'Pro',
            'generatedAt' => new \DateTimeImmutable('2026-09-15 12:00:00'),
            'hasWatermark' => false,
            'commitmentSummary' => [
                'totalOfficialPoints' => 10,
                'planned' => [
                    'points' => 10,
                    'percentageRounded' => 100,
                    'labelKey' => 'backend.plan.commitment.levels.jungle.label',
                    'levelKey' => 'jungle',
                    'pointsToNextLevel' => null,
                ],
            ],
            'currentUserLabel' => 'QA',
            'scoreMax' => 10,
            'scoreGained' => 10,
            'scorePct' => 100,
            'coverIndicators' => [
                'total' => 2,
                'applicable' => 2,
                'toImplement' => 2,
                'critical' => 0,
            ],
            'pdfQuickRead' => [[
                'key' => 'selection',
                'value' => 2,
                'total' => 2,
                'percentage' => 100,
            ]],
            'pdfVisualAssets' => [
                'logo' => '',
                'poppinsRegular' => $fontDataUri('Poppins-Regular.ttf'),
                'poppinsSemiBold' => $fontDataUri('Poppins-SemiBold.ttf'),
                'poppinsBold' => $fontDataUri('Poppins-Bold.ttf'),
                'vegetation' => '',
            ],
        ];
    }

    private function pdfPageCount(string $path): int
    {
        $output = $this->runCommand(['/usr/bin/pdfinfo', $path]);
        self::assertMatchesRegularExpression('/^Pages:\s+(\d+)$/m', $output);
        preg_match('/^Pages:\s+(\d+)$/m', $output, $matches);

        return (int) $matches[1];
    }

    private function pdfPageFormat(string $path, int $page): string
    {
        $output = $this->runCommand(['/usr/bin/pdfinfo', '-f', (string) $page, '-l', (string) $page, $path]);
        self::assertMatchesRegularExpression('/^Page\s+\d+ size:.*\(([^)]+)\)$/m', $output);
        preg_match('/^Page\s+\d+ size:.*\(([^)]+)\)$/m', $output, $matches);

        return $matches[1];
    }

    private function pdfPageText(string $path, int $page): string
    {
        return $this->runCommand([
            '/usr/bin/pdftotext',
            '-f',
            (string) $page,
            '-l',
            (string) $page,
            '-enc',
            'UTF-8',
            $path,
            '-',
        ]);
    }

    /** @param list<string> $command */
    private function runCommand(array $command): string
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error ?: 'PDF command failed.');
        self::assertIsString($output);

        return $output;
    }

    private function removeDirectory(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }
}
