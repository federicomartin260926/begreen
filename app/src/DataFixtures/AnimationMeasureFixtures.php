<?php

namespace App\DataFixtures;

use App\Service\Animation\AnimationCatalogImporter;
use App\Service\Animation\AnimationCatalogSourceReader;
use App\Service\MeasureTaxonomyOverlayImporter;
use App\Service\MeasureTaxonomyOverlayReader;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class AnimationMeasureFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly AnimationCatalogSourceReader $sourceReader,
        private readonly AnimationCatalogImporter $importer,
        private readonly MeasureTaxonomyOverlayReader $taxonomyOverlayReader,
        private readonly MeasureTaxonomyOverlayImporter $taxonomyOverlayImporter,
        private readonly ParameterBagInterface $params,
    ) {
    }

    public static function getGroups(): array
    {
        return ['measures'];
    }

    public function getDependencies(): array
    {
        return [
            AuxiliaryFixtures::class,
            MeasureDepartmentFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $path = rtrim((string) $this->params->get('kernel.project_dir'), '/')
            .'/public/fixtures/BE_GREEN_MY_ANIMATION_v42_ENTREGA_INFORMATICO.xlsx';

        $summary = $this->importer->import($this->sourceReader->read($path));
        $overlayPath = rtrim((string) $this->params->get('kernel.project_dir'), '/')
            .'/public/fixtures/be_green_my_animation_taxonomy.xlsx';
        $overlaySummary = $this->taxonomyOverlayImporter->import(
            $this->taxonomyOverlayReader->read($overlayPath, AnimationCatalogImporter::PROTOCOL_CODE)
        );

        echo sprintf(
            "Animation catalog %s | created=%d, updated=%d, active=%d\n",
            AnimationCatalogImporter::IMPORT_VERSION,
            $summary['created'],
            $summary['updated'],
            $summary['active'],
        );
        echo sprintf(
            "Animation taxonomy | measures=%d, DEP=%d, EVI=%d\n",
            $overlaySummary['measures'],
            $overlaySummary['departmentLinks'],
            $overlaySummary['evidenceLinks'],
        );
    }
}
