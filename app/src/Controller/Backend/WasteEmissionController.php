<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Repository\CategoryRepository;
use App\Repository\ProjectRepository;
use App\Security\EmissionRecordVoter;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\Emission\EmissionRecordAttachmentStorage;
use App\Service\Emission\EmissionCountryCatalog;
use App\Service\Emission\EmissionRecordAttachmentValidationException;
use App\Service\Emission\Waste\WasteEmissionCalculator;
use App\Service\Emission\Waste\WasteEmissionRecordService;
use App\Service\Emission\Waste\WasteEmissionRequestMapper;
use App\Service\Emission\Waste\WasteEmissionSnapshot;
use App\Service\Emission\Waste\WasteUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/emission')]
#[IsGranted('ROLE_USER')]
final class WasteEmissionController extends AbstractController
{
    private readonly EmissionCountryCatalog $countryCatalog;

    public function __construct(?EmissionCountryCatalog $countryCatalog = null)
    {
        $this->countryCatalog = $countryCatalog ?? new EmissionCountryCatalog();
    }

    private const FORM_FIELDS = [
        'startDate', 'endDate', 'country', 'wasteType', 'wasteActivity',
        'treatment', 'weight', 'weightUnit', 'notes',
    ];

    #[Route('/waste/preview', name: 'backend_emission_waste_v1_preview', methods: ['POST'])]
    public function preview(
        Request $request,
        ActiveProjectService $activeProjectService,
        WasteEmissionRequestMapper $requestMapper,
        WasteEmissionCalculator $calculator,
    ): JsonResponse {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $token = $request->request->get('_preview_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('waste_emission_v1_preview', $token)) {
            return $this->json(['error' => 'csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json($calculator->calculate($requestMapper->map($request))->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->json(
                ['error' => 'invalid_input', 'message' => $this->inputErrorKey($e)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    #[Route('/new-waste-v1', name: 'backend_emission_new_waste_v1', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        WasteEmissionRequestMapper $requestMapper,
        WasteEmissionRecordService $recordService,
        WasteUiCatalog $catalog,
        EmissionRecordAttachmentStorage $attachmentStorage,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $category = $this->category($categoryRepository);
        $values = $this->formValues($request, $this->emptyValues());

        if ($request->isMethod('GET')) {
            return $this->renderForm($request, $project, $category, $values, $catalog, false, null, []);
        }
        if (!$this->hasValidCsrfToken($request, 'waste_emission_v1_create')) {
            return $this->renderForm($request, $project, $category, $values, $catalog, false, null, ['csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attachments = $this->uploadedAttachments($request);
        try {
            $attachmentStorage->validateUploads($attachments);
            $input = $requestMapper->map($request);
            $phase = $projectRepository->findPhaseByDate(
                $project,
                \DateTimeImmutable::createFromInterface($input->startDate),
            );
            if (!$phase) {
                return $this->renderForm($request, $project, $category, $values, $catalog, false, null, ['phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $writeResult = $recordService->write(
                $project,
                $category,
                $phase,
                $input,
                $this->notes($request),
            );
        } catch (EmissionRecordAttachmentValidationException $e) {
            return $this->renderForm($request, $project, $category, $values, $catalog, false, null, [$e->errorKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            return $this->renderForm($request, $project, $category, $values, $catalog, false, null, [$this->inputErrorKey($e)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->storeAttachments($writeResult->record, $attachments, $attachmentStorage, $entityManager);
        } catch (\Throwable) {
            return $this->renderForm($request, $project, $category, $values, $catalog, true, $writeResult->record, ['attachment_storage_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->addFlash('success', 'backend.emission.waste_v1.flash.created');

        return $this->redirectToRoute(
            'backend_emission_index',
            $this->indexQuery($request, (int) $category->getId()),
        );
    }

    #[Route('/{id}/edit-waste-v1', name: 'backend_emission_edit_waste_v1', methods: ['GET', 'POST'])]
    public function edit(
        EmissionRecord $record,
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        WasteEmissionRequestMapper $requestMapper,
        WasteEmissionRecordService $recordService,
        WasteEmissionSnapshot $snapshot,
        WasteUiCatalog $catalog,
        EmissionRecordAttachmentStorage $attachmentStorage,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->ownedProject($record, $activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::EDIT, $record);
        $category = $this->category($categoryRepository);
        if (!$snapshot->isWasteV1Record($record, (int) $category->getId())) {
            throw $this->createNotFoundException('Waste v1 record not found.');
        }

        try {
            $storedValues = $this->snapshotValues($snapshot, $record);
        } catch (\JsonException|\UnexpectedValueException) {
            throw $this->createNotFoundException('Invalid waste v1 snapshot.');
        }
        $values = $this->formValues($request, $storedValues);

        if ($request->isMethod('GET')) {
            return $this->renderForm($request, $project, $category, $values, $catalog, true, $record, []);
        }
        if (!$this->hasValidCsrfToken($request, 'waste_emission_v1_edit_'.$record->getId())) {
            return $this->renderForm($request, $project, $category, $values, $catalog, true, $record, ['csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attachments = $this->uploadedAttachments($request);
        try {
            $attachmentStorage->validateUploads($attachments);
            $input = $requestMapper->map($request);
            $phase = $projectRepository->findPhaseByDate(
                $project,
                \DateTimeImmutable::createFromInterface($input->startDate),
            );
            if (!$phase) {
                return $this->renderForm($request, $project, $category, $values, $catalog, true, $record, ['phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $recordService->write(
                $project,
                $category,
                $phase,
                $input,
                $this->notes($request),
                $record,
            );
        } catch (EmissionRecordAttachmentValidationException $e) {
            return $this->renderForm($request, $project, $category, $values, $catalog, true, $record, [$e->errorKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $e) {
            return $this->renderForm($request, $project, $category, $values, $catalog, true, $record, [$this->inputErrorKey($e)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->storeAttachments($record, $attachments, $attachmentStorage, $entityManager);
        } catch (\Throwable) {
            return $this->renderForm($request, $project, $category, $values, $catalog, true, $record, ['attachment_storage_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->addFlash('success', 'backend.emission.waste_v1.flash.updated');

        return $this->redirectToRoute(
            'backend_emission_index',
            $this->indexQuery($request, (int) $category->getId()),
        );
    }

    #[Route('/{id}/duplicate-waste-v1', name: 'backend_emission_duplicate_waste_v1', methods: ['GET'])]
    public function duplicate(
        EmissionRecord $record,
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        WasteEmissionSnapshot $snapshot,
        WasteUiCatalog $catalog,
    ): Response {
        $project = $this->ownedProject($record, $activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::VIEW, $record);
        $category = $this->category($categoryRepository);
        if (!$snapshot->isWasteV1Record($record, (int) $category->getId())) {
            throw $this->createNotFoundException('Waste v1 record not found.');
        }

        try {
            $values = $this->snapshotValues($snapshot, $record);
        } catch (\JsonException|\UnexpectedValueException) {
            throw $this->createNotFoundException('Invalid waste v1 snapshot.');
        }

        return $this->renderForm(
            $request,
            $project,
            $category,
            $values,
            $catalog,
            false,
            null,
            [],
            duplicate: true,
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $errors
     */
    private function renderForm(
        Request $request,
        Project $project,
        Category $category,
        array $values,
        WasteUiCatalog $catalog,
        bool $edit,
        ?EmissionRecord $record,
        array $errors,
        int $status = Response::HTTP_OK,
        bool $duplicate = false,
    ): Response {
        $backQuery = $this->indexQuery($request, (int) $category->getId());
        $formAction = $edit && null !== $record
            ? $this->generateUrl(
                'backend_emission_edit_waste_v1',
                array_merge(['id' => $record->getId()], $backQuery),
            )
            : $this->generateUrl('backend_emission_new_waste_v1', $backQuery);

        return $this->render('backend/emission/waste_v1_form.html.twig', [
            'project' => $project,
            'category' => $category,
            'edit' => $edit,
            'duplicate' => $duplicate,
            'record' => $record,
            'values' => $values,
            'formAction' => $formAction,
            'countries' => $this->countryCatalog->choices($request->getLocale()),
            'wasteCatalog' => $catalog->frontendCatalog(),
            'csrfTokenId' => $edit
                ? 'waste_emission_v1_edit_'.$record?->getId()
                : 'waste_emission_v1_create',
            'errors' => $errors,
            'backQuery' => $backQuery,
        ], new Response(status: $status));
    }

    /** @return array<string, mixed> */
    private function emptyValues(): array
    {
        return array_fill_keys(self::FORM_FIELDS, null) + ['weightUnit' => 'kg'];
    }

    /** @param array<string, mixed> $fallback
     *  @return array<string, mixed>
     */
    private function formValues(Request $request, array $fallback): array
    {
        if (!$request->isMethod('POST')) {
            return $fallback;
        }

        $data = $request->request->all();
        foreach (self::FORM_FIELDS as $field) {
            $value = $data[$field] ?? null;
            $fallback[$field] = is_string($value) ? $value : null;
        }

        return $fallback;
    }

    /** @return array<string, mixed> */
    private function snapshotValues(
        WasteEmissionSnapshot $snapshot,
        EmissionRecord $record,
    ): array {
        $values = $snapshot->inputToArray(
            $snapshot->decodeInput((string) $record->getCalculationDetails()),
        );
        $values['notes'] = $record->getNotes();

        return $values;
    }

    private function inputErrorKey(\InvalidArgumentException $exception): string
    {
        return match ($exception->getMessage()) {
            'endDate cannot be before startDate.' => 'invalid_date_range',
            'Waste records must be split by year.' => 'activity_crosses_year',
            default => 'invalid_input',
        };
    }

    private function activeProject(ActiveProjectService $service): Project
    {
        $project = $service->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException('No active project.');
        }

        return $project;
    }

    private function ownedProject(
        EmissionRecord $record,
        ActiveProjectService $service,
    ): Project {
        $project = $this->activeProject($service);
        if ($record->getProject() !== $project) {
            throw $this->createNotFoundException('Invalid project or record ownership.');
        }

        return $project;
    }

    private function category(CategoryRepository $repository): Category
    {
        $category = $repository->findOneBy(['name' => 'Residuos']);
        if (!$category || !$category->isEnabledInEmissionCalculator()) {
            throw $this->createNotFoundException('Waste category not found.');
        }

        return $category;
    }

    private function hasValidCsrfToken(Request $request, string $tokenId): bool
    {
        $token = $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid($tokenId, $token);
    }

    private function notes(Request $request): ?string
    {
        $notes = $request->request->get('notes');

        return is_string($notes) && '' !== trim($notes) ? trim($notes) : null;
    }

    /** @return list<UploadedFile> */
    private function uploadedAttachments(Request $request): array
    {
        return array_values(array_filter(
            $request->files->all('attachments'),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }

    /** @param list<UploadedFile> $files */
    private function storeAttachments(
        EmissionRecord $record,
        array $files,
        EmissionRecordAttachmentStorage $storage,
        EntityManagerInterface $entityManager,
    ): void {
        $stored = [];
        try {
            foreach ($files as $file) {
                $attachment = $storage->store($record, $file);
                $stored[] = $attachment;
                $entityManager->persist($attachment);
            }
            if ([] !== $stored) {
                $entityManager->flush();
            }
        } catch (\Throwable $e) {
            foreach ($stored as $attachment) {
                try {
                    $storage->delete($attachment);
                } catch (\Throwable) {
                }
                $record->removeAttachment($attachment);
                $entityManager->remove($attachment);
            }

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function indexQuery(Request $request, int $categoryId): array
    {
        $query = $request->query->all();
        $query['categoryId'] = $categoryId;

        return array_filter(
            $query,
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );
    }
}
