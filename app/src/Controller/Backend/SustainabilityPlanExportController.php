<?php

namespace App\Controller\Backend;

use App\Entity\Plan;
use App\Entity\Project;
use App\Enum\CommercialPhase;
use App\Security\PlanVoter;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\SustainabilityPlanExcelExporter;
use App\Service\SustainabilityPlanGroupedPdfExporter;
use App\Service\SustainabilityPlanGroupingService;
use App\Service\SustainabilityCommitmentLevelService;
use App\Service\ProjectFeatureGate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/backend/plan', name: 'backend_plan_')]
#[\Symfony\Component\Security\Http\Attribute\IsGranted('ROLE_USER')]
final class SustainabilityPlanExportController extends AbstractController
{
    private const VALID_GROUPINGS = ['category', 'department', 'impact_area', 'triple_balance', 'ods'];
    private const CLOSURE_GROUPINGS = ['department', 'impact_area', 'triple_balance', 'ods'];

    public function __construct(
        private readonly ProjectFeatureGate $featureGate,
        private readonly SustainabilityPlanGroupingService $groupingService,
        private readonly SustainabilityCommitmentLevelService $commitmentLevelService,
        private readonly SustainabilityPlanGroupedPdfExporter $pdfExporter,
        private readonly SustainabilityPlanExcelExporter $excelExporter,
        private readonly TranslatorInterface $translator
    ) {
    }

    #[Route('/{id}/export/{grouping}/pdf', name: 'export_pdf', methods: ['GET'])]
    public function downloadPdf(
        Plan $plan,
        string $grouping,
        ActiveProjectService $activeProjectService
    ): Response {
        return $this->downloadPdfForPhase(
            $plan,
            $grouping,
            $activeProjectService,
            CommercialPhase::IMPLEMENTATION,
            false
        );
    }

    #[Route('/{id}/closure/export/{grouping}/pdf', name: 'closure_export_pdf', methods: ['GET'])]
    public function downloadClosurePdf(
        Plan $plan,
        string $grouping,
        ActiveProjectService $activeProjectService
    ): Response {
        return $this->downloadPdfForPhase(
            $plan,
            $grouping,
            $activeProjectService,
            CommercialPhase::ELABORATION,
            true
        );
    }

    private function downloadPdfForPhase(
        Plan $plan,
        string $grouping,
        ActiveProjectService $activeProjectService,
        CommercialPhase $phase,
        bool $closure
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        if ($plan->getProject()?->getId() !== $project->getId()) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        if ($plan->getStatus() !== 'completo') {
            $this->addFlash('info', 'backend.plan.errors.not_complete');
            return $this->redirectToRoute('backend_plan_measures');
        }

        if ($closure && $plan->getCustomMeasuresCompletedAt() === null) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        if (!$this->isExportAllowed($project, $grouping, 'pdf', $phase, $closure)) {
            $this->addFlash('info', $this->translator->trans($this->blockedMessageKey($grouping, 'pdf')));
            return $closure
                ? $this->redirectToRoute('backend_plan_done')
                : $this->redirectToRoute('backend_plan_review', [
                'is_applicable' => '1',
                'will_implement' => '1',
                'state' => 'all',
            ]);
        }

        $groups = $this->groupingService->groupPlanMeasures(
            $plan,
            $project,
            $grouping
        );

        $groupingLabel = $this->groupingService->getGroupingLabel(
            $grouping
        );
        $visualMetrics = $this->buildGroupedVisualMetrics($groups);
        $commitmentSummary = $this->commitmentLevelService->buildSummary(
            $plan,
            $project
        );

        $pdf = $this->pdfExporter->generate(
            'backend/plan/export/grouped_pdf_visual.html.twig',
            [
                'project' => $project,
                'plan' => $plan,
                'grouping' => $grouping,
                'groupingLabel' => $groupingLabel,
                'groupingSummaryTitle' => $this->translator->trans(
                    'pdf_grouped_visual.summary_title',
                    [
                        '%grouping%' => mb_strtolower(
                            $groupingLabel
                        ),
                    ]
                ),
                'groups' => $groups,
                'pdfGroupSummary' => $this->buildGroupedSummary(
                    $groups,
                    $this->translator->trans(
                        'pdf_grouped_visual.other'
                    )
                ),
                'groupedDetailPages' => $this->buildGroupedDetailPages(
                    $groups
                ),
                'projectTier' => $this->featureGate->getTier(
                    $project,
                    $phase
                ),
                'projectTierLabel' => $this->featureGate->getPlanLabel(
                    $project,
                    $phase
                ),
                'generatedAt' => new \DateTimeImmutable(
                    'now',
                    new \DateTimeZone('Europe/Madrid')
                ),
                'hasWatermark' => $this->featureGate->hasWatermark(
                    $project,
                    $phase
                ),
                'commitmentSummary' => $commitmentSummary,
                'currentUserLabel' => $this->buildCurrentUserLabel(),
                'scoreMax' => $visualMetrics['scoreMax'],
                'scoreGained' => $visualMetrics['scoreGained'],
                'scorePct' => $visualMetrics['scorePct'],
                'coverIndicators' => $visualMetrics[
                    'coverIndicators'
                ],
                'pdfQuickRead' => $visualMetrics['quickRead'],
                'pdfVisualAssets' => [
                    'logo' => $this->pdfAssetDataUri(
                        'assets/images/logo-white.svg',
                        'image/svg+xml'
                    ),
                    'vegetation' => $this->pdfAssetDataUri(
                        'public/images/commitment/'
                        . 'commitment-selva-v4.png',
                        'image/png'
                    ),
                ],
            ]
        );

        $filename = $this->buildFilename($project, $grouping, 'pdf');

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . $filename . '"'
        );

