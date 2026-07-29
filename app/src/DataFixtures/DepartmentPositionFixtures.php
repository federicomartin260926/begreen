<?php

namespace App\DataFixtures;

use App\Entity\Department;
use App\Entity\Position;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;

class DepartmentPositionFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['auxiliary'];
    }

    public function load(ObjectManager $manager): void
    {
         /** @var EntityManagerInterface $em */
        $em = $manager;
        $deptRepo = $em->getRepository(Department::class);
        $posRepo  = $em->getRepository(Position::class);

        // 1) LIMPIEZA: eliminar TODO lo que NO sea de eventos (preserva 'evento')
        $qb = $em->createQueryBuilder();
        /** @var Department[] $toDelete */
        $toDelete = $qb->select('d')
            ->from(Department::class, 'd')
            ->where($qb->expr()->orX('d.projectType IS NULL', 'd.projectType <> :evento'))
            ->setParameter('evento', 'evento')
            ->getQuery()
            ->getResult();

        if ($toDelete) {
            foreach ($toDelete as $dept) {
                foreach ($posRepo->findBy(['department' => $dept]) as $p) {
                    $em->remove($p);
                }
                $em->remove($dept);
            }
            $em->flush();
        }

        // 2) ALTAS: Departamentos nuevos (Rodaje)
        // Nota: NO tocamos los de 'evento' existentes.
        $departments = [
            // --- Eventos ---
            ['name' => 'Escenario',                'projectType' => 'evento', 'sortOrder' => 10],
            ['name' => 'Proveedores',              'projectType' => 'evento', 'sortOrder' => 20],
            ['name' => 'Seguridad',                'projectType' => 'evento', 'sortOrder' => 30],
            ['name' => 'Dirección',                'projectType' => 'evento', 'sortOrder' => 40],
            ['name' => 'Producción',               'projectType' => 'evento', 'sortOrder' => 50],
            ['name' => 'Sostenibilidad',           'projectType' => 'evento', 'sortOrder' => 60],
            ['name' => 'Compras y Contratación',   'projectType' => 'evento', 'sortOrder' => 70],
            ['name' => 'Administración y Finanzas','projectType' => 'evento', 'sortOrder' => 80],
            ['name' => 'Comunicación y Marketing', 'projectType' => 'evento', 'sortOrder' => 90],
            ['name' => 'Prensa',                   'projectType' => 'evento', 'sortOrder' => 100],
            ['name' => 'Programación y Contenidos','projectType' => 'evento', 'sortOrder' => 110],
            ['name' => 'Relaciones Institucionales','projectType' => 'evento', 'sortOrder' => 120],
            ['name' => 'Espacio / Recinto',        'projectType' => 'evento', 'sortOrder' => 130],
            ['name' => 'Montaje y Escenografía',   'projectType' => 'evento', 'sortOrder' => 140],
            ['name' => 'Técnica (Sonido, Luz, AV)','projectType' => 'evento', 'sortOrder' => 150],
            ['name' => 'Energía e Instalaciones',  'projectType' => 'evento', 'sortOrder' => 160],
            ['name' => 'Catering',                 'projectType' => 'evento', 'sortOrder' => 170],
            ['name' => 'Logística y Transporte',   'projectType' => 'evento', 'sortOrder' => 180],
            ['name' => 'Alojamiento y Viajes',     'projectType' => 'evento', 'sortOrder' => 190],
            ['name' => 'Limpieza y Residuos',      'projectType' => 'evento', 'sortOrder' => 200],
            ['name' => 'Accesibilidad e Inclusión','projectType' => 'evento', 'sortOrder' => 210],
            ['name' => 'Atención al Público',      'projectType' => 'evento', 'sortOrder' => 220],
            ['name' => 'Voluntariado y RRHH',      'projectType' => 'evento', 'sortOrder' => 230],
            ['name' => 'Expositores y Patrocinadores', 'projectType' => 'evento', 'sortOrder' => 240],

            // --- Rodajes ---
            ['name' => 'Producción',              'projectType' => 'rodaje', 'sortOrder' => 40],
            ['name' => 'Dirección',               'projectType' => 'rodaje', 'sortOrder' => 50],
            ['name' => 'Fotografía y Cámara',     'projectType' => 'rodaje', 'sortOrder' => 60],
            ['name' => 'Eléctrico',               'projectType' => 'rodaje', 'sortOrder' => 70],
            ['name' => 'Maquinista y Grip',       'projectType' => 'rodaje', 'sortOrder' => 80],
            ['name' => 'Sonido',                  'projectType' => 'rodaje', 'sortOrder' => 90],
            ['name' => 'Arte',                    'projectType' => 'rodaje', 'sortOrder' => 100],
            ['name' => 'Construcción',            'projectType' => 'rodaje', 'sortOrder' => 110],
            ['name' => 'Vestuario',               'projectType' => 'rodaje', 'sortOrder' => 120],
            ['name' => 'Maquillaje y Peluquería', 'projectType' => 'rodaje', 'sortOrder' => 130],
            ['name' => 'SFX',                     'projectType' => 'rodaje', 'sortOrder' => 140],
            ['name' => 'Localizaciones',          'projectType' => 'rodaje', 'sortOrder' => 150],
            ['name' => 'Transporte',              'projectType' => 'rodaje', 'sortOrder' => 160],
            ['name' => 'Atrezzo',                 'projectType' => 'rodaje', 'sortOrder' => 170],
            ['name' => 'Casting',                 'projectType' => 'rodaje', 'sortOrder' => 180],
            ['name' => 'Catering',                'projectType' => 'rodaje', 'sortOrder' => 190],
            ['name' => 'Home Economist',          'projectType' => 'rodaje', 'sortOrder' => 200],
            ['name' => 'Postproducción',          'projectType' => 'rodaje', 'sortOrder' => 210],
            ['name' => 'Contabilidad',            'projectType' => 'rodaje', 'sortOrder' => 220],
            ['name' => 'Sostenibilidad',          'projectType' => 'rodaje', 'sortOrder' => 230],
            ['name' => 'Veterinario y Animales',  'projectType' => 'rodaje', 'sortOrder' => 240],
            ['name' => 'Guion y Dirección',       'projectType' => 'rodaje', 'sortOrder' => 250],
        ];

        // upsert department
        $upsertDepartment = function(string $name, ?string $projectType, int $sortOrder) use ($deptRepo, $em): Department {
            $dept = $deptRepo->findOneBy(['name' => $name, 'projectType' => $projectType]);
            if (!$dept) {
                $dept = new Department();
                $dept->setName($name)->setProjectType($projectType);
                $em->persist($dept);
            }

            $dept->setSortOrder($sortOrder);
            return $dept;
        };

        /** @var array<string, Department> $deptIndex */
        $deptIndex = [];
        foreach ($departments as $d) {
            $dept = $upsertDepartment($d['name'], $d['projectType'], $d['sortOrder']);
            $deptIndex[$d['name']] = $dept;
        }
        $em->flush();

        // 3) ALTAS: Puestos por departamento (typos/normalizaciones aplicadas)
        $positionsByDept = [

            // === Localizaciones ===
            'Localizaciones' => [
                'Jefe/a de Localizaciones',
                'Localizador/a',
                'Auxiliar de Localizaciones',
            ],

            // === Catering ===
            'Catering' => [
                'Jefe/a de Catering',
                'Ayudante de Catering',
            ],

            // === Transporte ===
            'Transporte' => [
                'Jefe/a de Transporte',
                'Conductor/a',
                'Coordinador/a de Flota',
            ],

            // === Escenario ===
            'Escenario' => [
                'Stage Manager',
                'Técnico de Escenario',
            ],

            // === Producción ===
            'Producción' => [
                'Director/a de Producción',
                'Jefe/a de Producción',
                'Ayudante de Producción',
                'Auxiliar de Producción',
                'Secretario/a de Producción', // antes: Secretaria de Producción
            ],

            // === Dirección ===
            'Dirección' => [
                'Primer Ayudante de Dirección',
                'Segundo Ayudante de Dirección',
                'Auxiliar de Dirección',
            ],

            // === Guion y Dirección ===
            'Guion y Dirección' => [
                'Script Supervisor / Supervisor de Continuidad',
                'Coordinador/a de Guiones',
                'Guionista de Ficción',
                'Guionista de No Ficción',
            ],

            // === Casting ===
            'Casting' => [
                'Director/a de Casting',
                'Ayudante de Casting',
            ],

            // === Fotografía y Cámara ===
            'Fotografía y Cámara' => [
                'Director/a de Fotografía',
                'Operador/a Especialista de Cámara de Vídeo (steadycam, aéreo, submarina)',
                'Operador/a Reportero/a de Cámara de Vídeo',
                'Operador/a de Cámara',
                'Primer Ayudante de Cámara / Foquista (1º AC)',
                'Foto Fija',
                'Operador/a de Cámara de Vídeo',
                'Ayudante de Cámara de Vídeo',
                'Auxiliar de Cámara',
                'D.I.T.',
            ],

            // === Sonido ===
            'Sonido' => [
                'Jefe/a de Sonido',
                'Operador/a de Sonido',
                'Ayudante de Sonido',
                'Auxiliar de Sonido',
                'Microfonista / Boom Operator',
            ],

            // === Eléctrico ===
            'Eléctrico' => [
                'Gaffer (Jefe/a de Eléctricos)',
                'Eléctrico/a',
                'Ayudante de Eléctrico',
            ],

            // === Maquinista y Grip ===
            'Maquinista y Grip' => [
                'Jefe/a de Maquinistas',
                'Maquinista / Gruista',
                'Ayudante de Maquinista',
                'Key Grip',
                'Best Boy Grip',
                'Grip',
            ],

            // === Arte ===
            'Arte' => [
                'Director/a de Arte',
                'Decorador/a',
                'Ayudante de Decoración',
            ],

            // === Atrezzo ===
            'Atrezzo' => [
                'Atrecista / Atrezzista',
                'Ayudante de Atrezzo',
            ],

            // === Construcción ===
            'Construcción' => [
                'Jefe/a de Construcción',
                'Jefe/a de Carpintería',           // FIX “Jede” → “Jefe/a”
                'Jefe/a de Pintura/Empapelado',    // FIX “Epapelado” → “Empapelado”
                'Jefe/a de Modelaje',
                'Constructor/a de Atrezzo',
                'Carpintero/a',
                'Pintor/a / Empapelador/a',
                'Modelador/a',
                'Estructurista',
            ],

            // === Vestuario ===
            'Vestuario' => [
                'Estilista',
                'Figurinista',
                'Estilista / Figurinista',
                'Ayudante de Estilismo / Ayudante de Figurinista',
                'Jefe/a de Sastrería',
                'Sastre/a', // FIX “Satre”
                'Auxiliar de Estilismo / Auxiliar de Figurinista',
            ],

            // === Maquillaje y Peluquería ===
            'Maquillaje y Peluquería' => [
                'Jefe/a de Maquillaje',
                'Maquillador/a',
                'Ayudante de Maquillaje',
                'Auxiliar de Maquillaje',
                'Jefe/a de Peluquería',
                'Peluquero/a',
                'Ayudante de Peluquería',
                'Auxiliar de Peluquería',
            ],

            // === SFX ===
            'SFX' => [
                'Técnico/a de SFX',
                'Supervisor/a de SFX',
            ],

            // === Postproducción ===
            'Postproducción' => [
                'Editor/a Montador/a de Vídeo',
                'Editor/a de Vídeo',
                'Operador/a de VTR',
                'Editor/a de Audio',
                'Ayudante de Edición de Audio',
                'Montador/a de Imagen',
                'Ayudante de Montaje de Imagen',
                'Auxiliar de Montaje de Imagen',
                'Montador/a de Sonido',
                'Ayudante de Montaje de Sonido',
            ],

            // === Contabilidad ===
            'Contabilidad' => [
                'Contable de Producción',
                'Ayudante de Contabilidad',
                'Cajero/a - Pagador/a',
                'Auxiliar Administrativo/a',
                'Meritorio/a',
            ],

            // === Sostenibilidad ===
            'Sostenibilidad' => [
                'Responsable de Sostenibilidad',
            ],

            // === Veterinario y Animales ===
            'Veterinario y Animales' => [
                'Veterinario/a',
                'Cuidador/a de Animales',
            ],

            // === Home Economist ===
            'Home Economist' => [
                'Home Economist',
            ],
        ];

        // upsert position
        $upsertPosition = function(Department $dept, string $name) use ($posRepo, $em): Position {
            $pos = $posRepo->findOneBy(['department' => $dept, 'name' => $name]);
            if (!$pos) {
                $pos = new Position();
                $pos->setDepartment($dept)->setName($name);
                $em->persist($pos);
            }
            return $pos;
        };

        foreach ($positionsByDept as $deptName => $positions) {
            if (!isset($deptIndex[$deptName])) {
                // si cambiaste algún nombre de departamento, evita romper carga silenciosamente
                continue;
            }
            $dept = $deptIndex[$deptName];
            foreach ($positions as $pName) {
                $upsertPosition($dept, $pName);
            }
        }

        $em->flush();
    }
}
