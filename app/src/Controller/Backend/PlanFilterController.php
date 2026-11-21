<?php

namespace App\Controller\Backend;

use App\Entity\Plan;
use App\Repository\MeasureRepository;
use App\Repository\PlanRepository;
use App\Security\PlanVoter;
use App\Service\ActiveProjectService;
use App\Service\MeasureApplicabilityRuleCatalog;
use App\Service\PlanFilterApplicabilityService;
use App\Service\SustainabilityPlanCompletionService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/plan/filters', name: 'backend_plan_filters')]
#[IsGranted('ROLE_USER')]
final class PlanFilterController extends AbstractController
{
    public function __invoke(
        Request $request,
        ActiveProjectService $activeProjectService,
        PlanRepository $planRepository,
        MeasureRepository $measureRepository,
        MeasureApplicabilityRuleCatalog $ruleCatalog,
        PlanFilterApplicabilityService $applicabilityService,
        SustainabilityPlanCompletionService $completionService,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $activeProjectService->getActiveProject();
        $plan = $project ? $planRepository->findOneBy(['project' => $project]) : null;
        if (!$project || !$plan instanceof Plan || $plan->getProtocol() === null) {
            $this->addFlash('warning', 'backend.plan.filters.no_plan');

            return $this->redirectToRoute('backend_plan_welcome');
        }

        $this->denyAccessUnlessGranted(PlanVoter::EDIT, $plan);
        $filters = $ruleCatalog->filtersForProtocol($plan->getProtocol()->getCode());
        if ($filters === []) {
            $this->addFlash('warning', 'backend.plan.filters.unavailable');

            return $this->redirectToRoute('backend_plan_measures');
        }

        $answers = $applicabilityService->initialAnswers($plan, $project);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('plan_filters_'.$plan->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $answers = $applicabilityService->normalizeAnswers($plan, (array) $request->request->all('filters'));
            } catch (InvalidArgumentException $exception) {
                // Conservar las respuestas introducidas; no perderlas si queda un filtro pendiente.
                $submitted = (array) $request->request->all('filters');
                $answers = [];
                foreach ($filters as $filter) {
                    $code = (string) ($filter['code'] ?? '');
                    if (!array_key_exists($code, $submitted)) {
                        continue;
                    }
                    $raw = $submitted[$code];
                    $type = (string) ($filter['type'] ?? 'boolean');
                    if ($type === 'boolean') {
                        if ($raw === '0' || $raw === '1') {
                            $answers[$code] = $raw === '1';
                        }
                    } elseif ($type === 'multiple') {
                        if (is_array($raw)) {
                            $answers[$code] = array_values(array_filter($raw, 'is_string'));
                        }
                    } elseif (is_string($raw)) {
                        $answers[$code] = $raw;
                    }
                }

                return $this->render('backend/plan/filters.html.twig', [
                    'project' => $project,
                    'plan' => $plan,
                    'filters' => $filters,
                    'answers' => $answers,
                    'validationError' => $exception->getMessage(),
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $previousAnswers = $plan->getFilterAnswers();
            $changedCodes = [];
            foreach ($answers as $code => $answer) {
                if (!array_key_exists($code, $previousAnswers) || $previousAnswers[$code] !== $answer) {
                    $changedCodes[] = $code;
                }
            }

            $plan->setFilterAnswers($answers);
            $measures = $completionService->getVisibleMeasures($plan, $project, $measureRepository);
            $applicabilityService->recalculate($plan, $measures, $changedCodes);
            $completionService->syncStatus($plan, $project, $measureRepository);
            $entityManager->flush();

            $this->addFlash('success', 'backend.plan.filters.saved');

            return $this->redirectToRoute('backend_plan_measures');
        }

        return $this->render('backend/plan/filters.html.twig', compact('project', 'plan', 'filters', 'answers'));
    }
}
