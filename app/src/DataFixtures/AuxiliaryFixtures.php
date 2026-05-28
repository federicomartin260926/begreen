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
        ];
        foreach ($protocols as $data) {
            $p = $upsert($manager, Protocol::class, ['name' => $data['name']], function () use ($data) {
                return (new Protocol())->setCode($data['code'])->setName($data['name'])->setType($data['type']);
            });
            $p->setCode($data['code']);
            $translateName($p, $p->getName());
        }

        // -------------------------
        // Categories
        // -------------------------
        foreach ([
            'Alojamientos',
            'Transporte',
            'Viajes',
            'Energía',
            'Catering',
            'Materiales',
            'Agua',
            'Residuos',
            'Biodiversidad',
            'Comunicación',
            'Consumo eficiente de recursos naturales',
            'Contenido',
            'Social',
        ] as $name) {
            $c = $upsert($manager, Category::class, ['name' => $name], function () use ($name) {
                return (new Category())->setName($name);
            });
            $translateName($c, $name);
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
        foreach (['Alcance 1','Alcance 2','Alcance 3'] as $name) {
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
            ['code' => 'c', 'name' => 'Biodiversidad', 'sortOrder' => 3],
            ['code' => 'd', 'name' => 'Contaminación', 'sortOrder' => 4],
            ['code' => 'e', 'name' => 'Cambio Uso Suelo', 'sortOrder' => 5],
            ['code' => 'f', 'name' => 'Comunicación y Sensib.', 'sortOrder' => 6],
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
