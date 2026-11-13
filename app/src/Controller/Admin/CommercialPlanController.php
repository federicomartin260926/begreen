<?php

namespace App\Controller\Admin;

use App\Entity\CommercialPlan;
use App\Enum\CommercialPhase;
use App\Entity\Project;
use App\Form\CommercialTierType;
use App\Service\ProjectCommercialPricing;
use App\Repository\CommercialPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/commercial-plans', name: 'admin_commercial_plans_')]
final class CommercialPlanController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(CommercialPlanRepository $commercialPlanRepository, ProjectCommercialPricing $pricing): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $phasePlans = $commercialPlanRepository->findBy([], [
            'phase' => 'ASC',
            'sortOrder' => 'ASC',
            'id' => 'ASC',
        ]);

        $byCode = [];
        foreach ($phasePlans as $plan) {
            $byCode[$plan->getCode()][$plan->getPhase()->value] = $plan;
        }

        $tiers = [];
        foreach ([Project::COMMERCIAL_TIER_BASIC, Project::COMMERCIAL_TIER_STANDARD, Project::COMMERCIAL_TIER_PRO] as $code) {
            $elaboration = $byCode[$code][CommercialPhase::ELABORATION->value] ?? null;
            $implementation = $byCode[$code][CommercialPhase::IMPLEMENTATION->value] ?? null;
            $complete = $elaboration instanceof CommercialPlan && $implementation instanceof CommercialPlan;

            $tiers[] = [
                'code' => $code,
                'name' => ucfirst($code),
                'elaboration' => $elaboration,
                'implementation' => $implementation,
                'editId' => $complete ? $elaboration->getId() : null,
                'active' => $complete && $elaboration->isActive() && $implementation->isActive(),
                'consistentStatus' => $complete && $elaboration->isActive() === $implementation->isActive(),
                'sortOrder' => $elaboration?->getSortOrder(),
                'prices' => $pricing->getTierPricesCents($code),
            ];
        }

        return $this->render('admin/commercial_plan/index.html.twig', [
            'tiers' => $tiers,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CommercialPlan $commercialPlan,
        CommercialPlanRepository $commercialPlanRepository,
        ProjectCommercialPricing $pricing,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $code = $commercialPlan->getCode();
        if (!in_array($code, [Project::COMMERCIAL_TIER_BASIC, Project::COMMERCIAL_TIER_STANDARD, Project::COMMERCIAL_TIER_PRO], true)) {
            throw $this->createNotFoundException('Unknown commercial tier.');
        }

        $phasePlans = $commercialPlanRepository->findBy(['code' => $code]);
        $byPhase = [];
        foreach ($phasePlans as $plan) {
            $byPhase[$plan->getPhase()->value] = $plan;
        }
        $elaboration = $byPhase[CommercialPhase::ELABORATION->value] ?? null;
        $implementation = $byPhase[CommercialPhase::IMPLEMENTATION->value] ?? null;
        if (!$elaboration instanceof CommercialPlan || !$implementation instanceof CommercialPlan) {
            throw $this->createNotFoundException('A commercial tier requires both phase configurations.');
        }

        $form = $this->createForm(CommercialTierType::class, null, [
            'elaboration_plan' => $elaboration,
            'implementation_plan' => $implementation,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applySharedValues($elaboration, $implementation, $form);
            $this->applyEditableValues($elaboration, $form->get('elaboration'));
            $this->applyEditableValues($implementation, $form->get('implementation'));
            $entityManager->flush();

            $this->addFlash('success', 'backend.commercial_plans.flash.updated');

            return $this->redirectToRoute('admin_commercial_plans_index');
        }

        return $this->render('admin/commercial_plan/form.html.twig', [
            'tier' => $code,
            'form' => $form->createView(),
            'priceBands' => $pricing->getTierPricesCents($code),
            'statusMismatch' => $elaboration->isActive() !== $implementation->isActive(),
        ]);
    }

    private function applySharedValues(CommercialPlan $elaboration, CommercialPlan $implementation, FormInterface $form): void
    {
        $name = trim((string) $form->get('name')->getData());
        $active = (bool) $form->get('active')->getData();
        $sortOrder = (int) $form->get('sortOrder')->getData();

        foreach ([$elaboration, $implementation] as $plan) {
            $plan->setName($name)->setActive($active)->setSortOrder($sortOrder);
        }
    }

    private function applyEditableValues(CommercialPlan $commercialPlan, FormInterface $form): void
    {
        $allowedScores = array_map(static fn (mixed $value): int => (int) $value, (array) $form->get('allowedScores')->getData());
        sort($allowedScores);
        $allowedScores = array_values(array_unique(array_filter($allowedScores, static fn (int $score): bool => $score >= 1 && $score <= 5)));

        $commercialPlan->setAllowedScores($allowedScores);

        $pdfByDepartments = (bool) $form->get('pdfByDepartments')->getData();
        $commercialPlan->setFeature('sustainability_plan.department_pdf', $pdfByDepartments);
        $commercialPlan->setFeature('sustainability_plan.export.department_pdf', $pdfByDepartments);
        $commercialPlan->setFeature('sustainability_plan.watermark_free_pdf', $pdfByDepartments);
        $commercialPlan->setFeature('sustainability_plan.history', $pdfByDepartments);

        $advancedExports = (bool) $form->get('advancedExports')->getData();
        $commercialPlan->setFeature('sustainability_plan.advanced_exports', $advancedExports);
        $commercialPlan->setFeature('sustainability_plan.export.category', $advancedExports);
        $commercialPlan->setFeature('sustainability_plan.export.department', $advancedExports);
        $commercialPlan->setFeature('sustainability_plan.export.impact_area', $advancedExports);
        $commercialPlan->setFeature('sustainability_plan.export.triple_balance', $advancedExports);
        $commercialPlan->setFeature('sustainability_plan.export.ods', $advancedExports);
        $commercialPlan->setFeature('sustainability_plan.export.excel', $advancedExports);
        $commercialPlan->setFeature('sustainability_plan.export.email', (bool) $form->get('emailExport')->getData());

        $commercialPlan->setFeature('sustainability_plan.internal_notes', (bool) $form->get('internalNotes')->getData());
        $commercialPlan->setFeature('sustainability_plan.responsibles', (bool) $form->get('responsibles')->getData());
        $commercialPlan->setFeature('sustainability_plan.checklist', (bool) $form->get('checklist')->getData());
        $commercialPlan->setFeature('sustainability_plan.custom_measures', (bool) $form->get('customMeasures')->getData());
        $commercialPlan->setFeature('sustainability_plan.validation_summary', (bool) $form->get('validationSummary')->getData());
        $commercialPlan->setFeature('sustainability_plan.branding', (bool) $form->get('branding')->getData());
    }
}
