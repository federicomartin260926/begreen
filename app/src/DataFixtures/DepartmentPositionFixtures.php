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

        // 2) ALTAS: Departamentos nuevos (Rodajes / Genérico)
        // Nota: NO tocamos los de 'evento' existentes.
        $departments = [
            // --- Eventos ---
            ['name' => 'Escenario',                'projectType' => 'evento'],
            ['name' => 'Proveedores',              'projectType' => 'evento'],
            ['name' => 'Seguridad',                'projectType' => 'evento'],

            // --- Rodajes ---
            ['name' => 'Localizaciones',          'projectType' => 'rodaje'],
            ['name' => 'Producción',              'projectType' => 'rodaje'],
            ['name' => 'Dirección',               'projectType' => 'rodaje'],
            ['name' => 'Realización',             'projectType' => 'rodaje'],
            ['name' => 'Redacción',               'projectType' => 'rodaje'],
            ['name' => 'Casting',                 'projectType' => 'rodaje'],
            ['name' => 'Cámara',                  'projectType' => 'rodaje'],
            ['name' => 'Sonido',                  'projectType' => 'rodaje'],
            ['name' => 'Iluminación',             'projectType' => 'rodaje'], // alias de Eléctricos
            ['name' => 'Maquinistas',             'projectType' => 'rodaje'], // alias de Grip
            ['name' => 'Decoración',              'projectType' => 'rodaje'],
            ['name' => 'Ambientación',            'projectType' => 'rodaje'],
            ['name' => 'Atrezzo',                 'projectType' => 'rodaje'],
            ['name' => 'Construcción decorados',  'projectType' => 'rodaje'],
            ['name' => 'Estilismo',               'projectType' => 'rodaje'],
            ['name' => 'Maquillaje',              'projectType' => 'rodaje'],
            ['name' => 'Peluquería',              'projectType' => 'rodaje'],

            // --- Genérico (post/soporte) ---
            ['name' => 'Edición de Vídeo',        'projectType' => null],
            ['name' => 'Edición de Audio',        'projectType' => null],
            ['name' => 'Montaje',                 'projectType' => null],
            ['name' => 'Catering',                'projectType' => null],
            ['name' => 'Transporte',              'projectType' => null],
            ['name' => 'Equipo Técnico (AV)',     'projectType' => null],
            ['name' => 'Guionistas',              'projectType' => null],
            ['name' => 'Contabilidad',            'projectType' => null],
        ];

        // upsert department
        $upsertDepartment = function(string $name, ?string $projectType) use ($deptRepo, $em): Department {
            $dept = $deptRepo->findOneBy(['name' => $name, 'projectType' => $projectType]);
            if (!$dept) {
                $dept = new Department();
                $dept->setName($name)->setProjectType($projectType);
                $em->persist($dept);
            }
            return $dept;
        };

        /** @var array<string, Department> $deptIndex */
        $deptIndex = [];
        foreach ($departments as $d) {
            $dept = $upsertDepartment($d['name'], $d['projectType']);
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

            // === Casting ===
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
                'Script Supervisor / Supervisor de Continuidad',
            ],

            // === Realización ===
            'Realización' => [
                'Realizador/a',
                'Ayudante de Realización',
                'Regidor/a',
                'Auxiliar de Realización',
            ],

            // === Redacción ===
            'Redacción' => [
                'Jefe/a de Redacción',
                'Redactor/a / Comentarista / Tertuliano/a / Colaborador/a',
                'Documentalista',
                'Ayudante de Redacción',
                'Secretario/a de Redacción',
            ],

            // === Casting ===
            'Casting' => [
                'Director/a de Casting',
                'Ayudante de Casting',
            ],

            // === Cámara ===
            'Cámara' => [
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

            // === Iluminación ===
            'Iluminación' => [
                'Gaffer (Jefe/a de Eléctricos)',
                'Eléctrico/a',
                'Ayudante de Eléctrico',
            ],

            // === Maquinistas ===
            'Maquinistas' => [
                'Jefe/a de Maquinistas',
                'Maquinista / Gruista',
                'Ayudante de Maquinista',
                'Key Grip',
                'Best Boy Grip',
                'Grip',
            ],

            // === Decoración ===
            'Decoración' => [
                'Director/a de Arte',
                'Decorador/a',
                'Regidor/a', // si prefieres tenerlo solo en Realización, elimínalo aquí
                'Ayudante de Decoración',
            ],

            // === Ambientación ===
            'Ambientación' => [
                'Ambientador/a',
                'Ayudante de Ambientación',
                'Auxiliar de Ambientación',
                'Asistencia de Rodaje',
            ],

            // === Atrezzo ===
            'Atrezzo' => [
                'Atrecista / Atrezzista',
                'Ayudante de Atrezzo',
            ],

            // === Construcción decorados ===
            'Construcción decorados' => [
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

            // === Estilismo ===
            'Estilismo' => [
                'Estilista',
                'Figurinista',
                'Estilista / Figurinista',
                'Ayudante de Estilismo / Ayudante de Figurinista',
                'Jefe/a de Sastrería',
                'Sastre/a', // FIX “Satre”
                'Auxiliar de Estilismo / Auxiliar de Figurinista',
            ],

            // === Maquillaje ===
            'Maquillaje' => [
                'Jefe/a de Maquillaje',
                'Maquillador/a',
                'Ayudante de Maquillaje',
                'Auxiliar de Maquillaje',
            ],

            // === Peluquería ===
            'Peluquería' => [
                'Jefe/a de Peluquería',
                'Peluquero/a',
                'Ayudante de Peluquería',
                'Auxiliar de Peluquería',
            ],

            // === Genérico ===
            'Edición de Vídeo' => [
                'Editor/a Montador/a de Vídeo',
                'Editor/a de Vídeo',
                'Operador/a de VTR',
            ],
            'Edición de Audio' => [
                'Editor/a de Audio',
                'Ayudante de Edición de Audio',
            ],
            'Montaje' => [
                'Montador/a de Imagen',
                'Ayudante de Montaje de Imagen',
                'Auxiliar de Montaje de Imagen',
                'Montador/a de Sonido',
                'Ayudante de Montaje de Sonido',
            ],
            'Equipo Técnico (AV)' => [
                'Jefe/a Técnico/a',
                'Técnico/a de Audio Vídeo',
                'Ayudante Técnico/a de Audio Vídeo',
            ],
            'Guionistas' => [
                'Coordinador/a de Guiones',
                'Guionista de Ficción',
                'Guionista de No Ficción', // FIX
            ],
            'Contabilidad (Administración y Servicios Generales)' => [
                'Contable de Producción',
                'Ayudante de Contabilidad',
                'Cajero/a - Pagador/a',
                'Auxiliar Administrativo/a',
                'Meritorio/a',
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
