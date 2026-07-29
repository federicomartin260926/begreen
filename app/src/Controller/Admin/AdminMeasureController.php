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
        private MeasureTaxonomyPresenter $taxonomyPresenter,
        private PlanMeasureCatalogResolver $catalogResolver,
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
        $fields  = ['name','nameReview','questionText','gamificationMessage','description','implementation','departmentActionText'];

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
                    if ($measure->getSortOrder() <= 0) {
                        $measure->setSortOrder($this->nextMeasureSortOrder($em, $measure));
                    }

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
        $fields  = ['name','nameReview','questionText','gamificationMessage','description','implementation','departmentActionText'];

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
                    if ($measure->getSortOrder() <= 0) {
                        $measure->setSortOrder($this->nextMeasureSortOrder($em, $measure));
                    }

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
        $importVersion = $this->catalogResolver->getImportVersionForProtocol($measure->getProtocol());
        if ($importVersion !== null) {
            $measure->setImportVersion($importVersion);
        }
    }

    private function nextMeasureSortOrder(EntityManagerInterface $em, Measure $measure): int
    {
        $protocol = $measure->getProtocol();
        if (!$protocol) {
            return 10;
        }

        $qb = $em->createQueryBuilder()
            ->select('COALESCE(MAX(m.sortOrder), 0)')
            ->from(Measure::class, 'm')
            ->andWhere('m.protocol = :protocol')
            ->setParameter('protocol', $protocol);

        if ($protocol->getGroupingBy() === Protocol::GROUP_BY_DEPARTMENT) {
            $department = $measure->getDepartment();
            if ($department) {
                $qb->andWhere('m.department = :groupEntity')
                    ->setParameter('groupEntity', $department);
            } else {
                $qb->andWhere('m.department IS NULL');
            }
        } else {
            $category = $measure->getCategory();
            if ($category) {
                $qb->andWhere('m.category = :groupEntity')
                    ->setParameter('groupEntity', $category);
            } else {
                $qb->andWhere('m.category IS NULL');
            }
        }

        $measureBlock = $measure->getMeasureBlock();
        if ($measureBlock) {
            $qb->andWhere('m.measureBlock = :measureBlock')
                ->setParameter('measureBlock', $measureBlock);
        } else {
            $qb->andWhere('m.measureBlock IS NULL');
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) + 10;
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

    #[Route('/export/xlsx', name: 'export_excel', methods: ['GET'])]
    public function exportExcel(
        MeasureRepository $measureRepository,
        EntityManagerInterface $em,
        TranslatableListener $translatableListener
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $translatableListener->setTranslatableLocale('es');
        $measures = $measureRepository->findAllForExport();
        $catalog = $this->buildExportCatalogFromMeasures($measures);
        $translationsByMeasure = $this->loadMeasureEnglishTranslations($em);

        $spreadsheet = $this->measureTemplateExporter->buildMeasuresSpreadsheet(
            $catalog,
            $measures,
            static function (Measure $measure) use ($translationsByMeasure): array {
                return $measure->getId() !== null && isset($translationsByMeasure[$measure->getId()])
                    ? $translationsByMeasure[$measure->getId()]
                    : [];
            }
        );

        $response = new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        });

        $filename = sprintf(
            'medidas_catalogo_completo_%s.xlsx',
            (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Ymd')
        );
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

    /**
     * @param array<int, Measure> $measures
     * @return array{
     *     protocols: array<int, object>,
     *     measureBlocks: array<int, object>,
     *     categories: array<int, object>,
     *     categoryGhgs: array<int, object>,
     *     departments: array<int, object>,
     *     ods: array<int, object>,
     *     esg: array<int, object>,
     *     scopes: array<int, object>,
     *     impactAreas: array<int, object>,
     *     verificationSources: array<int, object>,
     *     tripleBalanceAxes: array<int, object>
     * }
     */
    private function buildExportCatalogFromMeasures(array $measures): array
    {
        $catalog = [
            'protocols' => [],
            'measureBlocks' => [],
            'categories' => [],
            'categoryGhgs' => [],
            'departments' => [],
            'ods' => [],
            'esg' => [],
            'scopes' => [],
            'impactAreas' => [],
            'verificationSources' => [],
            'tripleBalanceAxes' => [],
        ];

        foreach ($measures as $measure) {
            $this->addEntityToCatalog($catalog['protocols'], $measure->getProtocol());
            $this->addEntityToCatalog($catalog['measureBlocks'], $measure->getMeasureBlock());
            $this->addEntityToCatalog($catalog['categories'], $measure->getCategory());
            $this->addEntityToCatalog($catalog['categoryGhgs'], $measure->getCategoryGhg());
            $this->addEntityToCatalog($catalog['ods'], $measure->getOds());
            $this->addEntityToCatalog($catalog['esg'], $measure->getEsg());
            $this->addEntityToCatalog($catalog['scopes'], $measure->getScope());

            foreach ($measure->getResolvedDepartments() as $department) {
                $this->addEntityToCatalog($catalog['departments'], $department);
            }

            foreach ($measure->getResolvedOdsItems() as $odsItem) {
                $this->addEntityToCatalog($catalog['ods'], $odsItem);
            }

            foreach ($measure->getResolvedImpactAreas() as $impactArea) {
                $this->addEntityToCatalog($catalog['impactAreas'], $impactArea);
            }

            foreach ($measure->getResolvedTripleBalanceAxes() as $axis) {
                $this->addEntityToCatalog($catalog['tripleBalanceAxes'], $axis);
            }

            foreach ($measure->getResolvedVerificationSourceLinks() as $link) {
                $source = $link->getVerificationSource();
                if ($source) {
                    $this->addEntityToCatalog($catalog['verificationSources'], $source);
                }
            }
        }

        return array_map(static fn (array $items): array => array_values($items), $catalog);
    }

    /**
     * @param array<int, object> $catalog
     */
    private function addEntityToCatalog(array &$catalog, ?object $entity): void
    {
        if (!$entity || !method_exists($entity, 'getId')) {
            return;
        }

        $id = $entity->getId();
        if ($id === null) {
            return;
        }

        $catalog[(string) $id] = $entity;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function loadMeasureEnglishTranslations(EntityManagerInterface $em): array
    {
        $rows = $em->getConnection()->fetchAllAssociative(
            'SELECT foreign_key, field, content FROM ext_translations WHERE object_class = :class AND locale = :locale',
            [
                'class' => Measure::class,
                'locale' => 'en',
            ]
        );

        $translations = [];
        foreach ($rows as $row) {
            $measureId = (int) ($row['foreign_key'] ?? 0);
            $field = (string) ($row['field'] ?? '');
            if ($measureId <= 0 || $field === '') {
                continue;
            }

            $translations[$measureId][$field] = (string) ($row['content'] ?? '');
        }

        return $translations;
    }
}
