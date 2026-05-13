<?php
// src/Service/MeasureImporter.php
namespace App\Service;

use App\Entity\{Measure, Protocol, CategoryGhg, Category, Department, Ods, EsG, Scope};
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\TranslatableListener;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MeasureImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatableListener $translatableListener,
    ) {}

    /**
     * Importa medidas desde un XLSX (ruta absoluta) siguiendo el formato del Admin.
     * Devuelve contadores para mostrar resumen.
     */
    public function importFile(string $absolutePath): array
    {
        // Forzamos base ES
        $this->translatableListener->setTranslatableLocale('es');

        $protocolRepo = $this->em->getRepository(Protocol::class);
        $ghgRepo      = $this->em->getRepository(CategoryGhg::class);
        $catRepo      = $this->em->getRepository(Category::class);
        $deptRepo     = $this->em->getRepository(Department::class);
        $odsRepo      = $this->em->getRepository(Ods::class);
        $esgRepo      = $this->em->getRepository(EsG::class);
        $scopeRepo    = $this->em->getRepository(Scope::class);
        $measureRepo  = $this->em->getRepository(Measure::class);

        /** @var TranslationRepository $tr */
        $tr = $this->em->getRepository(\Gedmo\Translatable\Entity\Translation::class);

        $imported = 0;
        $errors = 0;
        $duplicates = 0;
        $invalidProtocols = 0;
        $invalidGhgs = 0;
        $invalidCategories = 0;
        $invalidDepartments = 0;
        $invalidOds = 0;
        $invalidEsg = 0;
        $invalidScopes = 0;

        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        foreach ($rows as $index => $row) {
            if ($index === 1) { continue; } // encabezado
            if (empty(array_filter($row, fn($v) => trim((string)($v ?? '')) !== ''))) {
                continue; // fila vacía
            }

            // === Lectura columnas (ver mapeo en el encabezado de este archivo) ===
            $nameEs      = trim($row['A'] ?? '');
            $nameRevEs   = trim($row['B'] ?? ''); // NUEVO (pasado ES)
            $descEs      = trim($row['C'] ?? '');
            $implEs      = trim($row['D'] ?? '');
            $protName    = trim($row['E'] ?? '');
            $ghgName     = trim($row['F'] ?? '');
            $catName     = trim($row['G'] ?? '');
            $depName     = trim($row['H'] ?? '');
            $verifEs     = trim($row['I'] ?? '');
            $odsName     = trim($row['J'] ?? '');
            $esgName     = trim($row['K'] ?? '');
            $scopeName   = trim($row['L'] ?? '');
            $scoreVal    = trim($row['M'] ?? '');
            $mandStr     = trim($row['N'] ?? '');
            $nameEn      = trim($row['O'] ?? '');
            $nameRevEn   = trim($row['P'] ?? ''); // NUEVO (pasado EN)
            $descEn      = trim($row['Q'] ?? '');
            $implEn      = trim($row['R'] ?? '');
            $verifEn     = trim($row['S'] ?? '');

            if ($nameEs === '') { $errors++; continue; }

            // Relaciones (todas en ES)
            $protocol = null;
            if ($protName !== '') {
                $protocol = $protocolRepo->findOneBy(['name' => $protName]);
                if (!$protocol) { $invalidProtocols++; $errors++; continue; }
            }

            $categoryGhg = null;
            if ($ghgName !== '') {
                $categoryGhg = $ghgRepo->findOneBy(['name' => $ghgName]);
                if (!$categoryGhg) { $invalidGhgs++; $errors++; continue; }
            }

            $category = null;
            if ($catName !== '') {
                $category = $catRepo->findOneBy(['name' => $catName]);
                if (!$category) { $invalidCategories++; $errors++; continue; }
            }

            $department = null;
            if ($depName !== '') {
                $department = $deptRepo->findOneBy(['name' => $depName]);
                if (!$department) { $invalidDepartments++; $errors++; continue; }
            }

            $ods = null;
            if ($odsName !== '') {
                $ods = $odsRepo->findOneBy(['name' => $odsName]);
                if (!$ods) { $invalidOds++; $errors++; continue; }
            }

            $esg = null;
            if ($esgName !== '') {
                $esg = $esgRepo->findOneBy(['name' => $esgName]);
                if (!$esg) { $invalidEsg++; $errors++; continue; }
            }

            $scope = null;
            if ($scopeName !== '') {
                $scope = $scopeRepo->findOneBy(['name' => $scopeName]);
                if (!$scope) { $invalidScopes++; $errors++; continue; }
            }

            // Duplicado (mismo criterio que ya tenías)
            $existing = $measureRepo->findOneBy([
                'name'       => $nameEs,
                'category'   => $category,
                'protocol'   => $protocol,
                'department' => $department
            ]);
            if ($existing) { $duplicates++; continue; }

            // Score / Mandatory
            $score = null;
            if ($scoreVal !== '' && is_numeric($scoreVal)) {
                $score = max(0, min(100, (int) round((float)$scoreVal)));
            }
            $mandatory = null;
            if ($mandStr !== '') {
                $norm = mb_strtolower($mandStr);
                $truthy = ['sí','si','yes','y','true','1'];
                $falsy  = ['no','n','false','0'];
                $mandatory = in_array($norm, $truthy, true) ? true : (in_array($norm, $falsy, true) ? false : null);
            }

            // === Crear medida base (ES) ===
            $measure = (new Measure())
                ->setName($nameEs)
                ->setDescription($descEs)
                ->setImplementation($implEs)
                ->setVerificationSources($verifEs)
                ->setProtocol($protocol)
                ->setCategoryGhg($categoryGhg)
                ->setCategory($category)
                ->setDepartment($department)
                ->setOds($ods)
                ->setEsg($esg)
                ->setScope($scope);

            if ($nameRevEs !== '') {
                // requiere: get/setNameReview() en Measure
                $measure->setNameReview($nameRevEs);
            }

            if ($score !== null) { $measure->setScore($score); }
            if ($mandatory !== null) { $measure->setMandatory($mandatory); }

            $this->em->persist($measure);
            $this->em->flush(); // asegurar ID para traducciones

            // === Traducciones EN opcionales ===
            if ($nameEn   !== '') { $tr->translate($measure, 'name', 'en', $nameEn); }
            if ($nameRevEn!== '') { $tr->translate($measure, 'nameReview', 'en', $nameRevEn); } // NUEVO
            if ($descEn   !== '') { $tr->translate($measure, 'description', 'en', $descEn); }
            if ($implEn   !== '') { $tr->translate($measure, 'implementation', 'en', $implEn); }
            if ($verifEn  !== '') { $tr->translate($measure, 'verificationSources', 'en', $verifEn); }

            $imported++;
        }

        $this->em->flush();

        return [
            'imported'           => $imported,
            'duplicates'         => $duplicates,
            'errors'             => $errors,
            'invalidProtocols'   => $invalidProtocols,
            'invalidGhgs'        => $invalidGhgs,
            'invalidCategories'  => $invalidCategories,
            'invalidDepartments' => $invalidDepartments,
            'invalidOds'         => $invalidOds,
            'invalidEsg'         => $invalidEsg,
            'invalidScopes'      => $invalidScopes,
        ];
    }
}
