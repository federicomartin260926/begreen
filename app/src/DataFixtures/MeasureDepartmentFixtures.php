<?php

namespace App\DataFixtures;

use App\Entity\Department;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

final class MeasureDepartmentFixtures extends Fixture implements FixtureGroupInterface
{
    /** @var list<array{name: string, projectType: string, sortOrder: int}> */
    private const DEPARTMENTS = [
        ['name' => 'Escenario',                 'projectType' => 'evento', 'sortOrder' => 10],
        ['name' => 'Proveedores',               'projectType' => 'evento', 'sortOrder' => 20],
        ['name' => 'Seguridad',                 'projectType' => 'evento', 'sortOrder' => 30],
        ['name' => 'Dirección',                 'projectType' => 'evento', 'sortOrder' => 40],
        ['name' => 'Producción',                'projectType' => 'evento', 'sortOrder' => 50],
        ['name' => 'Sostenibilidad',            'projectType' => 'evento', 'sortOrder' => 60],
        ['name' => 'Compras y Contratación',    'projectType' => 'evento', 'sortOrder' => 70],
        ['name' => 'Administración y Finanzas', 'projectType' => 'evento', 'sortOrder' => 80],
        ['name' => 'Comunicación y Marketing',  'projectType' => 'evento', 'sortOrder' => 90],
        ['name' => 'Prensa',                    'projectType' => 'evento', 'sortOrder' => 100],
        ['name' => 'Programación y Contenidos', 'projectType' => 'evento', 'sortOrder' => 110],
        ['name' => 'Relaciones Institucionales','projectType' => 'evento', 'sortOrder' => 120],
        ['name' => 'Espacio / Recinto',         'projectType' => 'evento', 'sortOrder' => 130],
        ['name' => 'Montaje y Escenografía',    'projectType' => 'evento', 'sortOrder' => 140],
        ['name' => 'Técnica (Sonido, Luz, AV)', 'projectType' => 'evento', 'sortOrder' => 150],
        ['name' => 'Energía e Instalaciones',   'projectType' => 'evento', 'sortOrder' => 160],
        ['name' => 'Catering',                  'projectType' => 'evento', 'sortOrder' => 170],
        ['name' => 'Logística y Transporte',    'projectType' => 'evento', 'sortOrder' => 180],
        ['name' => 'Alojamiento y Viajes',      'projectType' => 'evento', 'sortOrder' => 190],
        ['name' => 'Limpieza y Residuos',       'projectType' => 'evento', 'sortOrder' => 200],
        ['name' => 'Accesibilidad e Inclusión', 'projectType' => 'evento', 'sortOrder' => 210],
        ['name' => 'Atención al Público',       'projectType' => 'evento', 'sortOrder' => 220],
        ['name' => 'Voluntariado y RRHH',       'projectType' => 'evento', 'sortOrder' => 230],
        ['name' => 'Expositores y Patrocinadores', 'projectType' => 'evento', 'sortOrder' => 240],

        ['name' => 'Producción',               'projectType' => 'rodaje', 'sortOrder' => 40],
        ['name' => 'Dirección',                'projectType' => 'rodaje', 'sortOrder' => 50],
        ['name' => 'Fotografía y Cámara',      'projectType' => 'rodaje', 'sortOrder' => 60],
        ['name' => 'Eléctrico',                'projectType' => 'rodaje', 'sortOrder' => 70],
        ['name' => 'Maquinista y Grip',        'projectType' => 'rodaje', 'sortOrder' => 80],
        ['name' => 'Sonido',                   'projectType' => 'rodaje', 'sortOrder' => 90],
        ['name' => 'Arte',                     'projectType' => 'rodaje', 'sortOrder' => 100],
        ['name' => 'Construcción',             'projectType' => 'rodaje', 'sortOrder' => 110],
        ['name' => 'Vestuario',                'projectType' => 'rodaje', 'sortOrder' => 120],
        ['name' => 'Maquillaje y Peluquería',  'projectType' => 'rodaje', 'sortOrder' => 130],
        ['name' => 'SFX',                      'projectType' => 'rodaje', 'sortOrder' => 140],
        ['name' => 'Localizaciones',           'projectType' => 'rodaje', 'sortOrder' => 150],
        ['name' => 'Transporte',               'projectType' => 'rodaje', 'sortOrder' => 160],
        ['name' => 'Atrezzo',                  'projectType' => 'rodaje', 'sortOrder' => 170],
        ['name' => 'Casting',                  'projectType' => 'rodaje', 'sortOrder' => 180],
        ['name' => 'Catering',                 'projectType' => 'rodaje', 'sortOrder' => 190],
        ['name' => 'Home Economist',           'projectType' => 'rodaje', 'sortOrder' => 200],
        ['name' => 'Postproducción',           'projectType' => 'rodaje', 'sortOrder' => 210],
        ['name' => 'Contabilidad',             'projectType' => 'rodaje', 'sortOrder' => 220],
        ['name' => 'Sostenibilidad',           'projectType' => 'rodaje', 'sortOrder' => 230],
        ['name' => 'Veterinario y Animales',   'projectType' => 'rodaje', 'sortOrder' => 240],
        ['name' => 'Guion y Dirección',        'projectType' => 'rodaje', 'sortOrder' => 250],
    ];

    public static function getGroups(): array
    {
        return ['auxiliary'];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $manager;
        $repository = $entityManager->getRepository(Department::class);
        $queryBuilder = $entityManager->createQueryBuilder();
        $departmentsToDelete = $queryBuilder
            ->select('d')
            ->from(Department::class, 'd')
            ->where($queryBuilder->expr()->orX('d.projectType IS NULL', 'd.projectType <> :event'))
            ->setParameter('event', 'evento')
            ->getQuery()
            ->getResult();

        foreach ($departmentsToDelete as $department) {
            $entityManager->remove($department);
        }
        if ($departmentsToDelete !== []) {
            $entityManager->flush();
        }

        foreach (self::DEPARTMENTS as $definition) {
            $department = $repository->findOneBy([
                'name' => $definition['name'],
                'projectType' => $definition['projectType'],
            ]);
            if (!$department instanceof Department) {
                $department = (new Department())
                    ->setName($definition['name'])
                    ->setProjectType($definition['projectType']);
                $entityManager->persist($department);
            }

            $department->setSortOrder($definition['sortOrder']);
        }

        $entityManager->flush();
    }
}