        return $response;
    }

    #[Route('/{id}/export/{grouping}/excel', name: 'export_excel', methods: ['GET'])]
    public function downloadExcel(
        Plan $plan,
        string $grouping,
        ActiveProjectService $activeProjectService
    ): Response {
        return $this->downloadExcelForPhase(
            $plan,
            $grouping,
            $activeProjectService,
            CommercialPhase::IMPLEMENTATION,
            false
        );
    }

    #[Route('/{id}/closure/export/{grouping}/excel', name: 'closure_export_excel', methods: ['GET'])]
    public function downloadClosureExcel(
        Plan $plan,
        string $grouping,
        ActiveProjectService $activeProjectService
    ): Response {
        return $this->downloadExcelForPhase(
            $plan,
            $grouping,
            $activeProjectService,
            CommercialPhase::ELABORATION,
            true
        );
    }

    private function downloadExcelForPhase(
        Plan $plan,
        string $grouping,
        ActiveProjectService $activeProjectService,
        CommercialPhase $phase,
        bool $closure
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        if ($plan->getProject()?->getId() !== $project->getId()) {
            $this->addFlash('warning', 'backend.projects.flash.no_active');
            return $this->redirectToRoute('app_backend');
        }

        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->denyAccessUnlessGranted(PlanVoter::VIEW, $plan);

        if ($plan->getStatus() !== 'completo') {
            $this->addFlash('info', 'backend.plan.errors.not_complete');
            return $this->redirectToRoute('backend_plan_measures');
        }

        if ($closure && $plan->getCustomMeasuresCompletedAt() === null) {
            return $this->redirectToRoute('backend_plan_measures');
        }

        if (!$this->isExportAllowed($project, $grouping, 'excel', $phase, $closure)) {
            $this->addFlash('info', $this->translator->trans($this->blockedMessageKey($grouping, 'excel')));
            return $closure
                ? $this->redirectToRoute('backend_plan_done')
                : $this->redirectToRoute('backend_plan_review', [
                'is_applicable' => '1',
                'will_implement' => '1',
                'state' => 'all',
            ]);
        }

        $groups = $this->groupingService->groupPlanMeasures($plan, $project, $grouping);
        $spreadsheet = $this->excelExporter->buildSpreadsheet($plan, $project, $grouping, $groups);
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = (string) ob_get_clean();

        $filename = $this->buildFilename($project, $grouping, 'xlsx');
        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="' . $filename . '"'
        );

        return $response;
    }


    /**
     * @param array<int, array{
     *     label:string,
     *     rows:array<int, array<string, mixed>>
     * }> $groups
     * @return array<string, mixed>
     */
    private function buildGroupedVisualMetrics(array $groups): array
    {
        $rowsByMeasure = [];

        foreach ($groups as $group) {
            foreach ($group['rows'] ?? [] as $row) {
                $measureId = $row['measureId'] ?? null;
                if ($measureId === null) {
                    continue;
                }

                $rowsByMeasure[(string) $measureId] = $row;
            }
        }

        $total = count($rowsByMeasure);
        $applicable = 0;
        $selected = 0;
        $critical = 0;
        $criticalApplicable = 0;
        $criticalSelected = 0;
        $scoreMax = 0;
        $scoreGained = 0;

        foreach ($rowsByMeasure as $row) {
            $score = max(0, (int) ($row['score'] ?? 0));
            $isApplicable = ($row['applicable'] ?? null) === true;
            $isSelected = ($row['selected'] ?? null) === true;
            $isCritical = ($row['critical'] ?? false) === true;

            $scoreMax += $score;

            if ($isApplicable) {
                $applicable++;
            }

            if ($isSelected) {
                $selected++;
                $scoreGained += $score;
            }

            if ($isCritical) {
                $critical++;
            }

            if ($isApplicable && $isCritical) {
                $criticalApplicable++;
            }

            if ($isSelected && $isCritical) {
                $criticalSelected++;
            }
        }

        return [
            'scoreMax' => $scoreMax,
            'scoreGained' => $scoreGained,
            'scorePct' => $scoreMax > 0
                ? (int) round(100 * $scoreGained / $scoreMax)
                : null,
            'coverIndicators' => [
                'total' => $total,
                'applicable' => $applicable,
                'toImplement' => $selected,
                'critical' => $critical,
            ],
            'quickRead' => [
                [
                    'key' => 'applicability',
                    'value' => $applicable,
                    'total' => $total,
                    'percentage' => $this->percentage(
                        $applicable,
                        $total
                    ),
                ],
                [
                    'key' => 'selection',
                    'value' => $selected,
                    'total' => $applicable,
                    'percentage' => $this->percentage(
                        $selected,
                        $applicable
                    ),
                ],
                [
                    'key' => 'critical_coverage',
                    'value' => $criticalSelected,
                    'total' => $criticalApplicable,
                    'percentage' => $this->percentage(
                        $criticalSelected,
                        $criticalApplicable
                    ),
                ],
            ],
        ];
    }

    /**
     * @param array<int, array{
     *     label:string,
     *     rows:array<int, array<string, mixed>>
     * }> $groups
     * @return list<array<string, int|string>>
     */
    private function buildGroupedSummary(
        array $groups,
        string $otherLabel
    ): array {
        $summary = [];

        foreach ($groups as $group) {
            $row = [
                'name' => (string) ($group['label'] ?? ''),
                'total' => 0,
                'applicable' => 0,
                'selected' => 0,
                'critical' => 0,
            ];

            foreach ($group['rows'] ?? [] as $measureRow) {
                if (($measureRow['measureId'] ?? null) === null) {
                    continue;
                }

                $row['total']++;

                if (($measureRow['applicable'] ?? null) === true) {
                    $row['applicable']++;
                }

                if (($measureRow['selected'] ?? null) === true) {
                    $row['selected']++;
                }

                if (
                    ($measureRow['selected'] ?? null) === true
                    && ($measureRow['critical'] ?? false) === true
                ) {
                    $row['critical']++;
                }
            }

            if ($row['total'] > 0) {
                $summary[] = $row;
            }
        }

        usort(
            $summary,
            static function (array $left, array $right): int {
                foreach (
                    ['selected', 'critical', 'total']
                    as $key
                ) {
                    $comparison = $right[$key] <=> $left[$key];

                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return strnatcasecmp(
                    (string) $left['name'],
                    (string) $right['name']
                );
            }
        );

        if (count($summary) <= 6) {
            return $summary;
        }

        $visible = array_slice($summary, 0, 5);
        $other = [
            'name' => $otherLabel,
            'total' => 0,
            'applicable' => 0,
            'selected' => 0,
            'critical' => 0,
        ];

        foreach (array_slice($summary, 5) as $row) {
            foreach (
                ['total', 'applicable', 'selected', 'critical']
                as $key
            ) {
                $other[$key] += $row[$key];
            }
        }

        $visible[] = $other;

        return $visible;
    }

    /**
     * @param array<int, array{
     *     label:string,
     *     rows:array<int, array<string, mixed>>
     * }> $groups
     * @return list<array<string, mixed>>
     */
    private function buildGroupedDetailPages(array $groups): array
    {
        $pages = [];

        foreach ($groups as $group) {
            $rows = array_values($group['rows'] ?? []);
            $groupTotal = count($rows);
            $currentRows = [];
            $currentEstimatedHeight = 0.0;

            foreach ($rows as $row) {
                $observations = trim(
                    (string) ($row['observations'] ?? '')
                );
                $title = trim(
                    (string) (
                        $row['measureTitle']
                        ?? $row['displayName']
                        ?? ''
                    )
                );

                $titleLines = max(
                    1,
                    (int) ceil(max(1, mb_strlen($title)) / 95)
                );
                $observationLines = $observations === ''
                    ? 0
                    : max(
                        1,
                        (int) ceil(mb_strlen($observations) / 140)
                    );

                $estimatedHeight = 9.0
                    + ($titleLines * 3.8)
                    + ($observationLines * 3.2);

                if (
                    $currentRows !== []
                    && $currentEstimatedHeight + $estimatedHeight > 236.0
                ) {
                    $pages[] = [
                        'groupLabel' => (string) (
                            $group['label'] ?? ''
                        ),
                        'groupTotal' => $groupTotal,
                        'rows' => $currentRows,
                    ];

                    $currentRows = [];
                    $currentEstimatedHeight = 0.0;
                }

                $currentRows[] = $row;
                $currentEstimatedHeight += $estimatedHeight;
            }

            if ($currentRows !== []) {
                $pages[] = [
                    'groupLabel' => (string) (
                        $group['label'] ?? ''
                    ),
                    'groupTotal' => $groupTotal,
                    'rows' => $currentRows,
                ];
            }
        }

        return $pages;
    }

    private function percentage(int $value, int $total): int
    {
        return $total > 0
            ? (int) round(100 * $value / $total)
            : 0;
    }

    private function buildCurrentUserLabel(): string
    {
        $user = $this->getUser();

        if (!is_object($user)) {
            return '';
        }

        $firstName = '';

        foreach (['getName', 'getFirstName'] as $method) {
            if (!method_exists($user, $method)) {
                continue;
            }

            $firstName = trim((string) $user->{$method}());

            if ($firstName !== '') {
                break;
            }
        }

        $lastName = '';

        foreach (['getSurnames', 'getLastName'] as $method) {
            if (!method_exists($user, $method)) {
                continue;
            }

            $lastName = trim((string) $user->{$method}());

            if ($lastName !== '') {
                break;
            }
        }

        $fullName = trim($firstName . ' ' . $lastName);

        if ($fullName !== '') {
            return $fullName;
        }

        return method_exists($user, 'getUserIdentifier')
            ? (string) $user->getUserIdentifier()
            : '';
    }

    private function pdfAssetDataUri(
        string $relativePath,
        string $mimeType
    ): string {
        $path = $this->getParameter('kernel.project_dir')
            . '/'
            . ltrim($relativePath, '/');

        $contents = is_file($path)
            ? file_get_contents($path)
            : false;

        return $contents === false
            ? ''
            : sprintf(
                'data:%s;base64,%s',
                $mimeType,
                base64_encode($contents)
            );
    }

    private function isExportAllowed(
        Project $project,
        string $grouping,
        string $format,
        CommercialPhase $phase = CommercialPhase::IMPLEMENTATION,
        bool $closure = false
    ): bool
    {
        if (!in_array($grouping, self::VALID_GROUPINGS, true)) {
            return false;
        }

        if ($closure && !in_array($grouping, self::CLOSURE_GROUPINGS, true)) {
            return false;
        }

        return match ($format) {
            'pdf' => $this->isPdfAllowed($project, $grouping, $phase),
            'excel' => $this->isExcelAllowed($project, $grouping, $phase),
            default => false,
        };
    }

    private function isPdfAllowed(Project $project, string $grouping, CommercialPhase $phase): bool
    {
        return match ($grouping) {
            'department' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.department_pdf')
                || $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.department_pdf')
                || $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.department'),
            'category' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.category'),
            'impact_area' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.impact_area'),
            'triple_balance' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.triple_balance'),
            'ods' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.ods'),
            default => false,
        };
    }

    private function isExcelAllowed(Project $project, string $grouping, CommercialPhase $phase): bool
    {
        if (!$this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.excel')) {
            return false;
        }

        return match ($grouping) {
            'department' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.department'),
            'category' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.category'),
            'impact_area' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.impact_area'),
            'triple_balance' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.triple_balance'),
            'ods' => $this->featureGate->canUseFeature($project, $phase, 'sustainability_plan.export.ods'),
            default => false,
        };
    }

    private function blockedMessageKey(string $grouping, string $format): string
    {
        return match ([$grouping, $format]) {
            ['department', 'pdf'] => 'backend.plan.exports.available_in_standard',
            default => 'backend.plan.exports.available_in_pro',
        };
    }

    private function buildFilename(Project $project, string $grouping, string $extension): string
    {
        $slugger = new AsciiSlugger();
        $projectSlug = $slugger->slug((string) $project->getName())->lower();
        $groupingSlug = $slugger->slug($this->groupingService->getGroupingLabel($grouping))->lower();

        return sprintf('plan_sostenibilidad_%s_%s.%s', $projectSlug, $groupingSlug, $extension);
    }

}
