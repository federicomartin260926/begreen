<?php

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
use App\Service\Emission\Energy\EnergyEmissionCalculator;
use App\Service\Emission\Energy\EnergyEmissionRecordService;
use App\Service\Emission\Energy\EnergyEmissionRequestMapper;
use App\Service\Emission\Energy\EnergyEmissionSnapshot;
use App\Service\Emission\Energy\EnergyUiCatalog;
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
final class EnergyEmissionController extends AbstractController
{
    private readonly EmissionCountryCatalog $countryCatalog;

    public function __construct(?EmissionCountryCatalog $countryCatalog = null)
    {
        $this->countryCatalog = $countryCatalog ?? new EmissionCountryCatalog();
    }

    private const FORM_FIELDS = [
        'family', 'startDate', 'endDate', 'country', 'origin', 'inputMethod', 'amount', 'unit', 'initialReading', 'finalReading',
        'gridKwh', 'solarKwh', 'supplier', 'labeling', 'equipmentType', 'fuel', 'mode', 'bottleSizeKg',
        'bottleCount', 'batteryType', 'chargeSource', 'chargedKwh', 'notes',
    ];

    #[Route('/energy/preview', name: 'backend_emission_energy_v1_preview', methods: ['POST'])]
    public function preview(
        Request $request,
        ActiveProjectService $activeProjectService,
        EnergyEmissionRequestMapper $requestMapper,
        EnergyEmissionCalculator $calculator,
    ): JsonResponse {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException('No active project.');
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $token = $request->request->get('_preview_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('energy_emission_v1_preview', $token)) {
            return $this->json(['error' => 'csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json($calculator->calculate($requestMapper->map($request))->toArray());
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'invalid_input'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/new-energy-v1', name: 'backend_emission_new_energy_v1', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        EnergyEmissionRequestMapper $requestMapper,
        EnergyEmissionRecordService $recordService,
        EnergyUiCatalog $uiCatalog,
        EmissionRecordAttachmentStorage $attachmentStorage,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->activeProject($activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $category = $this->energyCategory($categoryRepository);
        $values = $this->formValues($request, $this->createDefaults());

        if ($request->isMethod('GET')) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, []);
        }
        if (!$this->hasValidCsrfToken($request, 'energy_emission_v1_create')) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, ['csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attachments = $this->uploadedAttachments($request);
        try {
            $attachmentStorage->validateUploads($attachments);
            $input = $requestMapper->map($request);
            $phase = $projectRepository->findPhaseByDate($project, \DateTimeImmutable::createFromInterface($input->startDate));
            if (!$phase) {
                return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, ['phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $writeResult = $recordService->write($project, $category, $phase, $input, $this->notes($request));
        } catch (EmissionRecordAttachmentValidationException $e) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, [$e->errorKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, ['invalid_input'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->storeAttachments($writeResult->record, $attachments, $attachmentStorage, $entityManager);
        } catch (\Throwable) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $writeResult->record, ['attachment_storage_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->addFlash('success', 'backend.emission.energy_v1.flash.created');

        return $this->redirectToRoute('backend_emission_index', $this->indexQuery($request, (int) $category->getId()));
    }

    #[Route('/{id}/edit-energy-v1', name: 'backend_emission_edit_energy_v1', methods: ['GET', 'POST'])]
    public function edit(
        EmissionRecord $record,
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        EnergyEmissionRequestMapper $requestMapper,
        EnergyEmissionRecordService $recordService,
        EnergyEmissionSnapshot $snapshot,
        EnergyUiCatalog $uiCatalog,
        EmissionRecordAttachmentStorage $attachmentStorage,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->ownedProject($record, $activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::EDIT, $record);
        $category = $this->energyCategory($categoryRepository);
        if (!$snapshot->isEnergyV1Record($record, (int) $category->getId())) {
            throw $this->createNotFoundException('Energy v1 record not found.');
        }

        try {
            $storedValues = $this->supportedFormValues($snapshot->inputToArray($snapshot->decodeInput((string) $record->getCalculationDetails())));
        } catch (\JsonException|\UnexpectedValueException) {
            throw $this->createNotFoundException('Invalid energy v1 snapshot.');
        }
        $storedValues['notes'] = $record->getNotes();
        $values = $this->formValues($request, $storedValues);
        if ($request->isMethod('GET')) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, []);
        }
        if (!$this->hasValidCsrfToken($request, 'energy_emission_v1_edit_'.$record->getId())) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attachments = $this->uploadedAttachments($request);
        try {
            $attachmentStorage->validateUploads($attachments);
            $input = $requestMapper->map($request);
            $phase = $projectRepository->findPhaseByDate($project, \DateTimeImmutable::createFromInterface($input->startDate));
            if (!$phase) {
                return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $recordService->write($project, $category, $phase, $input, $this->notes($request), $record);
        } catch (EmissionRecordAttachmentValidationException $e) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, [$e->errorKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['invalid_input'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->storeAttachments($record, $attachments, $attachmentStorage, $entityManager);
        } catch (\Throwable) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['attachment_storage_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->addFlash('success', 'backend.emission.energy_v1.flash.updated');

        return $this->redirectToRoute('backend_emission_index', $this->indexQuery($request, (int) $category->getId()));
    }

    #[Route('/{id}/duplicate-energy-v1', name: 'backend_emission_duplicate_energy_v1', methods: ['GET'])]
    public function duplicate(
        EmissionRecord $record,
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        EnergyEmissionSnapshot $snapshot,
        EnergyUiCatalog $uiCatalog,
    ): Response {
        $project = $this->ownedProject($record, $activeProjectService);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::VIEW, $record);
        $category = $this->energyCategory($categoryRepository);
        if (!$snapshot->isEnergyV1Record($record, (int) $category->getId())) {
            throw $this->createNotFoundException('Energy v1 record not found.');
        }

        try {
            $values = $this->supportedFormValues($snapshot->inputToArray($snapshot->decodeInput((string) $record->getCalculationDetails())));
        } catch (\JsonException|\UnexpectedValueException) {
            throw $this->createNotFoundException('Invalid energy v1 snapshot.');
        }
        $values['notes'] = $record->getNotes();

        return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, [], duplicate: true);
    }

    /** @param array<string, mixed> $values
     *  @param list<string> $errors
     */
    private function renderForm(
        Request $request,
        Project $project,
        Category $category,
        EnergyUiCatalog $uiCatalog,
        array $values,
        bool $edit,
        ?EmissionRecord $record,
        array $errors,
        int $status = Response::HTTP_OK,
        bool $duplicate = false,
    ): Response {
        $backQuery = $this->indexQuery($request, (int) $category->getId());
        if (is_string($values['country'] ?? null) && '' !== $values['country']) {
            $values['country'] = $this->countryCatalog->iso3ForForm($values['country']);
        }
        if (empty($values['inputMethod'])) {
            $values['inputMethod'] = !empty($values['initialReading']) || !empty($values['finalReading']) ? 'meter' : 'total';
        }
        $formAction = $edit && null !== $record
            ? $this->generateUrl('backend_emission_edit_energy_v1', array_merge(['id' => $record->getId()], $backQuery))
            : $this->generateUrl('backend_emission_new_energy_v1', $backQuery);

        return $this->render('backend/emission/energy_v1_form.html.twig', [
            'project' => $project,
            'category' => $category,
            'edit' => $edit,
            'duplicate' => $duplicate,
            'record' => $record,
            'values' => $values,
            'formAction' => $formAction,
            'energyUiConfig' => $uiCatalog->configuration(),
            'countries' => $this->countryCatalog->choices($request->getLocale()),
            'csrfTokenId' => $edit ? 'energy_emission_v1_edit_'.$record?->getId() : 'energy_emission_v1_create',
            'errors' => $errors,
            'backQuery' => $backQuery,
        ], new Response(status: $status));
    }

    /** @return array<string, string|null> */
    private function createDefaults(): array
    {
        return array_replace(array_fill_keys(self::FORM_FIELDS, null), [
            'family' => 'electricity',
            'mode' => 'direct',
            'inputMethod' => 'total',
        ]);
    }

    /** @param array<string, mixed> $fallback
     *  @return array<string, mixed>
     */
    private function formValues(Request $request, array $fallback): array
    {
        if (!$request->isMethod('POST')) {
            return $fallback;
        }
        foreach (self::FORM_FIELDS as $field) {
            $value = $request->request->get($field);
            $fallback[$field] = is_string($value) ? $value : null;
        }

        return $fallback;
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function supportedFormValues(array $values): array
    {
        return array_intersect_key($values, array_fill_keys(self::FORM_FIELDS, true));
    }

    private function activeProject(ActiveProjectService $service): Project
    {
        $project = $service->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException('No active project.');
        }

        return $project;
    }

    private function ownedProject(EmissionRecord $record, ActiveProjectService $service): Project
    {
        $project = $this->activeProject($service);
        if ($record->getProject() !== $project) {
            throw $this->createNotFoundException('Invalid project or record ownership.');
        }

        return $project;
    }

    private function energyCategory(CategoryRepository $repository): Category
    {
        $category = $repository->findOneBy(['name' => 'Energía']);
        if (!$category || !$category->isEnabledInEmissionCalculator()) {
            throw $this->createNotFoundException('Energy category not found.');
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

        return array_filter($query, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }
}
