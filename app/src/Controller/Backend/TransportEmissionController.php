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
use App\Service\Emission\EmissionRecordAttachmentValidationException;
use App\Service\Emission\Transport\TransportEmissionRecordService;
use App\Service\Emission\Transport\TransportEmissionPresentationMapper;
use App\Service\Emission\Transport\TransportEmissionRequestMapper;
use App\Service\Emission\Transport\TransportEmissionSnapshot;
use App\Service\Emission\Transport\TransportUiCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/emission')]
#[IsGranted('ROLE_USER')]
final class TransportEmissionController extends AbstractController
{
    private const FORM_FIELDS = [
        'category', 'mode', 'method', 'country', 'startedAt', 'activityValue', 'activityUnit', 'repetitions',
        'passengers', 'weightValue', 'weightUnit', 'vehicleType', 'carSize', 'fuel', 'thermalFuel',
        'routeClassification', 'travelClass', 'notes',
        'origin', 'destination', 'originLatitude', 'originLongitude', 'destinationLatitude', 'destinationLongitude',
        'tripType', 'stops', 'operatorReference', 'secondaryActivityValue', 'secondaryActivityUnit',
    ];

    #[Route('/new-transport', name: 'backend_emission_new_transport_v20', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        ProjectRepository $projectRepository,
        TransportEmissionRequestMapper $requestMapper,
        TransportEmissionRecordService $recordService,
        TransportEmissionPresentationMapper $presentationMapper,
        TransportUiCatalog $uiCatalog,
        EmissionRecordAttachmentStorage $attachmentStorage,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project) {
            throw $this->createNotFoundException('No active project.');
        }
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);

        $category = $this->transportCategory($categoryRepository);
        $values = $this->formValues($request, $this->createDefaults($project));
        if ($request->isMethod('GET')) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, []);
        }

        if (!$this->hasValidCsrfToken($request, 'transport_emission_v20_create')) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, ['csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attachments = $this->uploadedAttachments($request);
        try {
            $attachmentStorage->validateUploads($attachments);
        } catch (EmissionRecordAttachmentValidationException $e) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, [$e->errorKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $input = $requestMapper->map($request);
            $presentation = $presentationMapper->map($request);
            $phase = $projectRepository->findPhaseByDate($project, \DateTimeImmutable::createFromInterface($input->startedAt));
            if (!$phase) {
                return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, ['phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $writeResult = $recordService->write($project, $category, $phase, $input, $this->notes($request), presentation: $presentation);
        } catch (\InvalidArgumentException) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, ['invalid_input'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$writeResult->isPersisted()) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, false, null, [$writeResult->calculation->status], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->storeAttachments($writeResult->record, $attachments, $attachmentStorage, $entityManager);
        } catch (\Throwable) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $writeResult->record, ['attachment_storage_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->addFlash('success', 'backend.emission.transport_v20.flash.created');

        return $this->redirectToRoute('backend_emission_index', $this->indexQuery($request, (int) $category->getId()));
    }

    #[Route('/{id}/duplicate-transport', name: 'backend_emission_duplicate_transport_v20', methods: ['GET'])]
    public function duplicate(
        EmissionRecord $record,
        Request $request,
        ActiveProjectService $activeProjectService,
        CategoryRepository $categoryRepository,
        TransportEmissionSnapshot $snapshot,
        TransportUiCatalog $uiCatalog,
    ): Response {
        $project = $activeProjectService->getActiveProject();
        if (!$project || $record->getProject() !== $project) {
            throw $this->createNotFoundException('Invalid project or record ownership.');
        }

        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->denyAccessUnlessGranted(EmissionRecordVoter::VIEW, $record);

        $category = $this->transportCategory($categoryRepository);
        if (!$snapshot->isTransportV20Record($record, (int) $category->getId())) {
            throw $this->createNotFoundException('Transport v20 record not found.');
        }

        try {
            $values = array_replace(
                $snapshot->inputToArray($snapshot->decode((string) $record->getCalculationDetails())),
                $snapshot->decodePresentation((string) $record->getCalculationDetails()),
            );
        } catch (\JsonException|\UnexpectedValueException) {
            throw $this->createNotFoundException('Invalid transport v20 snapshot.');
        }

        $values['notes'] = $record->getNotes();

        return $this->renderForm(
            $request,
            $project,
            $category,
            $uiCatalog,
            $values,
            false,
            null,
            [],
            duplicate: true,
        );
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
        TransportEmissionPresentationMapper $presentationMapper,
        TransportUiCatalog $uiCatalog,
        EmissionRecordAttachmentStorage $attachmentStorage,
        EntityManagerInterface $entityManager,
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
            $storedValues = array_replace(
                $snapshot->inputToArray($snapshot->decode((string) $record->getCalculationDetails())),
                $snapshot->decodePresentation((string) $record->getCalculationDetails()),
            );
        } catch (\JsonException|\UnexpectedValueException) {
            throw $this->createNotFoundException('Invalid transport v20 snapshot.');
        }
        $storedValues['notes'] = $record->getNotes();
        $values = $this->formValues($request, $storedValues);

        if ($request->isMethod('GET')) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, []);
        }

        $tokenId = 'transport_emission_v20_edit_'.$record->getId();
        if (!$this->hasValidCsrfToken($request, $tokenId)) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['csrf_invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $attachments = $this->uploadedAttachments($request);
        try {
            $attachmentStorage->validateUploads($attachments);
        } catch (EmissionRecordAttachmentValidationException $e) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, [$e->errorKey], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $input = $requestMapper->map($request);
            $presentation = $presentationMapper->map($request);
            $phase = $projectRepository->findPhaseByDate($project, \DateTimeImmutable::createFromInterface($input->startedAt));
            if (!$phase) {
                return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['phase_not_available'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $writeResult = $recordService->write($project, $category, $phase, $input, $this->notes($request), $record, $presentation);
        } catch (\InvalidArgumentException) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['invalid_input'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$writeResult->isPersisted()) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, [$writeResult->calculation->status], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->storeAttachments($record, $attachments, $attachmentStorage, $entityManager);
        } catch (\Throwable) {
            return $this->renderForm($request, $project, $category, $uiCatalog, $values, true, $record, ['attachment_storage_failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->addFlash('success', 'backend.emission.transport_v20.flash.updated');

        return $this->redirectToRoute('backend_emission_index', $this->indexQuery($request, (int) $category->getId()));
    }

    /** @param array<string, string|null> $values
     *  @param list<string> $errors
     */
    private function renderForm(
        Request $request,
        Project $project,
        Category $category,
        TransportUiCatalog $uiCatalog,
        array $values,
        bool $edit,
        ?EmissionRecord $record,
        array $errors,
        int $status = Response::HTTP_OK,
        bool $duplicate = false,
    ): Response {
        $tokenId = $edit ? 'transport_emission_v20_edit_'.$record?->getId() : 'transport_emission_v20_create';
        $backQuery = $this->indexQuery($request, (int) $category->getId());

        $formAction = $edit && null !== $record
            ? $this->generateUrl(
                'backend_emission_edit_transport_v20',
                array_merge(['id' => $record->getId()], $backQuery),
            )
            : $this->generateUrl('backend_emission_new_transport_v20', $backQuery);

        return $this->render('backend/emission/transport_v20_form.html.twig', [
            'project' => $project,
            'category' => $category,
            'edit' => $edit,
            'duplicate' => $duplicate,
            'record' => $record,
            'values' => $values,
            'formAction' => $formAction,
            'transportCategories' => $uiCatalog->categories(),
            'transportMethods' => $uiCatalog->methods(),
            'transportUiConfig' => $uiCatalog->configuration(),
            'csrfTokenId' => $tokenId,
            'errors' => $errors,
            'backQuery' => $backQuery,
        ], new Response(status: $status));
    }

    /** @return array<string, string|null> */
    private function createDefaults(Project $project): array
    {
        $country = strtoupper((string) $project->getCountry());

        return [
            'category' => 'local',
            'mode' => 'car',
            'method' => 'distance',
            'country' => 1 === preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
            'startedAt' => '',
            'activityValue' => '',
            'activityUnit' => '',
            'repetitions' => '1',
            'passengers' => null,
            'weightValue' => null,
            'weightUnit' => null,
            'vehicleType' => null,
            'carSize' => null,
            'fuel' => null,
            'thermalFuel' => null,
            'routeClassification' => null,
            'travelClass' => null,
            'notes' => null,
            'origin' => null,
            'destination' => null,
            'originLatitude' => null,
            'originLongitude' => null,
            'destinationLatitude' => null,
            'destinationLongitude' => null,
            'tripType' => null,
            'stops' => null,
            'operatorReference' => null,
            'secondaryActivityValue' => null,
            'secondaryActivityUnit' => null,
        ];
    }

    /** @param array<string, string|null> $fallback
     *  @return array<string, string|null>
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

    private function transportCategory(CategoryRepository $repository): Category
    {
        $category = $repository->findOneBy(['name' => 'Transporte']);
        if (!$category || !$category->isEnabledInEmissionCalculator()) {
            throw $this->createNotFoundException('Transport category not found.');
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

        return is_string($notes) && '' !== $notes ? $notes : null;
    }

    /** @return list<UploadedFile> */
    private function uploadedAttachments(Request $request): array
    {
        $files = $request->files->all('attachments');

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
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

        return array_filter($query, static fn ($value): bool => null !== $value && '' !== $value);
    }
}
