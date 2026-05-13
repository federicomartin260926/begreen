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

        if (!$sourceRepo->findOneBy(['name' => 'DEFRA'])) {
            $defra = new EmissionSource();
            $defra->setName('DEFRA');
            $defra->setYear((int) date('Y'));
            $defra->setDescription('Department for Environment, Food & Rural Affairs (UK)');
            $manager->persist($defra);
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
        ];

        $nameMap = [
            // Alojamientos
            'HOTEL *****' => 'HOTEL *****',
            'HOTEL ****' => 'HOTEL ****',
            'HOTEL ***' => 'HOTEL ***',
            'HOTEL **' => 'HOTEL **',
            'HOTEL *' => 'HOTEL *',
            'HOSTAL' => 'Hostel',
            'CASA RURAL (menos de 100 m²)' => 'Country house (under 100 m²)',
            'CASA RURAL (de 100 m² a 200 m²)' => 'Country house (100–200 m²)',
            'CASA RURAL (más de 200 m²)' => 'Country house (over 200 m²)',
            'APARTAMENTO (menos de 50 m²)' => 'Apartment (under 50 m²)',
            'APARTAMENTO (de 50 a 150 m²)' => 'Apartment (50–150 m²)',
            'APARTAMENTO (más de 150 m²)' => 'Apartment (over 150 m²)',

            // Transporte local
            'A pie' => 'On foot',
            'Bicicleta' => 'Bicycle',
            'Patinete' => 'Kick scooter',
            'Taxi (gasolina)' => 'Taxi (gasoline)',
            'Taxi (diésel)' => 'Taxi (diesel)',
            'Taxi (eléctrico)' => 'Taxi (electric)',
            'Coche pequeño (gasolina)' => 'Small car (gasoline)',
            'Coche mediano (gasolina)' => 'Medium car (gasoline)',
            'Coche grande (gasolina)' => 'Large car (gasoline)',
            'Coche promedio (diésel)' => 'Average car (diesel)',
            'Coche híbrido' => 'Hybrid car',
            'Coche eléctrico' => 'Electric car',
            'Motocicleta (gasolina)' => 'Motorcycle (gasoline)',
            'Bus urbano (diésel)' => 'City bus (diesel)',
            'Bus urbano (eléctrico)' => 'City bus (electric)',
            'Tren eléctrico (cercanías)' => 'Electric train (commuter)',
            'Ferry (trayecto local)' => 'Ferry (local route)',
            'Barco recreativo' => 'Recreational boat',
            'Camión fijo (7.5–17t)' => 'Rigid truck (7.5–17t)',
            'Camión articulado (>33t)' => 'Articulated truck (>33t)',

            // Viajes
            'Tren AVE o larga distancia' => 'High-speed / long-distance train',
            'Vuelo nacional (clase turista)' => 'Domestic flight (economy)',
            'Vuelo internacional (clase turista)' => 'International flight (economy)',
            'Coche de alquiler' => 'Rental car',
            'Taxi interurbano' => 'Intercity taxi',
            'Vehículo privado fuera de sede' => 'Private vehicle (off-site)',
            'Jet privado (vuelo corporativo)' => 'Private jet (corporate flight)',

            // Energía
            'Electricidad' => 'Electricity',
            'Oficina en remoto' => 'Remote office',
            'Postproducción - Animación' => 'Post-production – Animation',
            'Postproducción - Montaje y edición' => 'Post-production – Editing',
            'Archivo y almacenamiento digital' => 'Digital archiving & storage',
            'Generador a gas' => 'Gas generator',
            'Caldera de gas' => 'Gas boiler',
            'Depósito de propano' => 'Propane tank',
            'Bombona de gas' => 'Gas cylinder',

            // Catering
            'Desayuno' => 'Breakfast',
            'Bocadillo' => 'Sandwich',
            'Menú Vegetariano' => 'Vegetarian menu',
            'Menú Vegano' => 'Vegan menu',
            'Menú Pescado' => 'Fish menu',
            'Menú Carne de Pollo' => 'Chicken menu',
            'Menú Carne Roja' => 'Red meat menu',
            'Bebida Alcohólica' => 'Alcoholic beverage',
            'Bebida No Alcohólica' => 'Non-alcoholic beverage',

            // Materiales
            'Madera (nueva)' => 'Wood (new)',
            'Madera (reciclada)' => 'Wood (recycled)',
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

            // Agua
            'Agua en escena' => 'Water on set',
            'Agua embotellada para beber' => 'Bottled drinking water',
            'Agua para FX' => 'Water for FX',

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
            ['Alojamientos', 'HOTEL *****', 'noche', 12.0, null],
            ['Alojamientos', 'HOTEL ****', 'noche', 10.5, null],
            ['Alojamientos', 'HOTEL ***', 'noche', 9.0, null],
            ['Alojamientos', 'HOTEL **', 'noche', 7.5, null],
            ['Alojamientos', 'HOTEL *', 'noche', 6.5, null],
            ['Alojamientos', 'HOSTAL', 'noche', 5.5, null],
            ['Alojamientos', 'CASA RURAL (menos de 100 m²)', 'noche', 5.0, null],
            ['Alojamientos', 'CASA RURAL (de 100 m² a 200 m²)', 'noche', 6.5, null],
            ['Alojamientos', 'CASA RURAL (más de 200 m²)', 'noche', 7.5, null],
            ['Alojamientos', 'APARTAMENTO (menos de 50 m²)', 'noche', 4.5, null],
            ['Alojamientos', 'APARTAMENTO (de 50 a 150 m²)', 'noche', 5.5, null],
            ['Alojamientos', 'APARTAMENTO (más de 150 m²)', 'noche', 6.5, null],

            // Transporte
            ['Transporte', 'A pie', 'km', 0.0, 'otros'],
            ['Transporte', 'Bicicleta', 'km', 0.0, 'otros'],
            ['Transporte', 'Patinete', 'km', 0.0, 'otros'],
            ['Transporte', 'Taxi (gasolina)', 'km', 0.151, 'carretera'],
            ['Transporte', 'Taxi (diésel)', 'km', 0.139, 'carretera'],
            ['Transporte', 'Taxi (eléctrico)', 'km', 0.05, 'carretera'],
            ['Transporte', 'Coche pequeño (gasolina)', 'km', 0.123, 'carretera'],
            ['Transporte', 'Coche mediano (gasolina)', 'km', 0.152, 'carretera'],
            ['Transporte', 'Coche grande (gasolina)', 'km', 0.174, 'carretera'],
            ['Transporte', 'Coche promedio (diésel)', 'km', 0.159, 'carretera'],
            ['Transporte', 'Coche híbrido', 'km', 0.09, 'carretera'],
            ['Transporte', 'Coche eléctrico', 'km', 0.045, 'carretera'],
            ['Transporte', 'Motocicleta (gasolina)', 'km', 0.072, 'carretera'],
            ['Transporte', 'Bus urbano (diésel)', 'km', 0.105, 'carretera'],
            ['Transporte', 'Bus urbano (eléctrico)', 'km', 0.027, 'carretera'],
            ['Transporte', 'Tren eléctrico (cercanías)', 'km', 0.036, 'ferroviario'],
            ['Transporte', 'Ferry (trayecto local)', 'km', 0.11, 'maritimo'],
            ['Transporte', 'Barco recreativo', 'km', 0.18, 'maritimo'],
            ['Transporte', 'Camión fijo (7.5–17t)', 'km', 0.55, 'carretera'],
            ['Transporte', 'Camión articulado (>33t)', 'km', 0.9, 'carretera'],

            // Viajes
            ['Viajes', 'Tren AVE o larga distancia', 'km', 0.028, 'ferroviario'],
            ['Viajes', 'Vuelo nacional (clase turista)', 'km', 0.133, 'aereo'],
            ['Viajes', 'Vuelo internacional (clase turista)', 'km', 0.110, 'aereo'],
            ['Viajes', 'Coche de alquiler', 'km', 0.160, 'carretera'],
            ['Viajes', 'Taxi interurbano', 'km', 0.151, 'carretera'],
            ['Viajes', 'Vehículo privado fuera de sede', 'km', 0.152, 'carretera'],
            ['Viajes', 'Jet privado (vuelo corporativo)', 'km', 1.5, 'aereo'],

            // Energía
            ['Energía', 'Electricidad', 'kWh', 0.233, 'electricidad'],
            ['Energía', 'Oficina en remoto', 'hora', 0.025, 'remoto'],
            ['Energía', 'Postproducción - Animación', 'hora', 0.035, 'animacion'],
            ['Energía', 'Postproducción - Montaje y edición', 'hora', 0.03, 'montaje_edicion'],
            ['Energía', 'Archivo y almacenamiento digital', 'GB·mes', 0.002, 'almacenamiento'],
            ['Energía', 'Generador a gas', 'litros', 2.2, 'gas_generador'],
            ['Energía', 'Caldera de gas', 'kWh', 0.204, 'gas_caldera'],
            ['Energía', 'Depósito de propano', 'kg', 2.96, 'gas_propano'],
            ['Energía', 'Bombona de gas', 'kg', 2.1, 'gas_bombona'],

            // Catering
            ['Catering', 'Desayuno', 'ración', 1.2, null],
            ['Catering', 'Bocadillo', 'ración', 0.8, null],
            ['Catering', 'Menú Vegetariano', 'ración', 2.0, null],
            ['Catering', 'Menú Vegano', 'ración', 1.5, null],
            ['Catering', 'Menú Pescado', 'ración', 3.0, null],
            ['Catering', 'Menú Carne de Pollo', 'ración', 3.5, null],
            ['Catering', 'Menú Carne Roja', 'ración', 5.0, null],
            ['Catering', 'Bebida Alcohólica', 'unidad', 1.0, null],
            ['Catering', 'Bebida No Alcohólica', 'unidad', 0.5, null],

            // Materiales
            ['Materiales', 'Madera (nueva)', 'kg', 0.9, null],
            ['Materiales', 'Madera (reciclada)', 'kg', 0.5, null],
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

            // Agua
            ['Agua', 'Agua en escena', 'litros', 0.0003, null],
            ['Agua', 'Agua embotellada para beber', 'litros', 0.5, null],
            ['Agua', 'Agua para FX', 'litros', 0.0003, null],

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

        $manager->flush();
    }
}
