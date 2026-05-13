<?php
// src/DataFixtures/MeasureFixtures.php
namespace App\DataFixtures;

use App\Service\MeasureImporter;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MeasureFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly TranslatableListener $translatableListener,
        private readonly MeasureImporter $measureImporter,
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

        // Lista de ficheros a importar
        $files = [
            $baseDir.'/peach_measures.xlsx',               // tu fichero existente de Peach
            $baseDir.'/green_film_measures.xlsx',
            $baseDir.'/albert_measures.xlsx',
            $baseDir.'/be_green_my_film_measures.xlsx',
            $baseDir.'/be_green_my_event_measures.xlsx',
        ];

        foreach ($files as $path) {
            if (!is_file($path)) {
                // Si falta alguno, lanzamos aviso en consola y seguimos
                echo sprintf("⚠️  Measures file not found: %s\n", $path);
                continue;
            }

            $summary = $this->measureImporter->importFile($path);
            echo sprintf(
                "✅ Imported %s | imported=%d, duplicates=%d, errors=%d\n",
                basename($path),
                $summary['imported'] ?? 0,
                $summary['duplicates'] ?? 0,
                $summary['errors'] ?? 0
            );
        }

        // Devuelve fallback si quieres
        $this->translatableListener->setTranslationFallback(true);
    }
}
