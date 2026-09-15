<?php

namespace App\Tests\Template;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\User;
use App\Service\SustainabilityPlanGroupedPdfExporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class PlanClosureTemplateTest extends KernelTestCase
{
    public function testGroupedPdfRendersWithItsVisualAssetContext(): void
    {
        self::bootKernel();

        $project = (new Project())->setName('Proyecto agrupado');
        $plan = (new Plan())->setProject($project)->setStatus('completo');
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $fontDataUri = static fn (string $filename): string => sprintf(
            'data:font/ttf;base64,%s',
            base64_encode((string) file_get_contents($projectDir . '/public/fonts/poppins/' . $filename))
        );

        $pdf = self::getContainer()->get(SustainabilityPlanGroupedPdfExporter::class)->generate(
            'backend/plan/export/grouped_pdf_visual.html.twig',
            [
                'project' => $project,
                'plan' => $plan,
                'grouping' => 'department',
                'groupingLabel' => 'Departamentos',
                'groupingSummaryTitle' => 'Resumen por departamentos',
                'groups' => [],
                'pdfGroupSummary' => [[
                    'name' => 'Producción',
                    'total' => 1,
                    'applicable' => 1,
                    'selected' => 1,
                    'critical' => 0,
                ]],
                'groupedDetailPages' => [[
                    'groupLabel' => 'Producción',
                    'groupTotal' => 1,
                    'rows' => [[
                        'measureTitle' => 'Medida seleccionada',
                        'displayName' => 'Medida seleccionada',
                        'observations' => '',
                    ]],
                ]],
                'projectTierLabel' => 'Pro',
                'generatedAt' => new \DateTimeImmutable('2026-09-15 12:00:00'),
                'hasWatermark' => false,
                'commitmentSummary' => [
                    'totalOfficialPoints' => 5,
                    'planned' => [
                        'points' => 5,
                        'percentageRounded' => 100,
                        'labelKey' => 'backend.plan.commitment.levels.jungle.label',
                        'levelKey' => 'jungle',
                        'pointsToNextLevel' => null,
                    ],
                ],
                'currentUserLabel' => 'QA',
                'scoreMax' => 5,
                'scoreGained' => 5,
                'scorePct' => 100,
                'coverIndicators' => [
                    'total' => 1,
                    'applicable' => 1,
                    'toImplement' => 1,
                    'critical' => 0,
                ],
                'pdfQuickRead' => [[
                    'key' => 'selection',
                    'value' => 1,
                    'total' => 1,
                    'percentage' => 100,
                ]],
                'pdfVisualAssets' => [
                    'logo' => '',
                    'poppinsRegular' => $fontDataUri('Poppins-Regular.ttf'),
                    'poppinsSemiBold' => $fontDataUri('Poppins-SemiBold.ttf'),
                    'poppinsBold' => $fontDataUri('Poppins-Bold.ttf'),
                    'vegetation' => '',
                ],
            ]
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testClosureShowsSummaryActionsCsrfAndDownloadStates(): void
    {
        self::bootKernel();

        $project = (new Project())->setName('Proyecto de cierre');
        $plan = (new Plan())->setProject($project)->setStatus('completo')->markCustomMeasuresCompleted();
        $this->setEntityId($project, 41);
        $this->setEntityId($plan, 51);

        $request = Request::create('/backend/plan/done');
        $request->setLocale('es');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set('_route', 'backend_plan_done');
        self::getContainer()->get('request_stack')->push($request);

        $user = (new User())->setEmail('closure@example.test');
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken($user, 'main', ['ROLE_USER'])
        );

        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('userProjects', []);
        $enabled = ['visible' => true, 'enabled' => true, 'requiredTier' => null, 'reason' => null];
        $lockedStandard = ['visible' => true, 'enabled' => false, 'requiredTier' => 'standard', 'reason' => null];
        $lockedPro = ['visible' => true, 'enabled' => false, 'requiredTier' => 'pro', 'reason' => null];

        $html = $twig->render('backend/plan/done.html.twig', [
            'project' => $project,
            'plan' => $plan,
            'elaborationTier' => 'basic',
            'elaborationTierLabel' => 'Basic',
            'closureSummary' => [
                'commitment' => [
                    'totalOfficialPoints' => 100,
                    'planned' => [
                        'points' => 62,
                        'percentageRounded' => 62,
                        'labelKey' => 'backend.plan.commitment.levels.forest.label',
                    ],
                ],
                'measures' => [
                    'official' => 50,
                    'selected' => 12,
                    'discarded' => 20,
                    'notApplicable' => 18,
                    'critical' => 3,
                    'custom' => 2,
                ],
            ],
            'commitmentSummary' => [
                'totalOfficialPoints' => 100,
                'planned' => [
                    'points' => 62,
                    'percentageRounded' => 62,
                    'labelKey' => 'backend.plan.commitment.levels.forest.label',
                    'levelKey' => 'forest',
                    'pointsToNextLevel' => null,
                ],
            ],
            'closureFeatures' => [
                'unifiedPdf' => $enabled,
                'departmentPdf' => $lockedStandard,
                'tripleBalancePdf' => $lockedPro,
                'odsPdf' => $lockedPro,
                'impactAreaPdf' => $lockedPro,
                'excel' => $lockedPro,
                'email' => $enabled,
            ],
            'elaborationUpgradeCta' => ['mode' => 'none'],
            'implementationTier' => 'basic',
            'implementationTierLabel' => 'Implementación Basic',
            'hasImplementationActivity' => false,
            'implementationUpgradeCta' => ['mode' => 'none'],
            'gamificationMessage' => null,
            'crewMembers' => [],
        ]);

        self::assertStringContainsString('62 / 100', $html);
        self::assertStringContainsString('62%', $html);
        self::assertStringContainsString('Bosque', $html);
        self::assertStringContainsString('PDF general', $html);
        self::assertStringContainsString('PDF por departamentos', $html);
        self::assertStringContainsString('PDF por triple balance', $html);
        self::assertStringContainsString('PDF por ODS', $html);
        self::assertStringContainsString('PDF por áreas de impacto', $html);
        self::assertStringContainsString('Exportación Excel', $html);
        self::assertStringContainsString('Enviar por email', $html);
        self::assertStringNotContainsString('PDF por categorías', $html);
        self::assertStringContainsString(
            'data-download-state-loading-label-value="Generando PDF…"',
            $html
        );
        self::assertStringContainsString(
            'data-download-state-loading-label-value="Generando PDF y enviando…"',
            $html
        );
        self::assertStringContainsString(
            'data-download-state-require-checked-name-value="crew_ids[]"',
            $html
        );
        self::assertStringContainsString('click->download-state#start', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringNotContainsString('/backend/plan/review?state=pending', $html);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
