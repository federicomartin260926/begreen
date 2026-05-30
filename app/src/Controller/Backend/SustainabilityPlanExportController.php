<?php

namespace App\Controller\Backend;

use App\Entity\Plan;
use App\Entity\Project;
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
use Symfony\Component\HttpFoundation\Request;
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

        if (!$this->isExportAllowed($project, $grouping, 'pdf')) {
            $this->addFlash('info', $this->translator->trans($this->blockedMessageKey($grouping, 'pdf')));
            return $this->redirectToRoute('backend_plan_review');
        }

        $groups = $this->groupingService->groupPlanMeasures($plan, $project, $grouping);
        $pdf = $this->pdfExporter->generate('backend/plan/export/grouped_pdf.html.twig', [
            'project' => $project,
            'plan' => $plan,
            'grouping' => $grouping,
            'groupingLabel' => $this->groupingService->getGroupingLabel($grouping),
            'groups' => $groups,
            'projectTier' => $this->featureGate->getTier($project),
            'projectTierLabel' => $this->featureGate->getPlanLabel($project),
            'generatedAt' => new \DateTimeImmutable(),
            'hasWatermark' => $this->featureGate->hasWatermark($project),
            'commitmentSummary' => $this->commitmentLevelService->buildSummary($plan, $project),
        ]);

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

        if (!$this->isExportAllowed($project, $grouping, 'excel')) {
            $this->addFlash('info', $this->translator->trans($this->blockedMessageKey($grouping, 'excel')));
            return $this->redirectToRoute('backend_plan_review');
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

    private function isExportAllowed(Project $project, string $grouping, string $format): bool
    {
        if (!in_array($grouping, self::VALID_GROUPINGS, true)) {
            return false;
        }

        return match ($format) {
            'pdf' => $this->isPdfAllowed($project, $grouping),
            'excel' => $this->isExcelAllowed($project, $grouping),
            default => false,
        };
    }

    private function isPdfAllowed(Project $project, string $grouping): bool
    {
        return match ($grouping) {
            'department' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.department_pdf')
                || $this->featureGate->canUseFeature($project, 'sustainability_plan.export.department'),
            'category' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.category'),
            'impact_area' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.impact_area'),
            'triple_balance' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.triple_balance'),
            'ods' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.ods'),
            default => false,
        };
    }

    private function isExcelAllowed(Project $project, string $grouping): bool
    {
        if (!$this->featureGate->canUseFeature($project, 'sustainability_plan.export.excel')) {
            return false;
        }

        return match ($grouping) {
            'department' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.department'),
            'category' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.category'),
            'impact_area' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.impact_area'),
            'triple_balance' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.triple_balance'),
            'ods' => $this->featureGate->canUseFeature($project, 'sustainability_plan.export.ods'),
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
