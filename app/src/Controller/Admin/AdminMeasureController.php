<?php

namespace App\Controller\Admin;

use App\Entity\{Measure, Category, Department, Protocol, Ods, EsG, Scope, CategoryGhg, ImpactArea, TripleBalanceAxis, VerificationSource};
use App\Form\{MeasureType, MeasureImportType};
use Dompdf\{Dompdf, Options};
use App\Repository\{MeasureRepository, ProtocolRepository, CategoryGhgRepository, CategoryRepository, DepartmentRepository, OdsRepository, EsGRepository, ScopeRepository};
use App\Repository\MeasureBlockRepository;
use App\Service\MeasureCatalogAdminService;
use App\Service\MeasureTaxonomyPresenter;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\MeasureTemplateExporter;
use App\Service\MeasureTemplateImporter;
use App\Service\MeasureTemplateParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse, ResponseHeaderBag, File\Exception\FileException};
use Symfony\Component\Routing\Annotation\Route;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

use Symfony\Contracts\Translation\TranslatorInterface;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\Form\FormError;

#[Route('/admin/measures', name: 'admin_measures_')]
class AdminMeasureController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private MeasureTemplateParser $measureTemplateParser,
        private MeasureTemplateImporter $measureTemplateImporter,
        private MeasureTemplateExporter $measureTemplateExporter,
        private MeasureCatalogAdminService $catalogAdminService,
        private MeasureTaxonomyPresenter $taxonomyPresenter
    ) {}

    #[Route('/', name: 'index')]
    public function index(MeasureRepository $measureRepository, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $measures = $measureRepository->findAll();
        $form = $this->createForm(MeasureImportType::class);

        return $this->render('admin/measure/index.html.twig', [
            'measures'          => $measures,
            'catalogSummary'    => $this->catalogAdminService->summarizeCatalog($measures),
            'taxonomyPresenter' => $this->taxonomyPresenter,
            'importForm'        => $form->createView(),
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        TranslatableListener $translatableListener
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $displayLocale = $request->getLocale(); // EN si la UI está en inglés

        // 1) Mostrar selects en idioma de la UI
        $translatableListener->setTranslatableLocale($displayLocale);

        $measure = new Measure();

        $locales = ['en']; // añade más si procede
        $fields  = ['name','nameReview','description','implementation','departmentActionText'];

        $form = $this->createForm(MeasureType::class, $measure, [
            'locales'             => array_merge(['es'], $locales),
            'default_locale'      => 'es',
            'translatable_fields' => $fields,
            'translations'        => [],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->syncMeasureImportVersion($measure);
            $selectedSources = $this->collectSelectedVerificationSources($form);
            $validationErrors = $this->catalogAdminService->validateV23Measure($measure, $selectedSources);
            if ($validationErrors !== []) {
                $this->addFormErrors($form, $validationErrors);
            } else {
                // 2) Guardar base en ES
                $translatableListener->setTranslatableLocale('es');
                $em->persist($measure);
                $em->flush(); // necesitamos ID

                /** @var \Gedmo\Translatable\Entity\Repository\TranslationRepository $tr */
                $tr = $em->getRepository(Translation::class);

                // 3) Guardar traducciones de locales no-ES
                foreach ($locales as $loc) {
                    foreach ($fields as $f) {
                        $val = (string) ($form->get($f . '_' . $loc)->getData() ?? '');
                        if ($val !== '') {
                            $tr->translate($measure, $f, $loc, $val);
                        }
                    }
                }

                $syncOk = true;
                try {
                    $this->catalogAdminService->syncVerificationSources($measure, $selectedSources);
                } catch (\InvalidArgumentException $e) {
                    $this->addFormErrors($form, [[
                        'field' => 'verificationSourcePriority1',
                        'message' => $e->getMessage(),
                    ]]);
                    $syncOk = false;
                }

                if ($syncOk) {
                    $measure->setDepartment($measure->getPrimaryDepartment());
                    $measure->setOds($measure->getPrimaryOds());

                    $em->flush();

                    $this->addFlash('success', 'backend.measures.flash.created');
                    return $this->redirectToRoute('admin_measures_index');
                }
            }
        }

        return $this->render('admin/measure/form.html.twig', [
            'form'    => $form->createView(),
            'measure' => $measure,
            'edit'    => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(
        int $id,
        Request $request,
        EntityManagerInterface $em,
        TranslatableListener $translatableListener
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        // 1) Cargar SIEMPRE la entidad en ES (base)
        $translatableListener->setTranslatableLocale('es');
        $measure = $em->getRepository(Measure::class)->find($id);
        if (!$measure) {
            throw $this->createNotFoundException();
        }

        $locales = ['en']; // añade más si procede
        $fields  = ['name','nameReview','description','implementation','departmentActionText'];

        /** @var \Gedmo\Translatable\Entity\Repository\TranslationRepository $tr */
        $tr = $em->getRepository(Translation::class);
        $existingTranslations = $tr->findTranslations($measure);

        // 2) Si es GET (mostrar formulario), cambiamos a locale visible SOLO para pintar selects traducidos
        if ($request->isMethod('GET')) {
            $translatableListener->setTranslatableLocale($request->getLocale());
        } else {
            // En POST nos aseguramos de estar en ES antes de bindear datos base
            $translatableListener->setTranslatableLocale('es');
        }

        $form = $this->createForm(MeasureType::class, $measure, [
            'locales'             => array_merge(['es'], $locales),
            'default_locale'      => 'es',
            'translatable_fields' => $fields,
            'translations'        => $existingTranslations,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->syncMeasureImportVersion($measure);
            $selectedSources = $this->collectSelectedVerificationSources($form);
            $validationErrors = $this->catalogAdminService->validateV23Measure($measure, $selectedSources);
            if ($validationErrors !== []) {
                $this->addFormErrors($form, $validationErrors);
            } else {
                // 3) Asegurar ES al guardar los campos base (muy importante)
                $translatableListener->setTranslatableLocale('es');

                // 4) Upsert de traducciones NO-ES desde los campos unmapped
                foreach ($locales as $loc) {
                    foreach ($fields as $f) {
                        $fieldName = $f . '_' . $loc;
                        $val = (string) ($form->get($fieldName)->getData() ?? '');

                        if ($val !== '') {
                            $tr->translate($measure, $f, $loc, $val);
                        } else {
                            if (!empty($existingTranslations[$loc][$f])) {
                                $em->createQuery('DELETE FROM Gedmo\\Translatable\\Entity\\Translation t
                                                WHERE t.objectClass = :cls AND t.field = :field
                                                    AND t.foreignKey = :fk AND t.locale = :loc')
                                ->setParameters([
                                    'cls'   => Measure::class,
                                    'field' => $f,
                                    'fk'    => $measure->getId(),
                                    'loc'   => $loc,
                                ])->execute();
                            }
                        }
                    }
                }

                $syncOk = true;
                try {
                    $this->catalogAdminService->syncVerificationSources($measure, $selectedSources);
                } catch (\InvalidArgumentException $e) {
                    $this->addFormErrors($form, [[
                        'field' => 'verificationSourcePriority1',
                        'message' => $e->getMessage(),
                    ]]);
                    $syncOk = false;
                }

                if ($syncOk) {
                    $measure->setDepartment($measure->getPrimaryDepartment());
                    $measure->setOds($measure->getPrimaryOds());

                    $em->flush();

                    $this->addFlash('success', 'backend.measures.flash.updated');
                    return $this->redirectToRoute('admin_measures_index');
                }
            }
        }

        // 5) Tras construir la vista en GET, si quieres que los SELECTs se vean en el idioma de la UI:
        // (ya lo pusimos arriba para GET antes de crear el form)

        return $this->render('admin/measure/form.html.twig', [
            'form'    => $form->createView(),
            'measure' => $measure,
            'edit'    => true,
        ]);
    }

    private function collectSelectedVerificationSources($form): array
    {
        return [
            1 => $form->has('verificationSourcePriority1') ? $form->get('verificationSourcePriority1')->getData() : null,
            2 => $form->has('verificationSourcePriority2') ? $form->get('verificationSourcePriority2')->getData() : null,
            3 => $form->has('verificationSourcePriority3') ? $form->get('verificationSourcePriority3')->getData() : null,
        ];
    }

    private function syncMeasureImportVersion(Measure $measure): void
    {
        $protocolCode = $measure->getProtocol()?->getCode();
        if ($protocolCode === PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_CODE) {
            $measure->setImportVersion(PlanMeasureCatalogResolver::BE_GREEN_MY_FILM_IMPORT_VERSION);
        }
    }

    /**
     * @param array<int, array{field:string, message:string}> $errors
     */
    private function addFormErrors($form, array $errors): void
    {
        foreach ($errors as $error) {
            $field = $error['field'] ?? null;
            $message = $error['message'] ?? null;

            if (!is_string($message) || $message === '') {
                continue;
            }

            if (is_string($field) && $field !== '' && $form->has($field)) {
                $form->get($field)->addError(new FormError($message));
                continue;
            }

            $form->addError(new FormError($message));
        }
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Measure $measure, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid('delete_measure_' . $measure->getId(), (string)$request->request->get('_token'))) {
            $em->remove($measure);
            $em->flush();

            $this->addFlash('success', 'backend.measures.flash.deleted');
        } else {
            $this->addFlash('danger', 'backend.common.csrf_invalid');
        }

        return $this->redirectToRoute('admin_measures_index');
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(
        Request $request,
        TranslatorInterface $t,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $form = $this->createForm(MeasureImportType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', $t->trans('backend.measures.flash.invalid_form'));
            return $this->redirectToRoute('admin_measures_index');
        }
        $file = $form->get('file')->getData();
        if (!$file) {
            $this->addFlash('danger', $t->trans('backend.measures.flash.invalid_form'));
            return $this->redirectToRoute('admin_measures_index');
        }

        try {
            $report = $this->measureTemplateParser->parseFile($file->getPathname());
            $report = $this->measureTemplateImporter->import($report, true);

            $summary = $report->getImportSummary();
            if (($summary['status'] ?? '') !== 'applied') {
                $firstError = $report->getErrors()[0]['message'] ?? $t->trans('backend.measures.flash.import_error', ['%msg%' => 'Validation errors']);
                $this->addFlash('danger', (string) $firstError);
            } else {
                $this->addFlash('success', $t->trans('backend.measures.import.summary', [
                    '%imported%' => $summary['imported'] ?? 0,
                    '%updated%' => $summary['updated'] ?? 0,
                    '%duplicates%' => $summary['duplicates'] ?? 0,
                    '%errors%' => $summary['errors'] ?? 0,
                ]));
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', $t->trans('backend.measures.flash.import_error', [
                '%msg%' => $e->getMessage()
            ]));
        }

        return $this->redirectToRoute('admin_measures_index');
    }

   #[Route('/template/download', name: 'template_download', methods: ['GET'])]
    public function downloadTemplate(
        EntityManagerInterface $em,
        ProtocolRepository $protocolRepo,
        CategoryGhgRepository $ghgRepo,
        CategoryRepository $categoryRepo,
        DepartmentRepository $departmentRepo,
        OdsRepository $odsRepo,
        EsGRepository $esgRepo,
        ScopeRepository $scopeRepo,
        MeasureBlockRepository $measureBlockRepository,
        TranslatorInterface $translator,
    ): Response {
        $catalog = [
            'protocols' => $protocolRepo->findAll(),
            'categories' => $categoryRepo->findAll(),
            'categoryGhgs' => $ghgRepo->findAll(),
            'departments' => $departmentRepo->findAll(),
            'ods' => $odsRepo->findAll(),
            'esg' => $esgRepo->findAll(),
            'scopes' => $scopeRepo->findAll(),
            'impactAreas' => $em->getRepository(ImpactArea::class)->findAll(),
            'tripleBalanceAxes' => $em->getRepository(TripleBalanceAxis::class)->findAll(),
            'verificationSources' => $em->getRepository(VerificationSource::class)->findAll(),
            'measureBlocks' => $measureBlockRepository->createQueryBuilder('b')
                ->leftJoin('b.protocol', 'p')
                ->addSelect('p')
                ->andWhere('b.active = true')
                ->orderBy('p.name', 'ASC')
                ->addOrderBy('b.sortOrder', 'ASC')
                ->addOrderBy('b.name', 'ASC')
                ->getQuery()
                ->getResult(),
        ];

        $spreadsheet = $this->measureTemplateExporter->buildSpreadsheet($catalog);

        $response = new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        });

        $filename = $translator->trans('backend.measures.template.filename', [], 'messages', 'es');
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/export/pdf', name: 'export_pdf')]
    public function exportPdf(MeasureRepository $measureRepository): Response
    {
        $measures = $measureRepository->createQueryBuilder('m')
            ->leftJoin('m.protocol', 'p')
            ->addSelect('p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();

        $html = $this->renderView('admin/measure/pdf.html.twig', [
            'measures' => $measures,
        ]);

        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($pdfOptions);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="measures.pdf"',
            ]
        );
    }
}
