<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Exception\Ai\AiAuthenticationException;
use App\Exception\Ai\AiConnectionException;
use App\Exception\Ai\AiEmptyResponseException;
use App\Exception\Ai\AiInvalidJsonResponseException;
use App\Exception\Ai\AiInvalidStructureException;
use App\Exception\Ai\AiProviderNotConfiguredException;
use App\Exception\Ai\AiQuotaExceededException;
use App\Exception\Ai\AiRateLimitException;
use App\Exception\Ai\AiReportException;
use App\Exception\Ai\AiTimeoutException;
use App\Exception\ReportDesign\ReportDesignRequiresDevelopmentException;
use App\Service\Ai\AiReportSettingResolver;
use App\Service\ReportDesign\ReportDesignAiGenerator;
use App\Service\ReportDesign\ReportDesignCatalog;
use App\Service\ReportDesign\ReportDesignComponentCatalog;
use App\Service\ReportDesign\ReportDesignDataCatalog;
use App\Service\ReportDesign\ReportDesignManager;
use App\Service\ReportDesign\ReportDesignStorage;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/report-design', name: 'admin_report_design_')]
final class ReportDesignController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        ReportDesignCatalog $designCatalog,
        ReportDesignStorage $storage,
        ReportDesignComponentCatalog $componentCatalog,
        ReportDesignDataCatalog $dataCatalog,
        AiReportSettingResolver $settingResolver,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $definition = $this->definition($request, $designCatalog);
        $settings = $settingResolver->resolve();

        return $this->render('admin/report_design/index.html.twig', [
            'definition' => $definition,
            'draft' => $storage->loadDraft($definition),
            'approved' => $storage->loadApproved($definition),
            'definitions' => $designCatalog->all(),
            'components' => $componentCatalog->all($definition),
            'dataTree' => $dataCatalog->tree($definition),
            'provider' => $settings->provider,
            'model' => $settings->model(),
        ]);
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(
        Request $request,
        ReportDesignCatalog $designCatalog,
        ReportDesignAiGenerator $generator,
        LoggerInterface $aiLogger,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid(
            'report_design_generate',
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $instruction = (string) $request->request->get('instruction', '');
        $definition = $this->definition($request, $designCatalog);

        try {
            $generator->generateDraft($definition, $instruction);

            $this->addFlash(
                'success',
                'backend.report_design.flash.generated',
            );
        } catch (ReportDesignRequiresDevelopmentException $exception) {
            $this->addFlash(
                'warning',
                ReportDesignRequiresDevelopmentException::USER_MESSAGE,
            );
        } catch (AiReportException $exception) {
            $aiLogger->error('AI report design generation failed.', [
                'feature' => 'report_designer_ai',
                'exception_class' => $exception::class,
                'failure_category' => $this->aiFailureCategory($exception),
                'document' => $definition->document,
                'variant' => $definition->variant,
            ]);
            $this->addFlash(
                'danger',
                'backend.report_design.flash.ai_error',
            );
        } catch (\InvalidArgumentException $exception) {
            $aiLogger->warning(
                'AI report design proposal rejected by validator.',
                [
                    'feature' => 'report_designer_ai',
                    'failure_category' => 'application_validation',
                    'exception_class' => $exception::class,
                    'document' => $definition->document,
                    'variant' => $definition->variant,
                ],
            );

            $this->addFlash(
                'danger',
                'backend.report_design.flash.invalid_design',
            );
        }

        return $this->redirectToRoute('admin_report_design_index', $this->definitionParameters($definition));
    }

    private function aiFailureCategory(AiReportException $exception): string
    {
        return match (true) {
            $exception instanceof AiProviderNotConfiguredException => 'configuration',
            $exception instanceof AiAuthenticationException => 'authentication',
            $exception instanceof AiQuotaExceededException => 'quota',
            $exception instanceof AiRateLimitException => 'rate_limit',
            $exception instanceof AiTimeoutException => 'timeout',
            $exception instanceof AiConnectionException => 'connection',
            $exception instanceof AiEmptyResponseException => 'empty_response',
            $exception instanceof AiInvalidJsonResponseException,
            $exception instanceof AiInvalidStructureException => 'invalid_response',
            default => 'provider',
        };
    }

    #[Route('/approve', name: 'approve', methods: ['POST'])]
    public function approve(
        Request $request,
        ReportDesignCatalog $designCatalog,
        ReportDesignManager $manager,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid(
            'report_design_approve',
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $definition = $this->definition($request, $designCatalog);
        try {
            $manager->approve($definition);

            $this->addFlash(
                'success',
                'backend.report_design.flash.approved',
            );
        } catch (\InvalidArgumentException|\RuntimeException) {
            $this->addFlash(
                'danger',
                'backend.report_design.flash.approve_error',
            );
        }

        return $this->redirectToRoute('admin_report_design_index', $this->definitionParameters($definition));
    }

    #[Route(
        '/discard-draft',
        name: 'discard_draft',
        methods: ['POST'],
    )]
    public function discardDraft(
        Request $request,
        ReportDesignCatalog $designCatalog,
        ReportDesignManager $manager,
    ): RedirectResponse {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid(
            'report_design_discard_draft',
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $definition = $this->definition($request, $designCatalog);
        try {
            $manager->discardDraft($definition);

            $this->addFlash(
                'success',
                'backend.report_design.flash.discarded',
            );
        } catch (\RuntimeException) {
            $this->addFlash(
                'danger',
                'backend.report_design.flash.discard_error',
            );
        }

        return $this->redirectToRoute(
            'admin_report_design_index',
            $this->definitionParameters($definition),
        );
    }

    private function definition(Request $request, ReportDesignCatalog $catalog): \App\Service\ReportDesign\ReportDesignDefinition
    {
        return $catalog->get(
            (string) ($request->request->get('document') ?: $request->query->get('document', ReportDesignCatalog::DOCUMENT_SUSTAINABILITY_PLAN)),
            (string) ($request->request->get('variant') ?: $request->query->get('variant', ReportDesignCatalog::VARIANT_GENERAL)),
        );
    }

    /** @return array{document:string,variant:string} */
    private function definitionParameters(\App\Service\ReportDesign\ReportDesignDefinition $definition): array
    {
        return ['document' => $definition->document, 'variant' => $definition->variant];
    }

}
