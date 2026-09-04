<?php

namespace App\Controller\Backend;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Repository\CategoryRepository;
use App\Repository\ProjectRepository;
use App\Security\EmissionRecordVoter;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\Emission\Transport\TransportEmissionRecordService;
use App\Service\Emission\Transport\TransportEmissionRequestMapper;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/emission')]
#[IsGranted('ROLE_USER')]
final class TransportEmissionController extends AbstractController
{
    #[Route('/new-transport', name: 'backend_emission_new_transport_v20', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        TransportEmissionRequestMapper $requestMapper,
        TransportEmissionRecordService $recordService,
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException('No active project.');
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $category = $this->transportCategory($categoryRepository);
        if ($request->isMethod('GET')) {
            return new JsonResponse(['version' => TransportEmissionSnapshot::VERSION]);
        }

        try {
            $input = $requestMapper->map($request);
            $phase = $projectRepository->findPhaseByDate($project, \DateTimeImmutable::createFromInterface($input->startedAt));
            if (!$phase) {
                return new JsonResponse(['status' => 'phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $writeResult = $recordService->write($project, $category, $phase, $input, $this->notes($request));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['status' => 'invalid_input', 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$writeResult->isPersisted()) {
            return new JsonResponse(['status' => $writeResult->calculation->status], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'status' => $writeResult->calculation->status,
            'id' => $writeResult->record?->getId(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/edit-transport', name: 'backend_emission_edit_transport_v20', methods: ['GET', 'POST'])]
    public function edit(
        EmissionRecord $record,
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        TransportEmissionRequestMapper $requestMapper,
        TransportEmissionRecordService $recordService,
        TransportEmissionSnapshot $snapshot,
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException('Invalid project or record ownership.');
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::EDIT, $record);

        $category = $this->transportCategory($categoryRepository);
        if (!$snapshot->isTransportV20Record($record, (int) $category->getId())) {
            throw $this->createNotFoundException('Transport v20 record not found.');
        }

        try {
            $storedInput = $snapshot->decode((string) $record->getCalculationDetails());
        } catch (\JsonException|\UnexpectedValueException) {
            throw $this->createNotFoundException('Invalid transport v20 snapshot.');
        }

        if ($request->isMethod('GET')) {
            return new JsonResponse([
                'version' => TransportEmissionSnapshot::VERSION,
                'input' => $snapshot->inputToArray($storedInput),
            ]);
        }

        try {
            $input = $requestMapper->map($request);
            $phase = $projectRepository->findPhaseByDate($project, \DateTimeImmutable::createFromInterface($input->startedAt));
            if (!$phase) {
                return new JsonResponse(['status' => 'phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $writeResult = $recordService->write($project, $category, $phase, $input, $this->notes($request), $record);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['status' => 'invalid_input', 'error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$writeResult->isPersisted()) {
            return new JsonResponse(['status' => $writeResult->calculation->status], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['status' => $writeResult->calculation->status, 'id' => $record->getId()]);
    }

    private function transportCategory(CategoryRepository $repository): Category
    {
        $category = $repository->findOneBy(['name' => 'Transporte']);
        if (!$category || !$category->isEnabledInEmissionCalculator()) {
            throw $this->createNotFoundException('Transport category not found.');
        }

        return $category;
    }

    private function notes(Request $request): ?string
    {
        $notes = $request->request->get('notes');

        return is_string($notes) && '' !== $notes ? $notes : null;
    }
}
