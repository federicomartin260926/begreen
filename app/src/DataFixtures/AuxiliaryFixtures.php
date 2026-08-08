<?php

namespace App\DataFixtures;

use App\Entity\Scope;
use App\Entity\Category;
use App\Entity\CategoryGhg;
use App\Entity\ImpactArea;
use App\Entity\Protocol;
use App\Entity\Ods;
use App\Entity\EsG;
use App\Entity\TripleBalanceAxis;
use App\Entity\VerificationSource;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Gedmo\Translatable\TranslatableListener;
use Gedmo\Translatable\Entity\Translation;

class AuxiliaryFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly TranslatableListener $translatableListener
    ) {}

    public static function getGroups(): array
    {
        return ['auxiliary'];
    }

    public function load(ObjectManager $manager): void
    {
        // Base en ES
        $this->translatableListener->setTranslatableLocale('es');
        $this->translatableListener->setTranslationFallback(false);

        /** @var \Gedmo\Translatable\Entity\Repository\TranslationRepository $tr */
        $tr = $manager->getRepository(Translation::class);

        // ===== Diccionario simple ES→EN (solo entidades usadas aquí) =====
        $t = function (string $es) {
            static $map = [
                // Protocols
                'Be Green My Film' => 'Be Green My Film',
                'Be Green My Event' => 'Be Green My Event',

                // Categories
                'Alojamientos' => 'Accommodation',
                'Transporte'   => 'Transport',
                'Viajes'       => 'Travel',
                'Energía'      => 'Energy',
                'Catering'     => 'Catering',
                'Materiales'   => 'Materials',
                'Agua'         => 'Water',
                'Residuos'     => 'Waste',
                'Biodiversidad' => 'Biodiversity',
                'Comunicación' => 'Communication',
                'Consumo eficiente de recursos naturales' => 'Efficient Consumption of Natural Resources',
                'Contenido' => 'Content',
                'Social' => 'Social',

                // Category GHG
                'Emisiones directas de GEI debido al consumo de combustibles fósiles' => 'Direct GHG emissions from fossil fuel consumption',
                'Emisiones indirectas de GEI debido al consumo de energía importada'  => 'Indirect GHG emissions from imported energy consumption',
                'Emisiones indirectas de GEI debido al transporte'                    => 'Indirect GHG emissions from transport',
                'Emisiones indirectas de GEI por productos utilizados por la organización' => 'Indirect GHG emissions from products used by the organization',
                'Emisiones indirectas de GEI por otras fuentes'                       => 'Indirect GHG emissions from other sources',

                // Scopes
                'Alcance 1' => 'Scope 1',
                'Alcance 2' => 'Scope 2',
                'Alcance 3' => 'Scope 3',

                // ESG
                'Ambiental' => 'Environmental',
                'Social' => 'Social',
                'Gobernanza' => 'Governance',

                // ODS (names)
                'Fin de la pobreza' => 'No Poverty',
                'Hambre cero' => 'Zero Hunger',
                'Salud y bienestar' => 'Good Health and Well-Being',
                'Educación de calidad' => 'Quality Education',
                'Igualdad de género' => 'Gender Equality',
                'Agua limpia y saneamiento' => 'Clean Water and Sanitation',
                'Energía asequible y no contaminante' => 'Affordable and Clean Energy',
                'Trabajo decente y crecimiento económico' => 'Decent Work and Economic Growth',
                'Industria, innovación e infraestructura' => 'Industry, Innovation and Infrastructure',
                'Reducción de las desigualdades' => 'Reduced Inequalities',
                'Ciudades y comunidades sostenibles' => 'Sustainable Cities and Communities',
                'Producción y consumo responsables' => 'Responsible Consumption and Production',
                'Acción por el clima' => 'Climate Action',
                'Vida submarina' => 'Life Below Water',
                'Vida de ecosistemas terrestres' => 'Life on Land',
                'Paz, justicia e instituciones sólidas' => 'Peace, Justice and Strong Institutions',
                'Alianzas para lograr los objetivos' => 'Partnerships for the Goals',
            ];
            return $map[$es] ?? $es;
        };

        // Helpers --------
        $translateName = function (object $entity, string $esName) use ($tr, $t) {
            $tr->translate($entity, 'name', 'en', $t($esName));
        };
        $translateDesc = function (object $entity, ?string $esDesc) use ($tr) {
            if ($esDesc === null || $esDesc === '') return;
            $tr->translate($entity, 'description', 'en', 'Description: ' . $esDesc);
        };

        $upsert = function (ObjectManager $em, string $class, array $criteria, callable $builder) {
            $repo = $em->getRepository($class);
            $entity = $repo->findOneBy($criteria);
            if (!$entity) {
                $entity = $builder();
                $em->persist($entity);
            } else {
                // Permite que el builder actualice campos si cambian
                $builder($entity);
            }
            return $entity;
        };

        // -------------------------
        // Protocols
        // -------------------------
        $protocols = [
            ['code' => 'be-green-my-film',  'name' => 'Be Green My Film',  'type' => 'rodaje'],
            ['code' => 'be-green-my-event', 'name' => 'Be Green My Event', 'type' => 'evento'],
        ];
        foreach ($protocols as $data) {
            $p = $upsert($manager, Protocol::class, ['name' => $data['name']], function () use ($data) {
                return (new Protocol())->setCode($data['code'])->setName($data['name'])->setType($data['type']);
            });
            $p->setCode($data['code'])->setType($data['type']);
            $translateName($p, $p->getName());
        }

        // -------------------------
        // Categories
        // -------------------------
        foreach ([
            ['name' => 'Oficina', 'sortOrder' => 10, 'enabledInEmissionCalculator' => false],
            ['name' => 'Energía', 'sortOrder' => 20, 'enabledInEmissionCalculator' => true],
            ['name' => 'Alojamientos', 'sortOrder' => 30, 'enabledInEmissionCalculator' => true],
            ['name' => 'Transporte', 'sortOrder' => 40, 'enabledInEmissionCalculator' => true],
            ['name' => 'Consumo eficiente de recursos naturales', 'sortOrder' => 50, 'enabledInEmissionCalculator' => false],
            ['name' => 'Consumo eficiente de recursos', 'sortOrder' => 50, 'enabledInEmissionCalculator' => false],
            ['name' => 'Materiales', 'sortOrder' => 60, 'enabledInEmissionCalculator' => true],
            ['name' => 'Residuos', 'sortOrder' => 70, 'enabledInEmissionCalculator' => true],
            ['name' => 'Catering', 'sortOrder' => 80, 'enabledInEmissionCalculator' => true],
            ['name' => 'Biodiversidad', 'sortOrder' => 90, 'enabledInEmissionCalculator' => false],
            ['name' => 'Comunicación', 'sortOrder' => 100, 'enabledInEmissionCalculator' => false],
            ['name' => 'Contenido', 'sortOrder' => 110, 'enabledInEmissionCalculator' => false],
            ['name' => 'Contenidos', 'sortOrder' => 110, 'enabledInEmissionCalculator' => false],
            ['name' => 'Social', 'sortOrder' => 120, 'enabledInEmissionCalculator' => false],
            ['name' => 'Viajes', 'sortOrder' => 130, 'enabledInEmissionCalculator' => true],
            ['name' => 'Agua', 'sortOrder' => 140, 'enabledInEmissionCalculator' => true],
        ] as $data) {
            $c = $upsert($manager, Category::class, ['name' => $data['name']], function (?Category $entity = null) use ($data) {
                $entity ??= new Category();

                return $entity
                    ->setName($data['name'])
                    ->setSortOrder($data['sortOrder'])
                    ->setEnabledInEmissionCalculator($data['enabledInEmissionCalculator']);
            });
            $c->setSortOrder($data['sortOrder'])
                ->setEnabledInEmissionCalculator($data['enabledInEmissionCalculator']);
            $translateName($c, $data['name']);
        }

        // -------------------------
        // GHG Categories
        // -------------------------
        $categoryGhgs = [
            'Emisiones directas de GEI debido al consumo de combustibles fósiles',
            'Emisiones indirectas de GEI debido al consumo de energía importada',
            'Emisiones indirectas de GEI debido al transporte',
            'Emisiones indirectas de GEI por productos utilizados por la organización',
            'Emisiones indirectas de GEI por otras fuentes',
            'Alcance 2 - Electricidad adquirida',
            'Alcance 3 - Cat. 5 Residuos generados en las operaciones',
            'Alcance 3 - Cat. 1 Bienes y servicios adquiridos',
            'Alcance 1 - Combustión fija',
            'Alcance 3 - Cat. 6 Viajes de negocio',
            'Alcance 1 - Combustión móvil',
            'Alcance 3 - Cat. 4 Transporte y distribución (upstream)',
            'Alcance 3 - Cat. 7 Desplazamientos del personal',
            'Electricidad adquirida',
            'Bienes y servicios adquiridos',
            'Residuos generados',
            'Combustión fija',
            'Energía autogenerada',
            'Combustión móvil',
            'Alojamiento',
            'Desplazamiento de asistentes',
            'Viajes de negocio',
            'Transporte y distribución',
            'Agua y vertidos',
            'Alimentación',
            'Todas las categorías',
            'No aplica',
        ];
        foreach ($categoryGhgs as $name) {
            $g = $upsert($manager, CategoryGhg::class, ['name' => $name], function () use ($name) {
                return (new CategoryGhg())->setName($name)->setDescription(null);
            });
            $translateName($g, $name);
        }

        // -------------------------
        // Scopes
        // -------------------------
        foreach (['Alcance 1', 'Alcance 2', 'Alcance 3', 'No aplica', 'Alcance 1, 2 y 3'] as $name) {
            $s = $upsert($manager, Scope::class, ['name' => $name], function () use ($name) {
                return (new Scope())->setName($name);
            });
            $translateName($s, $name);
        }

        // -------------------------
        // ODS
        // -------------------------
        $odsList = [
            ['code' => 'ODS1',  'name' => 'Fin de la pobreza'],
            ['code' => 'ODS2',  'name' => 'Hambre cero'],
            ['code' => 'ODS3',  'name' => 'Salud y bienestar'],
            ['code' => 'ODS4',  'name' => 'Educación de calidad'],
            ['code' => 'ODS5',  'name' => 'Igualdad de género'],
            ['code' => 'ODS6',  'name' => 'Agua limpia y saneamiento'],
            ['code' => 'ODS7',  'name' => 'Energía asequible y no contaminante'],
            ['code' => 'ODS8',  'name' => 'Trabajo decente y crecimiento económico'],
            ['code' => 'ODS9',  'name' => 'Industria, innovación e infraestructura'],
            ['code' => 'ODS10', 'name' => 'Reducción de las desigualdades'],
            ['code' => 'ODS11', 'name' => 'Ciudades y comunidades sostenibles'],
            ['code' => 'ODS12', 'name' => 'Producción y consumo responsables'],
            ['code' => 'ODS13', 'name' => 'Acción por el clima'],
            ['code' => 'ODS14', 'name' => 'Vida submarina'],
            ['code' => 'ODS15', 'name' => 'Vida de ecosistemas terrestres'],
            ['code' => 'ODS16', 'name' => 'Paz, justicia e instituciones sólidas'],
            ['code' => 'ODS17', 'name' => 'Alianzas para lograr los objetivos'],
        ];

        foreach ($odsList as $data) {
            $ods = $upsert($manager, Ods::class, ['code' => $data['code']], function () use ($data) {
                return (new Ods())
                    ->setCode($data['code'])
                    ->setName($data['name'])
                    ->setDescription('Descripción de ' . $data['name']);
            });
            $translateName($ods, $ods->getName());
            $tr->translate($ods, 'description', 'en', 'Description of ' . $t($ods->getName()));
        }

        // -------------------------
        // ESG
        // -------------------------
        $esgList = [
            'Ambiental'   => 'Impacto ambiental, cambio climático, eficiencia energética, residuos, biodiversidad.',
            'Social'      => 'Relaciones laborales, igualdad, inclusión, derechos humanos, comunidad.',
            'Gobernanza'  => 'Ética empresarial, transparencia, cumplimiento, estructura organizativa.',
        ];
        foreach ($esgList as $name => $desc) {
            $e = $upsert($manager, EsG::class, ['name' => $name], function () use ($name, $desc) {
                return (new EsG())->setName($name)->setDescription($desc);
            });
            $translateName($e, $name);
            $translateDesc($e, $desc);
        }

        // -------------------------
        // Impact areas (v23 canonical)
        // -------------------------
        $impactAreas = [
            ['code' => 'a', 'name' => 'Cambio Climático', 'sortOrder' => 1],
            ['code' => 'b', 'name' => 'Agotamiento Recursos Nat.', 'sortOrder' => 2],
            ['code' => 'c', 'name' => 'Contaminación', 'sortOrder' => 3],
            ['code' => 'd', 'name' => 'Biodiversidad', 'sortOrder' => 4],
            ['code' => 'e', 'name' => 'Comunicación y Sensib.', 'sortOrder' => 5],
        ];
        foreach ($impactAreas as $data) {
            $impactArea = $upsert($manager, ImpactArea::class, ['code' => $data['code']], function () use ($data) {
                return (new ImpactArea())
                    ->setCode($data['code'])
                    ->setName($data['name'])
                    ->setSortOrder($data['sortOrder']);
            });
            $impactArea
                ->setCode($data['code'])
                ->setName($data['name'])
                ->setSortOrder($data['sortOrder']);
        }

        // -------------------------
        // Verification sources (v23 canonical)
        // -------------------------
        $verificationSources = [
            ['code' => 'af', 'name' => 'Factura / Albarán', 'sortOrder' => 1],
            ['code' => 'ag', 'name' => 'Foto', 'sortOrder' => 2],
            ['code' => 'ah', 'name' => 'Captura / Email', 'sortOrder' => 3],
            ['code' => 'ai', 'name' => 'Declaración Resp.', 'sortOrder' => 4],
            ['code' => 'aj', 'name' => 'Informe Técnico', 'sortOrder' => 5],
            ['code' => 'ak', 'name' => 'Certif. / Licencia', 'sortOrder' => 6],
            ['code' => 'al', 'name' => 'Listado / Invent.', 'sortOrder' => 7],
            ['code' => 'am', 'name' => 'Ficha Técnica', 'sortOrder' => 8],
            ['code' => 'an', 'name' => 'Contrato / Acuerdo', 'sortOrder' => 9],
            ['code' => 'ao', 'name' => 'Doc. Producción', 'sortOrder' => 10],
            ['code' => 'ap', 'name' => 'Plan / Protocolo', 'sortOrder' => 11],
            ['code' => 'aq', 'name' => 'Acta / Registro', 'sortOrder' => 12],
            ['code' => 'ar', 'name' => 'Permiso Admin.', 'sortOrder' => 13],
        ];
        foreach ($verificationSources as $data) {
            $source = $upsert($manager, VerificationSource::class, ['code' => $data['code']], function () use ($data) {
                return (new VerificationSource())
                    ->setCode($data['code'])
                    ->setName($data['name'])
                    ->setSortOrder($data['sortOrder']);
            });
            $source
                ->setCode($data['code'])
                ->setName($data['name'])
                ->setSortOrder($data['sortOrder']);
        }

        // -------------------------
        // Triple balance axes
        // -------------------------
        $tripleBalanceAxes = [
            ['code' => 'ambiental', 'name' => 'Ambiental', 'sortOrder' => 1],
            ['code' => 'social', 'name' => 'Social', 'sortOrder' => 2],
            ['code' => 'economico', 'name' => 'Económico', 'sortOrder' => 3],
        ];
        foreach ($tripleBalanceAxes as $data) {
            $axis = $upsert($manager, TripleBalanceAxis::class, ['code' => $data['code']], function () use ($data) {
                return (new TripleBalanceAxis())
                    ->setCode($data['code'])
                    ->setName($data['name'])
                    ->setSortOrder($data['sortOrder']);
            });
            $axis
                ->setCode($data['code'])
                ->setName($data['name'])
                ->setSortOrder($data['sortOrder']);
        }

        // Flush final
        $manager->flush();

        // Restablecer fallback si lo usas en runtime
        $this->translatableListener->setTranslationFallback(true);
    }
}
