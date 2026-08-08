<?php

namespace App\Controller\Admin;

use App\Entity\EmissionActivity;
use App\Form\EmissionActivityType;
use App\Entity\EmissionRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\PdfService;
use App\Entity\Category;
use App\Entity\CategoryGhg;
use App\Entity\EmissionSource;
use App\Form\EmissionSourceType;
use App\Form\ImportExcelType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Repository\CategoryRepository;
use App\Repository\CategoryGhgRepository;
use App\Repository\EmissionActivityRepository;
use App\Repository\EmissionSourceRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\TranslatableListener;

#[Route('/admin/emission-activity', name: 'admin_emission_activity_')]
class EmissionActivityController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $activities = $em->getRepository(EmissionActivity::class)->findAll();
        $importForm = $this->createForm(ImportExcelType::class);
        $fuentes = $em->getRepository(EmissionSource::class)->findBy([], ['name' => 'ASC', 'year' => 'DESC']);

        return $this->render('admin/emission_activity/index.html.twig', [
            'activities' => $activities,
            'importForm' => $importForm->createView(),
            'fuentes' => $fuentes,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $t,
        TranslatableListener $translatableListener
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $displayLocale = $request->getLocale();   // EN si la UI está en inglés
        $locales       = ['en'];                  // añade más si procede
        $fields        = ['name','unit'];

        // 1) Mostrar selects en idioma de la UI
        $translatableListener->setTranslatableLocale($displayLocale);

        $activity = new EmissionActivity();

        $form = $this->createForm(EmissionActivityType::class, $activity, [
            'locales'             => array_merge(['es'], $locales),
            'default_locale'      => 'es',
            'translatable_fields' => $fields,
            'translations'        => [],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // EmissionSource (igual que tenías)
            $sourceName = $form->get('sourceName')->getData();
            $sourceYear = $form->get('sourceYear')->getData();
            $source = $em->getRepository(EmissionSource::class)->findOneBy([
                'name' => $sourceName,
                'year' => $sourceYear,
            ]);
            if (!$source) {
                $source = new EmissionSource();
                $source->setName($sourceName);
                $source->setYear($sourceYear);
                $em->persist($source);
            }
            $activity->setEmissionSource($source);

            // 2) Guardar base en ES
            $translatableListener->setTranslatableLocale('es');
            $em->persist($activity);
            $em->flush(); // necesitamos ID

            /** @var TranslationRepository $tr */
            $tr = $em->getRepository(Translation::class);

            // 3) Guardar traducciones de locales no-ES
            foreach ($locales as $loc) {
                foreach ($fields as $f) {
                    $fieldName = $f . '_' . $loc;
                    if (!$form->has($fieldName)) {
                        continue;
                    }
                    $val = (string) ($form->get($fieldName)->getData() ?? '');
                    if ($val !== '') {
                        $tr->translate($activity, $f, $loc, $val);
                    }
                }
            }

            $em->flush();

            $this->addFlash('success', $t->trans('backend.admin.emission.activity.flash.created'));
            return $this->redirectToRoute('admin_emission_activity_index');
        }

        return $this->render('admin/emission_activity/form.html.twig', [
            'form' => $form->createView(),
            'edit' => false,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        EmissionActivity $activity,
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $t,
        TranslatableListener $translatableListener
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        // Config locales/fields
        $locales = ['en']; // añade más si procede
        $fields  = ['name','unit'];

        /** @var TranslationRepository $tr */
        $tr = $em->getRepository(Translation::class);

        // 1) Cargar SIEMPRE la entidad en ES (base)
        $translatableListener->setTranslatableLocale('es');
        $em->refresh($activity); // asegura el contexto en ES

        $existingTranslations = $tr->findTranslations($activity);

        // 2) Si es GET, cambiamos a locale visible SOLO para pintar selects traducidos
        if ($request->isMethod('GET')) {
            $translatableListener->setTranslatableLocale($request->getLocale());
        } else {
            // En POST nos aseguramos de estar en ES antes de bindear datos base
            $translatableListener->setTranslatableLocale('es');
        }

        $form = $this->createForm(EmissionActivityType::class, $activity, [
            'locales'             => array_merge(['es'], $locales),
            'default_locale'      => 'es',
            'translatable_fields' => $fields,
            'translations'        => $existingTranslations,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Upsert traducciones NO-ES desde los campos unmapped
            foreach ($locales as $loc) {
                foreach ($fields as $f) {
                    $fieldName = $f . '_' . $loc;
                    if (!$form->has($fieldName)) {
                        continue;
                    }
                    $val = (string) ($form->get($fieldName)->getData() ?? '');
                    if ($val !== '') {
                        $tr->translate($activity, $f, $loc, $val);
                    } else {
                        if (!empty($existingTranslations[$loc][$f])) {
                            $em->createQuery('DELETE FROM Gedmo\\Translatable\\Entity\\Translation t
                                            WHERE t.objectClass = :cls AND t.field = :field
                                                AND t.foreignKey = :fk AND t.locale = :loc')
                            ->setParameters([
                                'cls'   => EmissionActivity::class,
                                'field' => $f,
                                'fk'    => $activity->getId(),
                                'loc'   => $loc,
                            ])->execute();
                        }
                    }
                }
            }

            $em->flush();

            $this->addFlash('success', $t->trans('backend.admin.emission.activity.flash.updated'));
            return $this->redirectToRoute('admin_emission_activity_index');
        }

        return $this->render('admin/emission_activity/form.html.twig', [
            'form' => $form->createView(),
            'edit' => true,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(EmissionActivity $activity, Request $request, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        if ($this->isCsrfTokenValid('delete_emission_activity_' . $activity->getId(), $request->request->get('_token'))) {
            $records = $em->getRepository(EmissionRecord::class)->findBy(['activity' => $activity]);
            if (!empty($records)) {
                $this->addFlash(
                    'error',
                    $t->trans('backend.admin.emission.activity.flash.delete_blocked_has_records', [
                        '%name%' => $activity->getName(),
                    ])
                );
                return $this->redirectToRoute('admin_emission_activity_index');
            }

            $em->remove($activity);
            $em->flush();
            $this->addFlash('success', $t->trans('backend.admin.emission.activity.flash.deleted'));
        }

        return $this->redirectToRoute('admin_emission_activity_index');
    }

    #[Route('/import', name: 'import', methods: ['POST'])]
    public function import(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $t,
        TranslatableListener $translatableListener
    ): Response {
        $form = $this->createForm(ImportExcelType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $spreadsheet = IOFactory::load($file->getPathname());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            $imported = 0;
            $errors = 0;
            $duplicates = 0;
            $invalidCategories = 0;
            $invalidGhgs = 0;
            $invalidSubcats = 0;

            // Lista canónica de subcategorías
            $validSubcats = [
                'electricidad','remoto','animacion','montaje_edicion','almacenamiento',
                'gas_generador','gas_caldera','gas_propano','gas_bombona',
                'aereo','carretera','ferroviario','maritimo','otros','madera',
            ];

            /** @var \Gedmo\Translatable\Entity\Repository\TranslationRepository $tr */
            $tr = $em->getRepository(Translation::class);

            foreach ($rows as $index => $row) {
                if ($index === 1) continue; // Encabezado

                // Ignorar filas completamente vacías
                if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) {
                    continue;
                }

                // Columnas (plantilla SIEMPRE en ES)
                // A: Categoría (ES)
                // B: Categoría GHG (ES)
                // C: Subcategoría (código canónico) [opcional]
                // D: Nombre (ES)
                // E: Unidad (ES)
                // F: Factor
                // G: Fuente
                // H: Año
                // I: Nombre (EN) [opcional]
                // J: Unidad (EN) [opcional]

                $categoryNameEs = trim((string)($row['A'] ?? ''));
                $ghgNameEs      = trim((string)($row['B'] ?? ''));
                $subcategory    = trim((string)($row['C'] ?? ''));
                $nameEs         = trim((string)($row['D'] ?? ''));
                $unitEs         = trim((string)($row['E'] ?? ''));
                $factor         = is_numeric($row['F'] ?? null) ? (float)$row['F'] : null;
                $sourceName     = trim((string)($row['G'] ?? ''));
                $sourceYear     = is_numeric($row['H'] ?? null) ? (int)$row['H'] : null;
                $nameEn         = trim((string)($row['I'] ?? ''));
                $unitEn         = trim((string)($row['J'] ?? ''));

                // Validaciones mínimas (ES base)
                if (!$categoryNameEs || !$ghgNameEs || !$nameEs || !$unitEs || $factor === null || !$sourceName || !$sourceYear) {
                    $errors++;
                    continue;
                }

                // Validar subcategoría si viene
                if ($subcategory !== '' && !in_array($subcategory, $validSubcats, true)) {
                    $invalidSubcats++;
                    continue;
                }

                // Resolver SIEMPRE por ES (nombre base)
                $category = $em->getRepository(Category::class)->findOneBy(['name' => $categoryNameEs]);
                if (!$category) { $invalidCategories++; continue; }

                $categoryGhg = $em->getRepository(CategoryGhg::class)->findOneBy(['name' => $ghgNameEs]);
                if (!$categoryGhg) { $invalidGhgs++; continue; }

                // Fuente (nombre + año)
                $source = $em->getRepository(EmissionSource::class)->findOneBy([
                    'name' => $sourceName,
                    'year' => $sourceYear,
                ]);
                if (!$source) {
                    $source = new EmissionSource();
                    $source->setName($sourceName);
                    $source->setYear($sourceYear);
                    $em->persist($source);
                    // No es necesario flush aquí
                }

                // Duplicado (base ES)
                $existing = $em->getRepository(EmissionActivity::class)->findOneBy([
                    'name'           => $nameEs,
                    'unit'           => $unitEs,
                    'category'       => $category,
                    'emissionSource' => $source,
                    'subcategory'    => $subcategory !== '' ? $subcategory : null,
                ]);
                if ($existing) {
                    $duplicates++;
                    continue;
                }

                // Forzar ES para persistir base (por claridad)
                $translatableListener->setTranslatableLocale('es');

                // Crear actividad base ES
                $activity = new EmissionActivity();
                $activity->setName($nameEs);
                $activity->setUnit($unitEs);
                $activity->setEmissionFactor($factor);
                $activity->setCategory($category);
                $activity->setCategoryGhg($categoryGhg);
                $activity->setEmissionSource($source);
                $activity->setSubcategory($subcategory !== '' ? $subcategory : null);

                $em->persist($activity);
                $em->flush(); // necesitamos ID para traducir

                // Traducciones EN opcionales
                if ($nameEn !== '') {
                    $tr->translate($activity, 'name', 'en', $nameEn);
                }
                if ($unitEn !== '') {
                    $tr->translate($activity, 'unit', 'en', $unitEn);
                }

                $imported++;
            }

            $em->flush();

            // Resumen (puedes añadir %invalidSubcats% si creas la clave i18n)
            $this->addFlash(
                'success',
                $t->trans('backend.admin.emission.activity.import.summary', [
                    '%imported%'           => $imported,
                    '%duplicates%'         => $duplicates,
                    '%errors%'             => $errors,
                    '%invalidCategories%'  => $invalidCategories,
                    '%invalidGhgs%'        => $invalidGhgs,
                    '%invalidSubcats%'   => $invalidSubcats, // si lo quieres mostrar
                ])
            );
        } else {
            $this->addFlash('error', $t->trans('backend.admin.emission.activity.import.form_error'));
        }

        return $this->redirectToRoute('admin_emission_activity_index');
    }

    #[Route('/template/download', name: 'template_download', methods: ['GET'])]
    public function downloadTemplate(
        EntityManagerInterface $em,
        TranslatorInterface $t,
    ): Response {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Fuerza textos en ES (sin depender del locale de la UI)
        $sheet->setTitle($t->trans('backend.admin.emission.template.sheet_title', [], 'messages', 'es'));

        // Códigos canónicos de subcategorías (lista completa/estable)
        $subcategories = [
            'electricidad',
            'remoto',
            'animacion',
            'montaje_edicion',
            'almacenamiento',
            'gas_generador',
            'gas_caldera',
            'gas_propano',
            'gas_bombona',
            'aereo',
            'carretera',
            'ferroviario',
            'maritimo',
            'otros',
            'madera',
        ];

        // Encabezados en ES + columnas EN opcionales al final
        $headers = [
            $t->trans('backend.admin.emission.template.headers.category',   [], 'messages', 'es'), // A
            $t->trans('backend.admin.emission.template.headers.ghg_category', [], 'messages', 'es'), // B
            $t->trans('backend.admin.emission.template.headers.subcategory', [], 'messages', 'es'), // C (código)
            $t->trans('backend.admin.emission.template.headers.name',      [], 'messages', 'es'), // D (ES)
            $t->trans('backend.admin.emission.template.headers.unit',      [], 'messages', 'es'), // E (ES)
            $t->trans('backend.admin.emission.template.headers.factor',    [], 'messages', 'es'), // F
            $t->trans('backend.admin.emission.template.headers.source',    [], 'messages', 'es'), // G
            $t->trans('backend.admin.emission.template.headers.year',      [], 'messages', 'es'), // H
            $t->trans('backend.admin.emission.template.headers.name_en',   [], 'messages', 'es'), // I (EN opcional)
            $t->trans('backend.admin.emission.template.headers.unit_en',   [], 'messages', 'es'), // J (EN opcional)
        ];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);

        // Nombres base (ES) por consulta escalar (ignora Gedmo y locales)
        $categoryNames = array_column(
            $em->createQuery('SELECT c.name FROM ' . Category::class . ' c ORDER BY c.name ASC')
            ->getArrayResult(),
            'name'
        );
        $ghgNames = array_column(
            $em->createQuery('SELECT g.name FROM ' . CategoryGhg::class . ' g ORDER BY g.name ASC')
            ->getArrayResult(),
            'name'
        );

        $exampleCategory    = $categoryNames[0] ?? $t->trans('backend.admin.emission.template.examples.category', [], 'messages', 'es');
        $exampleGhg         = $ghgNames[0] ?? $t->trans('backend.admin.emission.template.examples.ghg_category', [], 'messages', 'es');
        $exampleSubcategory = $subcategories[0];

        // Fila de ejemplo
        $sheet->fromArray(
            [
                $exampleCategory,                                                               // A
                $exampleGhg,                                                                    // B
                $exampleSubcategory,                                                            // C (código)
                $t->trans('backend.admin.emission.template.examples.sample_name',   [], 'messages', 'es'), // D ES
                'km',                                                                           // E ES
                0.192,                                                                          // F
                'MITECO',                                                                       // G
                date('Y'),                                                                      // H
                $t->trans('backend.admin.emission.template.examples.sample_name_en', [], 'messages', 'es'), // I EN ejemplo
                'km',                                                                           // J EN ejemplo
            ],
            null,
            'A2'
        );

        // Hoja oculta con listas (en ES)
        $listSheet = new Worksheet($spreadsheet, 'Listas');
        $spreadsheet->addSheet($listSheet);

        // Categorías (ES)
        foreach ($categoryNames as $i => $catName) {
            $listSheet->setCellValue('A' . ($i + 1), $catName);
        }
        // GHG (ES)
        foreach ($ghgNames as $i => $ghgName) {
            $listSheet->setCellValue('B' . ($i + 1), $ghgName);
        }
        // Subcategorías (CÓDIGOS canónicos)
        foreach ($subcategories as $i => $subcat) {
            $listSheet->setCellValue('C' . ($i + 1), $subcat);
        }
        $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $lastCatRow = max(1, count($categoryNames));
        $lastGhgRow = max(1, count($ghgNames));
        $lastSubRow = max(1, count($subcategories));

        // Validación Categoría (A)
        for ($row = 2; $row <= 1000; $row++) {
            $dv = (new DataValidation())
                ->setType(DataValidation::TYPE_LIST)
                ->setFormula1("'Listas'!\$A\$1:\$A\$" . $lastCatRow)
                ->setAllowBlank(false)
                ->setShowDropDown(true);
            $sheet->getCell("A$row")->setDataValidation($dv);
        }

        // Validación GHG (B)
        for ($row = 2; $row <= 1000; $row++) {
            $dv = (new DataValidation())
                ->setType(DataValidation::TYPE_LIST)
                ->setFormula1("'Listas'!\$B\$1:\$B\$" . $lastGhgRow)
                ->setAllowBlank(false)
                ->setShowDropDown(true);
            $sheet->getCell("B$row")->setDataValidation($dv);
        }

        // Validación Subcategoría (C) — por códigos
        for ($row = 2; $row <= 1000; $row++) {
            $dv = (new DataValidation())
                ->setType(DataValidation::TYPE_LIST)
                ->setFormula1("'Listas'!\$C\$1:\$C\$" . $lastSubRow)
                ->setAllowBlank(true)
                ->setShowDropDown(true);
            $sheet->getCell("C$row")->setDataValidation($dv);
        }

        // Validación Fuente (G)
        for ($row = 2; $row <= 1000; $row++) {
            $dv = (new DataValidation())
                ->setType(DataValidation::TYPE_LIST)
                ->setFormula1('"MITECO,DEFRA"')
                ->setAllowBlank(false)
                ->setShowDropDown(true);
            $sheet->getCell("G$row")->setDataValidation($dv);
        }

        // Validación Año (H)
        for ($row = 2; $row <= 1000; $row++) {
            $dv = (new DataValidation())
                ->setType(DataValidation::TYPE_WHOLE)
                ->setOperator(DataValidation::OPERATOR_GREATERTHANOREQUAL)
                ->setFormula1('2000')
                ->setAllowBlank(false);
            $sheet->getCell("H$row")->setDataValidation($dv);
        }

        // Descargar
        $response = new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $t->trans('backend.admin.emission.template.filename', [], 'messages', 'es') // ej. "plantilla_factores_emision.xlsx"
        );

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }


    #[Route('/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(
        Request $request,
        EmissionActivityRepository $repo,
        EmissionSourceRepository $sourceRepo,
        PdfService $pdf,
        TranslatorInterface $t
    ): Response {
        $sourceId = $request->query->get('source');
        $criteria = [];
        $source = null;

        if ($sourceId) {
            $source = $sourceRepo->find($sourceId);
            if ($source) {
                $criteria['emissionSource'] = $source;
            }
        }

        $activities = $repo->findBy($criteria, ['category' => 'ASC']);

        $filename = $t->trans('backend.admin.emission.pdf.filename'); // ej. "factores_emision.pdf"

        return $pdf->renderPdf('admin/emission_activity/pdf.html.twig', [
            'activities' => $activities,
            'sourceName' => $source?->getName(),
            'sourceYear' => $source?->getYear(),
        ], $filename);
    }

    #[Route('/subcategories', name: 'subcategories', methods: ['GET'])]
    public function getSubcategories(
        Request $request,
        EntityManagerInterface $em,
        EmissionActivityRepository $activityRepository,
        TranslatorInterface $t
    ): JsonResponse {
        $categoryId = (int) $request->query->get('categoryId');

        if (!$categoryId) {
            return new JsonResponse(['error' => $t->trans('backend.admin.emission.errors.no_category')], 400);
        }

        // 1) Obtener el nombre base (ES) directamente de la BD (consulta escalar)
        $categoryNameEs = (string) $em->createQuery(
            'SELECT c.name FROM ' . Category::class . ' c WHERE c.id = :id'
        )->setParameter('id', $categoryId)
        ->getSingleScalarResult();

        // 2) Subcategorías fijas del repo (clave ES => código)
        $fixedEs = $activityRepository->getSubcategories($categoryNameEs); // ej: ['Carretera'=>'carretera', ...]

        if (!$fixedEs) {
            return new JsonResponse([], 200);
        }

        // 3) Etiquetas traducidas según locale visible
        $codeToKey = [
            'carretera'       => 'backend.emission.subcat.carretera',
            'ferroviario'     => 'backend.emission.subcat.ferroviario',
            'maritimo'        => 'backend.emission.subcat.maritimo',
            'aereo'           => 'backend.emission.subcat.aereo',
            'otros'           => 'backend.emission.subcat.otros',
            'electricidad'    => 'backend.emission.subcat.electricidad',
            'remoto'          => 'backend.emission.subcat.remoto',
            'animacion'       => 'backend.emission.subcat.animacion',
            'montaje_edicion' => 'backend.emission.subcat.montaje_edicion',
            'almacenamiento'  => 'backend.emission.subcat.almacenamiento',
            'gas_generador'   => 'backend.emission.subcat.gas_generador',
            'gas_caldera'     => 'backend.emission.subcat.gas_caldera',
            'gas_propano'     => 'backend.emission.subcat.gas_propano',
            'gas_bombona'     => 'backend.emission.subcat.gas_bombona',
            'madera'          => 'backend.emission.subcat.madera',
        ];

        $out = [];
        $locale = $request->getLocale();
        foreach ($fixedEs as $_labelEs => $code) {
            $label = $t->trans($codeToKey[$code] ?? $_labelEs, [], 'messages', $locale);
            $out[$label] = $code;
        }

        return new JsonResponse($out);
    }


}
