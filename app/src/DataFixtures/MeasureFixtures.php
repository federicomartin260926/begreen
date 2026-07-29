<?php
// src/DataFixtures/MeasureFixtures.php
namespace App\DataFixtures;

use App\Entity\Measure;
use App\Entity\MeasureBlock;
use App\Entity\Protocol;
use App\Service\MeasureTemplateImporter;
use App\Service\MeasureTemplateParser;
use App\Repository\MeasureBlockRepository;
use App\Repository\ProtocolRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Gedmo\Translatable\TranslatableListener;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MeasureFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const CATALOGS = [
        [
            'protocolCode' => 'be-green-my-film',
            'protocolName' => 'Be Green My Film',
            'filename' => 'be_green_my_film_measures.xlsx',
        ],
        [
            'protocolCode' => 'be-green-my-event',
            'protocolName' => 'Be Green My Event',
            'filename' => 'be_green_my_event_measures.xlsx',
        ],
    ];

    public function __construct(
        private readonly TranslatableListener $translatableListener,
        private readonly MeasureTemplateParser $measureTemplateParser,
        private readonly MeasureTemplateImporter $measureTemplateImporter,
        private readonly ParameterBagInterface $params,
    ) {}

    public static function getGroups(): array
    {
        return ['measures'];
    }

    public function getDependencies(): array
    {
        return [
            AuxiliaryFixtures::class,
            DepartmentPositionFixtures::class,
        ];
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

        foreach (self::CATALOGS as $catalog) {
            $path = $baseDir.'/'.$catalog['filename'];
            if (!is_file($path)) {
                echo sprintf("⚠️  Measures file not found: %s\n", $path);
                continue;
            }

            $report = $this->measureTemplateParser->parseFile($path);
            $report = $this->measureTemplateImporter->import(
                report: $report,
                apply: true,
                validateCanonical: true,
            );
            $this->backfillMeasureSortOrders(
                $manager,
                $catalog['protocolCode'],
                $catalog['protocolName']
            );
            $summary = $report->getImportSummary();

            echo sprintf(
                "📊 Catalog %s | status=%s, imported=%d, updated=%d, errors=%d\n",
                basename($path),
                $summary['status'] ?? $report->getStatus(),
                $summary['imported'] ?? 0,
                $summary['updated'] ?? 0,
                $summary['errors'] ?? count($report->getErrors())
            );
        }

        $this->seedInitialBlockQuestions($manager);
        $manager->flush();

        // Devuelve fallback si quieres
        $this->translatableListener->setTranslationFallback(true);
    }

    private function seedInitialBlockQuestions(ObjectManager $manager): void
    {
        /** @var ProtocolRepository $protocolRepository */
        $protocolRepository = $manager->getRepository(Protocol::class);
        /** @var MeasureBlockRepository $measureBlockRepository */
        $measureBlockRepository = $manager->getRepository(MeasureBlock::class);

        $protocol = $protocolRepository->findOneBy(['code' => 'be-green-my-film'])
            ?? $protocolRepository->findOneBy(['name' => 'Be Green My Film']);

        if (!$protocol instanceof Protocol) {
            echo "⚠️  Protocol not found for block question seed: Be Green My Film\n";
            return;
        }

        $questions = [
            [
                'code' => 'biodiversidad',
                'name' => 'Biodiversidad',
                'question' => '¿Se va a rodar en espacios naturales?',
            ],
            [
                'code' => 'modulo-menores-en-el-rodaje',
                'name' => 'Módulo: Menores en el rodaje',
                'question' => '¿Se va a rodar con menores?',
            ],
            [
                'code' => 'modulo-animales-en-el-rodaje',
                'name' => 'Módulo: Animales en el rodaje',
                'question' => '¿Se va a rodar con animales?',
            ],
        ];

        foreach ($questions as $definition) {
            $block = $measureBlockRepository->findOneBy([
                'protocol' => $protocol,
                'code' => $definition['code'],
            ]) ?? $measureBlockRepository->findOneBy([
                'protocol' => $protocol,
                'name' => $definition['name'],
            ]) ?? $measureBlockRepository->findEquivalentByProtocol($protocol, $definition['name']);

            if (!$block instanceof MeasureBlock) {
                echo sprintf(
                    "⚠️  Measure block not found for question seed: %s (%s)\n",
                    $definition['name'],
                    $definition['code']
                );
                continue;
            }

            $block
                ->setHasScreeningQuestion(true)
                ->setScreeningQuestion($definition['question']);
        }
    }

    private function backfillMeasureSortOrders(ObjectManager $manager, string $protocolCode, string $protocolName): void
    {
        /** @var ProtocolRepository $protocolRepository */
        $protocolRepository = $manager->getRepository(Protocol::class);
        $protocol = $protocolRepository->findOneBy(['code' => $protocolCode])
            ?? $protocolRepository->findOneBy(['name' => $protocolName]);

        if (!$protocol instanceof Protocol) {
            echo sprintf("⚠️  Protocol not found for measure sort order backfill: %s\n", $protocolName);

            return;
        }

        $measureRepository = $manager->getRepository(Measure::class);
        $measures = $measureRepository->createQueryBuilder('m')
            ->andWhere('m.protocol = :protocol')
            ->andWhere('m.sourceRow IS NOT NULL')
            ->andWhere('m.sortOrder = 0')
            ->setParameter('protocol', $protocol)
            ->getQuery()
            ->getResult();

        foreach ($measures as $measure) {
            if (!$measure instanceof Measure) {
                continue;
            }

            $measure->setSortOrder((int) $measure->getSourceRow());
        }
    }
}
