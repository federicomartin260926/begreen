<?php

declare(strict_types=1);

namespace App\Controller\Backend;

use App\Entity\FinalReportContent;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\ProjectDocument;
use App\Enum\ProjectDocumentCatalog;
use App\Exception\Ai\AiReportException;
use App\Security\ProjectVoter;
use App\Service\ActiveProjectService;
use App\Service\FinalReport\FinalReportContentEditor;
use App\Service\FinalReport\FinalReportDataBuilder;
use App\Service\FinalReport\FinalReportNarrativeService;
use App\Service\FinalReport\FinalReportPdfBuilder;
use App\Service\ProjectDocument\ProjectDocumentStorage;
use App\Service\ProjectDocument\ProjectDocumentValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/final-report', name: 'backend_final_report_')]
#[IsGranted('ROLE_USER')]
final class FinalReportController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        FinalReportDataBuilder $dataBuilder,
        FinalReportContentEditor $editor,
    ): Response {
        [$project, $plan] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);

        $content = $this->findContent($project, $entityManager) ?? (new FinalReportContent())->setProject($project);

        return $this->renderEditor($project, $plan, $content, $dataBuilder, $editor);
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        FinalReportContentEditor $editor,
    ): RedirectResponse {
        [$project, $plan] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->assertCsrf($request, 'final_report_save_'.$project->getId());

        $content = $this->findContent($project, $entityManager) ?? (new FinalReportContent())->setProject($project);
        try {
            $editor->update($content, $project, $plan, $request->request->all());
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('backend_final_report_index');
        }

        $entityManager->persist($content);
        $entityManager->flush();
        $this->addFlash('success', 'backend.final_report.flash.saved');

        return $this->redirectToRoute('backend_final_report_index');
    }

    #[Route('/narrative/generate', name: 'narrative_generate', methods: ['POST'])]
    public function generateNarrative(
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        FinalReportDataBuilder $dataBuilder,
        FinalReportNarrativeService $narrativeService,
        LoggerInterface $aiLogger,
        TranslatorInterface $translator,
    ): RedirectResponse {
        [$project, $plan] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->assertCsrf($request, 'final_report_ai_'.$project->getId());
        $content = $this->findContent($project, $entityManager) ?? (new FinalReportContent())->setProject($project);
        $replace = array_keys(array_filter(
            $request->request->all('replaceManual'),
            static fn (mixed $value): bool => (string) $value === '1',
        ));

        try {
            $updated = $narrativeService->generate(
                $content,
                $dataBuilder->build($project, $plan),
                $request->getLocale(),
                $replace,
            );
            $entityManager->persist($content);
            $entityManager->flush();
            $this->addFlash('success', $translator->trans(
                'backend.final_report.flash.narrative_updated',
                ['%count%' => count($updated)],
                'messages',
                $request->getLocale(),
            ));
        } catch (AiReportException) {
            // The provider records the technical failure once in the AI channel.
            $this->addFlash('danger', 'backend.final_report.flash.narrative_failed');
        } catch (\InvalidArgumentException $exception) {
            $aiLogger->error('Final report narrative generation failed.', [
                'feature' => 'final_report_narrative',
                'exception_class' => $exception::class,
                'failure_category' => 'application_validation',
                'project_id' => $project->getId(),
            ]);
            $this->addFlash('danger', 'backend.final_report.flash.narrative_failed');
        }

        return $this->redirectToRoute('backend_final_report_index');
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        FinalReportDataBuilder $dataBuilder,
        FinalReportPdfBuilder $pdfBuilder,
    ): Response {
        return $this->pdfResponse($request, $activeProjectService, $entityManager, $dataBuilder, $pdfBuilder, true);
    }

    #[Route('/download', name: 'download', methods: ['GET'])]
    public function download(
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        FinalReportDataBuilder $dataBuilder,
        FinalReportPdfBuilder $pdfBuilder,
    ): Response {
        return $this->pdfResponse($request, $activeProjectService, $entityManager, $dataBuilder, $pdfBuilder, false);
    }

    #[Route('/photos/upload', name: 'photo_upload', methods: ['POST'])]
    public function uploadPhoto(
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        ProjectDocumentStorage $storage,
        FinalReportContentEditor $editor,
    ): RedirectResponse {
        [$project] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->assertCsrf($request, 'final_report_upload_'.$project->getId());

        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'backend.final_report.flash.photo_required');

            return $this->redirectToRoute('backend_final_report_index');
        }

        $document = (new ProjectDocument())
            ->setProject($project)
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE);

        $transactionStarted = false;

        try {
            $editor->validateImageUpload($file);
            $storage->store($document, $file);

            $connection = $entityManager->getConnection();
            $connection->beginTransaction();
            $transactionStarted = true;

            $project->addProjectDocument($document);
            $entityManager->persist($document);
            $entityManager->flush();

            $content = $this->findContent($project, $entityManager) ?? (new FinalReportContent())->setProject($project);
            $editor->appendPhoto($content, $document);
            $entityManager->persist($content);
            $entityManager->flush();

            $connection->commit();
            $transactionStarted = false;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $connection->rollBack();
            }
            $storage->delete($document);

            if ($exception instanceof ProjectDocumentValidationException
                || $exception instanceof \InvalidArgumentException) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('backend_final_report_index');
            }

            throw $exception;
        }

        $this->addFlash('success', 'backend.final_report.flash.photo_uploaded');

        return $this->redirectToRoute('backend_final_report_index');
    }

    #[Route('/photos/{documentId}/replace', name: 'photo_replace', methods: ['POST'], requirements: ['documentId' => '\d+'])]
    public function replacePhoto(
        int $documentId,
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        ProjectDocumentStorage $storage,
        FinalReportContentEditor $editor,
    ): RedirectResponse {
        [$project] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->assertCsrf($request, 'final_report_photo_replace_'.$project->getId().'_'.$documentId);

        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('danger', 'backend.final_report.flash.photo_required');

            return $this->redirectToRoute('backend_final_report_index');
        }

        $original = $entityManager->getRepository(ProjectDocument::class)->find($documentId);
        $content = $this->findContent($project, $entityManager);
        $replacement = (new ProjectDocument())
            ->setProject($project)
            ->setType('final_report_photo')
            ->setKind(ProjectDocumentCatalog::KIND_FILE);
        $transactionStarted = false;
        $originalPhotos = $content?->getPhotos() ?? [];
        $originalCoverDocumentId = $content?->getCoverDocumentId();

        try {
            if (!$original instanceof ProjectDocument || !$content instanceof FinalReportContent) {
                throw new \InvalidArgumentException('The photograph is not included in the final report.');
            }
            $editor->assertPhotoCanBeReplaced($content, $project, $original);
            $editor->validateImageUpload($file);
            $storage->store($replacement, $file);

            $connection = $entityManager->getConnection();
            $connection->beginTransaction();
            $transactionStarted = true;

            $project->addProjectDocument($replacement);
            $entityManager->persist($replacement);
            $entityManager->flush();

            $editor->replacePhoto($content, $project, $original, $replacement);
            $entityManager->persist($content);
            $entityManager->flush();

            $connection->commit();
            $transactionStarted = false;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                $connection->rollBack();
            }
            if ($content instanceof FinalReportContent) {
                $content
                    ->setPhotos($originalPhotos)
                    ->setCoverDocumentId($originalCoverDocumentId);
            }
            $storage->delete($replacement);
            $project->removeProjectDocument($replacement);

            if ($exception instanceof ProjectDocumentValidationException
                || $exception instanceof \InvalidArgumentException) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('backend_final_report_index');
            }

            throw $exception;
        }

        $this->addFlash('success', 'backend.final_report.flash.photo_replaced');

        return $this->redirectToRoute('backend_final_report_index');
    }

    #[Route('/photos/{documentId}/remove', name: 'photo_remove', methods: ['POST'], requirements: ['documentId' => '\d+'])]
    public function removePhoto(
        int $documentId,
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        FinalReportContentEditor $editor,
    ): RedirectResponse {
        [$project] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $project);
        $this->assertCsrf($request, 'final_report_photo_remove_'.$project->getId().'_'.$documentId);

        $document = $entityManager->getRepository(ProjectDocument::class)->find($documentId);
        $content = $this->findContent($project, $entityManager) ?? (new FinalReportContent())->setProject($project);

        try {
            if (!$document instanceof ProjectDocument) {
                throw new \InvalidArgumentException('The photograph does not belong to the active project.');
            }
            $editor->excludePhoto($content, $project, $document);
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('backend_final_report_index');
        }

        $entityManager->persist($content);
        $entityManager->flush();
        $this->addFlash('success', 'backend.final_report.flash.photo_removed');

        return $this->redirectToRoute('backend_final_report_index');
    }

    #[Route('/photos/{documentId}', name: 'photo_view', methods: ['GET'], requirements: ['documentId' => '\d+'])]
    public function viewPhoto(
        int $documentId,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        ProjectDocumentStorage $storage,
        FinalReportContentEditor $editor,
    ): Response {
        [$project] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $document = $entityManager->getRepository(ProjectDocument::class)->find($documentId);

        if (!$document instanceof ProjectDocument
            || $document->getProject()?->getId() !== $project->getId()
            || !$editor->isSupportedImageDocument($document)) {
            throw $this->createNotFoundException();
        }

        $path = $storage->absolutePath($document);
        if (!is_file($path) || !is_readable($path)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', (string) $document->getMimeType());
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'");
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getOriginalName() ?? 'report-image',
        );

        return $response;
    }

    /** @return array{Project, Plan} */
    private function activeContext(ActiveProjectService $activeProjectService, EntityManagerInterface $entityManager): array
    {
        $project = $activeProjectService->getActiveProject();
        if (!$project instanceof Project) {
            throw $this->createNotFoundException('No active project.');
        }
        $plan = $entityManager->getRepository(Plan::class)->findOneBy(['project' => $project]);
        if (!$plan instanceof Plan) {
            throw $this->createNotFoundException('The active project has no sustainability plan.');
        }

        return [$project, $plan];
    }

    private function findContent(Project $project, EntityManagerInterface $entityManager): ?FinalReportContent
    {
        $content = $entityManager->getRepository(FinalReportContent::class)->findOneBy(['project' => $project]);

        return $content instanceof FinalReportContent ? $content : null;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function renderEditor(
        Project $project,
        Plan $plan,
        FinalReportContent $content,
        FinalReportDataBuilder $dataBuilder,
        FinalReportContentEditor $editor,
    ): Response {
        $photoValues = [];
        foreach ($content->getPhotos() as $photo) {
            if (isset($photo['documentId']) && !$editor->isExcludedPhotoEntry($photo)) {
                $photoValues[(int) $photo['documentId']] = $photo;
            }
        }
        $featuredValues = [];
        foreach ($content->getFeaturedMeasures() as $featured) {
            if (isset($featured['planMeasureId'])) {
                $featuredValues[(int) $featured['planMeasureId']] = $featured;
            }
        }
        $hiddenDocumentIds = array_fill_keys([
            ...$editor->supersededPhotoDocumentIds($content),
            ...$editor->excludedPhotoDocumentIds($content),
        ], true);
        $gallery = array_values(array_filter(
            $project->getProjectDocuments()->toArray(),
            static fn (mixed $document): bool => $document instanceof ProjectDocument
                && $editor->isSupportedImageDocument($document)
                && !isset($hiddenDocumentIds[$document->getId()]),
        ));

        return $this->render('backend/final_report/index.html.twig', [
            'project' => $project,
            'plan' => $plan,
            'report' => $dataBuilder->build($project, $plan),
            'content' => $content,
            'gallery' => $gallery,
            'photoValues' => $photoValues,
            'featuredValues' => $featuredValues,
            'manualSections' => FinalReportContentEditor::MANUAL_SECTIONS,
        ]);
    }

    private function pdfResponse(
        Request $request,
        ActiveProjectService $activeProjectService,
        EntityManagerInterface $entityManager,
        FinalReportDataBuilder $dataBuilder,
        FinalReportPdfBuilder $pdfBuilder,
        bool $inline,
    ): Response {
        [$project, $plan] = $this->activeContext($activeProjectService, $entityManager);
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $draft = $request->query->get('report_design') === 'draft';
        if ($draft) {
            $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        }
        $content = $this->findContent($project, $entityManager) ?? (new FinalReportContent())->setProject($project);
        $pdf = $pdfBuilder->generate($project, $content, $dataBuilder->build($project, $plan), $draft);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="final-sustainability-report.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
