<?php

namespace App\Controller\Admin;

use App\Entity\{Measure, Category, Department, Protocol, Ods, EsG, Scope, CategoryGhg};
use App\Form\{MeasureType, MeasureImportType};
use Dompdf\{Dompdf, Options};
use App\Repository\{MeasureRepository, ProtocolRepository, CategoryGhgRepository, CategoryRepository, DepartmentRepository, OdsRepository, EsGRepository, ScopeRepository};
use App\Service\MeasureCatalogAdminService;
use App\Service\MeasureTaxonomyPresenter;
use App\Service\PlanMeasureCatalogResolver;
use App\Service\MeasureImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse, ResponseHeaderBag, File\Exception\FileException};
use Symfony\Component\Routing\Annotation\Route;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

use Symfony\Contracts\Translation\TranslatorInterface;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\Form\FormError;

#[Route('/admin/measures', name: 'admin_measures_')]
class AdminMeasureController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator,
        private MeasureImporter $measureImporter,
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
        $fields  = ['name','nameReview','description','implementation'];

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
        $fields  = ['name','nameReview','description','implementation'];

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
        EntityManagerInterface $em,
        TranslatorInterface $t,
        TranslatableListener $translatableListener
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
            /** @var MeasureImporter $importer */
            $summary = $this->measureImporter->importFile($file->getPathname());

            $this->addFlash('success', $t->trans('backend.measures.import.summary', [
                '%imported%'           => $summary['imported'],
                '%duplicates%'         => $summary['duplicates'],
                '%errors%'             => $summary['errors'],
                '%invalidProtocols%'   => $summary['invalidProtocols'],
                '%invalidGhgs%'        => $summary['invalidGhgs'],
                '%invalidCategories%'  => $summary['invalidCategories'],
                '%invalidDepartments%' => $summary['invalidDepartments'],
                '%invalidOds%'         => $summary['invalidOds'],
                '%invalidEsg%'         => $summary['invalidEsg'],
                '%invalidScopes%'      => $summary['invalidScopes'],
            ]));
        } catch (\Throwable $e) {
            $this->addFlash('danger', $t->trans('backend.measures.flash.import_error', [
                '%msg%' => $e->getMessage()
            ]));
        }

        return $this->redirectToRoute('admin_measures_index');
    }

   #[Route('/template/download', name: 'template_download')]
    public function downloadTemplate(
        ProtocolRepository     $protocolRepo,
        CategoryGhgRepository  $ghgRepo,
        CategoryRepository     $categoryRepo,
        DepartmentRepository   $departmentRepo,
        OdsRepository          $odsRepo,
        EsGRepository          $esgRepo,
        ScopeRepository        $scopeRepo,
        TranslatorInterface    $translator,
        TranslatableListener   $translatableListener,
    ): Response {
        // 1) Forzar SIEMPRE ES
        $translatableListener->setTranslatableLocale('es');
        $tEs = fn(string $k, array $p = []) => $translator->trans($k, $p, 'messages', 'es');

        // 2) Spreadsheet base
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($tEs('backend.measures.template.sheet_title'));

        // 3) Encabezados (A–N ES, O–S EN opcional)
        $headers = [
            $tEs('backend.measures.template.headers.name'),                  // A
            $tEs('backend.measures.template.headers.name_review'),           // B  (NUEVO)
            $tEs('backend.measures.template.headers.description'),           // C
            $tEs('backend.measures.template.headers.implementation'),        // D
            $tEs('backend.measures.template.headers.protocol'),              // E
            $tEs('backend.measures.template.headers.ghg_category'),          // F
            $tEs('backend.measures.template.headers.category'),              // G
            $tEs('backend.measures.template.headers.department'),            // H
            $tEs('backend.measures.template.headers.verification_sources'),  // I
            $tEs('backend.measures.template.headers.ods'),                   // J
            $tEs('backend.measures.template.headers.esg'),                   // K
            $tEs('backend.measures.template.headers.scope'),                 // L
            $tEs('backend.measures.template.headers.score'),                 // M
            $tEs('backend.measures.template.headers.mandatory'),             // N
            $tEs('backend.measures.template.headers.name_en'),               // O (opcional)
            $tEs('backend.measures.template.headers.name_review_en'),        // P (opcional) NUEVO
            $tEs('backend.measures.template.headers.description_en'),        // Q (opcional)
            $tEs('backend.measures.template.headers.implementation_en'),     // R (opcional)
            $tEs('backend.measures.template.headers.verification_sources_en')// S (opcional)
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:S1')->getFont()->setBold(true);

        // 4) Listas SIEMPRE en ES
        $protocols   = $protocolRepo->findAll();
        $ghgs        = $ghgRepo->findAll();
        $categories  = $categoryRepo->findAll();
        $departments = $departmentRepo->findAll();
        $odsList     = $odsRepo->findAll();
        $esgs        = $esgRepo->findAll();
        $scopes      = $scopeRepo->findAll();

        // Hoja oculta de listas
        $listSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $tEs('backend.measures.template.lists_sheet'));
        $spreadsheet->addSheet($listSheet);

        $lists = [
            'A' => array_map(fn($e) => $e->getName(), $protocols),
            'B' => array_map(fn($e) => $e->getName(), $ghgs),
            'C' => array_map(fn($e) => $e->getName(), $categories),
            'D' => array_map(fn($e) => $e->getName(), $departments),
            'E' => array_map(fn($e) => $e->getName(), $odsList),
            'F' => array_map(fn($e) => $e->getName(), $esgs),
            'G' => array_map(fn($e) => $e->getName(), $scopes),
        ];
        foreach ($lists as $col => $values) {
            foreach ($values as $i => $val) {
                $listSheet->setCellValue("{$col}" . ($i + 1), $val);
            }
        }
        $listSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // 5) Fila de ejemplo
        $sheet->fromArray([
            $tEs('backend.measures.template.example.name'),                 // A
            '',                                                            // B  name_review (pasado)
            $tEs('backend.measures.template.example.description'),          // C
            $tEs('backend.measures.template.example.implementation'),       // D
            $lists['A'][0] ?? '',                                          // E Protocolo
            $lists['B'][0] ?? '',                                          // F GHG
            $lists['C'][0] ?? '',                                          // G Categoría
            $lists['D'][0] ?? '',                                          // H Depto
            $tEs('backend.measures.template.example.verification_sources'), // I
            $lists['E'][0] ?? '',                                          // J ODS
            $lists['F'][0] ?? '',                                          // K ESG
            $lists['G'][0] ?? '',                                          // L Alcance
            50,                                                            // M Puntuación
            $tEs('backend.common.no'),                                     // N Obligatoria
            '', '', '', '', ''                                             // O–S EN opcionales
        ], null, 'A2');

        // 6) Validaciones de listas (en ES) -> columnas nuevas
        $map = [
            'E' => ['A', count($lists['A'])], // Protocolo
            'F' => ['B', count($lists['B'])], // GHG
            'G' => ['C', count($lists['C'])], // Categoría
            'H' => ['D', count($lists['D'])], // Departamento
            'J' => ['E', count($lists['E'])], // ODS
            'K' => ['F', count($lists['F'])], // ESG
            'L' => ['G', count($lists['G'])], // Alcance
        ];
        foreach ($map as $column => [$listCol, $count]) {
            for ($row = 2; $row <= 1000; $row++) {
                $sheet->getCell("{$column}{$row}")
                    ->setDataValidation(
                        (new \PhpOffice\PhpSpreadsheet\Cell\DataValidation())
                            ->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)
                            ->setFormula1("'" . $listSheet->getTitle() . "'!\${$listCol}\$1:\${$listCol}\$$count")
                            ->setAllowBlank(true)
                            ->setShowDropDown(true)
                    );
            }
        }

        // 7) Validación numérica para Puntuación (M)
        for ($row = 2; $row <= 1000; $row++) {
            $dvScore = (new \PhpOffice\PhpSpreadsheet\Cell\DataValidation())
                ->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_WHOLE)
                ->setOperator(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN)
                ->setFormula1('0')
                ->setFormula2('100')
                ->setAllowBlank(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle($tEs('backend.measures.template.score_error_title'))
                ->setError($tEs('backend.measures.template.score_error_text'));
            $sheet->getCell("M{$row}")->setDataValidation($dvScore);
        }

        // 8) Validación para Obligatoria (N) -> Sí/No ES
        $yes = $tEs('backend.common.yes');
        $no  = $tEs('backend.common.no');
        $listYN = '"' . $yes . ',' . $no . '"';
        for ($row = 2; $row <= 1000; $row++) {
            $dvMandatory = (new \PhpOffice\PhpSpreadsheet\Cell\DataValidation())
                ->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST)
                ->setFormula1($listYN)
                ->setAllowBlank(true)
                ->setShowDropDown(true);
            $sheet->getCell("N{$row}")->setDataValidation($dvMandatory);
        }

        // 9) Descargar XLSX
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($spreadsheet) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        });

        $filename = $tEs('backend.measures.template.filename');
        $disposition = $response->headers->makeDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
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
