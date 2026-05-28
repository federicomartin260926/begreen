<?php
// src/DataFixtures/MeasureFixtures.php
namespace App\DataFixtures;

use App\Service\MeasureTemplateV23Importer;
use App\Service\MeasureTemplateV23Parser;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MeasureFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly TranslatableListener $translatableListener,
        private readonly MeasureTemplateV23Parser $measureTemplateParser,
        private readonly MeasureTemplateV23Importer $measureTemplateImporter,
        private readonly ParameterBagInterface $params,
    ) {}

    public static function getGroups(): array
    {
        return ['measures'];
    }

    public function getDependencies(): array
    {
        return [AuxiliaryFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        @ini_set('memory_limit', '1024M'); // o '512M' si prefieres
        @set_time_limit(0);
        gc_enable();

        // Base ES para escritura
        $this->translatableListener->setTranslatableLocale('es');
        $this->translatableListener->setTranslationFallback(false);

        $baseDir = rtrim($this->params->get('kernel.project_dir'), '/').'/public/fixtures';

        $path = $baseDir.'/be_green_my_film_measures.xlsx';
        if (!is_file($path)) {
            echo sprintf("⚠️  Measures file not found: %s\n", $path);
            $this->translatableListener->setTranslationFallback(true);
            return;
        }

        $report = $this->measureTemplateParser->parseFile($path);
        $report = $this->measureTemplateImporter->import($report, true, false);
        $summary = $report->getImportSummary();

        echo sprintf(
            "✅ Imported %s | imported=%d, updated=%d, errors=%d\n",
            basename($path),
            $summary['imported'] ?? 0,
            $summary['updated'] ?? 0,
            $summary['errors'] ?? 0
        );

        // Devuelve fallback si quieres
        $this->translatableListener->setTranslationFallback(true);
    }
}
