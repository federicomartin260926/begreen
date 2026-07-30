<?php

namespace App\Controller\Admin;

use App\Entity\CommercialPlan;
use App\Form\CommercialPlanType;
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
    public function index(CommercialPlanRepository $commercialPlanRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render('admin/commercial_plan/index.html.twig', [
            'plans' => $commercialPlanRepository->findBy([], [
                'phase' => 'ASC',
                'sortOrder' => 'ASC',
                'id' => 'ASC',
            ]),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, CommercialPlan $commercialPlan, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $form = $this->createForm(CommercialPlanType::class, $commercialPlan, [
            'show_stripe_upgrade_from_standard_price_id' => strtolower(trim((string) $commercialPlan->getCode())) === 'pro',
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyEditableValues($commercialPlan, $form);
            $entityManager->flush();

            $this->addFlash('success', 'backend.commercial_plans.flash.updated');

            return $this->redirectToRoute('admin_commercial_plans_index');
        }

        return $this->render('admin/commercial_plan/form.html.twig', [
            'plan' => $commercialPlan,
            'form' => $form->createView(),
        ]);
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
