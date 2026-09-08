<?php

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\EmissionActivity;
use App\Entity\EmissionSource;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

// Gedmo
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;

class EmissionActivityFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['emission'];
    }

    public function getDependencies(): array
    {
        return [
            AuxiliaryFixtures::class
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $categoryRepo   = $manager->getRepository(Category::class);
        $sourceRepo     = $manager->getRepository(EmissionSource::class);

        /** @var TranslationRepository $translationRepo */
        $translationRepo = $manager->getRepository(Translation::class);

        // --- Fuentes ---
        $miteco = $sourceRepo->findOneBy(['name' => 'MITECO']);
        if (!$miteco) {
            $miteco = new EmissionSource();
            $miteco->setName('MITECO');
            $miteco->setYear((int) date('Y'));
            $miteco->setDescription('Ministerio para la Transición Ecológica y el Reto Demográfico (ES)');
            $manager->persist($miteco);
            $manager->flush();
        }

        // --- Mapas de traducción ES -> EN ---
        $unitMap = [
            'noche' => 'night',
            'km' => 'km',
            'kWh' => 'kWh',
            'hora' => 'hour',
            'GB·mes' => 'GB·month',
            'litros' => 'liters',
            'kg' => 'kg',
            'm2' => 'm²',
            'día' => 'day',
            'unidad' => 'unit',
            'ración' => 'serving',
            '€' => '€',
        ];

        $subcategoryMap = [
            'carretera' => 'road',
            'ferroviario' => 'rail',
            'maritimo' => 'maritime',
            'aereo' => 'air',
            'otros' => 'other',
            'electricidad' => 'electricity',
            'remoto' => 'remote-office',
            'animacion' => 'animation',
            'montaje_edicion' => 'editing',
            'almacenamiento' => 'storage',
            'gas_generador' => 'gas-generator',
            'gas_caldera' => 'gas-boiler',
            'gas_propano' => 'propane-tank',
            'gas_bombona' => 'gas-cylinder',
            'madera' => 'wood',
        ];

        $nameMap = [
            // Materiales
            'Madera comprada' => 'Purchased wood',
            'Madera reciclada' => 'Recycled wood',
            'Madera reutilizada' => 'Reused wood',
            'Papel (nuevo)' => 'Paper (new)',
            'Papel (reciclado)' => 'Paper (recycled)',
            'Cartón' => 'Cardboard',
            'Plástico' => 'Plastic',
            'Textil (algodón)' => 'Textile (cotton)',
            'Textil (poliéster)' => 'Textile (polyester)',
            'Metal (acero)' => 'Metal (steel)',
            'Metal (aluminio)' => 'Metal (aluminium)',
            'Vidrio' => 'Glass',
            'Cerámica' => 'Ceramic',
            'Pintura (base agua)' => 'Paint (water-based)',
            'Pintura (base disolvente)' => 'Paint (solvent-based)',
            'Spray / Aerosol' => 'Spray / Aerosol',
            'Baterías' => 'Batteries',
            'Bombillas' => 'Light bulbs',
            'Lona textil' => 'Textile canvas',
            'Cartón espuma' => 'Foam board',
            'Panel nido de abeja' => 'Honeycomb panel',
            'Tablero MDF' => 'MDF board',
            'Tablero aglomerado' => 'Chipboard',
            'Poliestireno expandido' => 'Expanded polystyrene',
            'PVC calandrado' => 'Calendered PVC',
            'Acrílico / PMMA' => 'Acrylic / PMMA',
            'SAV (vinilo autoadhesivo)' => 'SAV (self-adhesive vinyl)',
            'Material reutilizado' => 'Reused material',
            'Material alquilado' => 'Rented material',
            'Material nuevo / virgen' => 'New / virgin material',
            'Vestuario (nuevo)' => 'Wardrobe (new)',
            'Vestuario (segunda mano)' => 'Wardrobe (second-hand)',
            'Decorados / escenografía' => 'Sets / Scenography',
            'Elementos de atrezzo' => 'Props',
            'Equipo de rodaje (por día)' => 'Shooting equipment (per day)',

            // Residuos
            'Orgánico' => 'Organic',
            'Compost' => 'Compost',
            'Papel' => 'Paper',
            'Envases' => 'Packaging',
            //'Vidrio' => 'Glass',
            'Resto' => 'Residual waste',
            'Metal' => 'Metal',
            'Textil' => 'Textile',
            'Pinturas, disolventes, barnices' => 'Paints, solvents, varnishes',
            'Madera' => 'Wood',
            'Pequeños electrodomésticos' => 'Small appliances',
            'Aceite usado' => 'Used oil',
            //'Plástico' => 'Plastic',
            'Toner' => 'Toner',
            'Pilas' => 'Batteries',
            'Mezcla de todo tipo' => 'Mixed waste',
        ];

        // --- Datos base (ES por defecto) ---
        $activities = [
            // [CategoryName, Name(ES), Unit(ES), Factor, Subcategory(ES or null)]
            // Materiales
            ['Materiales', 'Papel (nuevo)', 'kg', 1.3, null],
            ['Materiales', 'Papel (reciclado)', 'kg', 0.9, null],
            ['Materiales', 'Cartón', 'kg', 0.6, null],
            ['Materiales', 'Plástico', 'kg', 2.5, null],
            ['Materiales', 'Textil (algodón)', 'kg', 10.0, null],
            ['Materiales', 'Textil (poliéster)', 'kg', 15.0, null],
            ['Materiales', 'Metal (acero)', 'kg', 2.0, null],
            ['Materiales', 'Metal (aluminio)', 'kg', 10.0, null],
            ['Materiales', 'Vidrio', 'kg', 1.0, null],
            ['Materiales', 'Cerámica', 'kg', 0.8, null],
            ['Materiales', 'Pintura (base agua)', 'kg', 3.0, null],
            ['Materiales', 'Pintura (base disolvente)', 'kg', 5.0, null],
            ['Materiales', 'Spray / Aerosol', 'kg', 6.0, null],
            ['Materiales', 'Baterías', 'kg', 8.0, null],
            ['Materiales', 'Bombillas', 'kg', 5.5, null],
            ['Materiales', 'Lona textil', 'm2', 1.5, null],
            ['Materiales', 'Cartón espuma', 'm2', 1.2, null],
            ['Materiales', 'Panel nido de abeja', 'm2', 1.0, null],
            ['Materiales', 'Tablero MDF', 'm2', 2.5, null],
            ['Materiales', 'Tablero aglomerado', 'm2', 2.0, null],
            ['Materiales', 'Poliestireno expandido', 'kg', 3.5, null],
            ['Materiales', 'PVC calandrado', 'kg', 6.5, null],
            ['Materiales', 'Acrílico / PMMA', 'kg', 9.0, null],
            ['Materiales', 'SAV (vinilo autoadhesivo)', 'kg', 7.0, null],
            ['Materiales', 'Material reutilizado', '€', 0.3, null],
            ['Materiales', 'Material alquilado', '€', 0.2, null],
            ['Materiales', 'Material nuevo / virgen', '€', 1.2, null],
            ['Materiales', 'Vestuario (nuevo)', 'kg', 11.0, null],
            ['Materiales', 'Vestuario (segunda mano)', 'kg', 3.0, null],
            ['Materiales', 'Decorados / escenografía', '€', 1.5, null],
            ['Materiales', 'Elementos de atrezzo', '€', 1.0, null],
            ['Materiales', 'Equipo de rodaje (por día)', 'día', 2.0, null],

            // Residuos
            ['Residuos', 'Orgánico', 'kg', 0.05, null],
            ['Residuos', 'Compost', 'kg', 0.01, null],
            ['Residuos', 'Papel', 'kg', 0.07, null],
            ['Residuos', 'Envases', 'kg', 0.08, null],
            ['Residuos', 'Vidrio', 'kg', 0.02, null],
            ['Residuos', 'Resto', 'kg', 0.10, null],
            ['Residuos', 'Metal', 'kg', 0.06, null],
            ['Residuos', 'Textil', 'kg', 0.45, null],
            ['Residuos', 'Pinturas, disolventes, barnices', 'kg', 2.00, null],
            ['Residuos', 'Madera', 'kg', 0.09, null],
            ['Residuos', 'Pequeños electrodomésticos', 'kg', 1.00, null],
            ['Residuos', 'Aceite usado', 'kg', 2.50, null],
            ['Residuos', 'Plástico', 'kg', 1.20, null],
            ['Residuos', 'Toner', 'kg', 3.00, null],
            ['Residuos', 'Bombillas', 'kg', 1.50, null],
            ['Residuos', 'Pilas', 'kg', 3.50, null],
            ['Residuos', 'Mezcla de todo tipo', 'kg', 1.00, null],
        ];

        foreach ($activities as [$categoryName, $nameEs, $unitEs, $factor, $subcategoryEs]) {
            $category = $categoryRepo->findOneBy(['name' => $categoryName]);
            if (!$category) {
                continue;
            }

            $activity = new EmissionActivity();
            // Carga base en ES (idioma por defecto)
            $activity->setCategory($category);
            $activity->setName($nameEs);
            $activity->setUnit($unitEs);
            $activity->setEmissionFactor($factor);
            $activity->setEmissionSource($miteco);
            if ($subcategoryEs) {
                $activity->setSubcategory($subcategoryEs);
            }

            $manager->persist($activity);

            // Traducciones EN con TranslationRepository
            $nameEn        = $nameMap[$nameEs] ?? $nameEs;
            $unitEn        = $unitMap[$unitEs] ?? $unitEs;
            $subcategoryEn = $subcategoryEs ? ($subcategoryMap[$subcategoryEs] ?? $subcategoryEs) : null;

            $translationRepo->translate($activity, 'name', 'en', $nameEn);
            $translationRepo->translate($activity, 'unit', 'en', $unitEn);
        }

        $materials = $categoryRepo->findOneBy(['name' => 'Materiales']);
        if ($materials) {
            $woodDefra = $sourceRepo->findOneBy(['name' => 'DEFRA', 'year' => 2025]);
            if (!$woodDefra) {
                $woodDefra = (new EmissionSource())
                    ->setName('DEFRA')
                    ->setYear(2025)
                    ->setDescription('Department for Environment, Food & Rural Affairs (UK)');
                $manager->persist($woodDefra);
            }

            $woodFactors = [
                ['Madera comprada', 0.26950416, 'wood_purchased'],
                ['Madera reciclada', 0.112969683723424, 'wood_recycled'],
                ['Madera reutilizada', 0.03854288, 'wood_reused'],
            ];

            foreach ($woodFactors as [$nameEs, $factor, $calculationCode]) {
                $activity = (new EmissionActivity())
                    ->setCategory($materials)
                    ->setName($nameEs)
                    ->setUnit('kg')
                    ->setEmissionFactor($factor)
                    ->setEmissionSource($woodDefra)
                    ->setSubcategory('madera')
                    ->setCalculationCode($calculationCode);

                $manager->persist($activity);
                $translationRepo->translate($activity, 'name', 'en', $nameMap[$nameEs]);
                $translationRepo->translate($activity, 'unit', 'en', 'kg');
            }
        }

        $manager->flush();
    }
}
