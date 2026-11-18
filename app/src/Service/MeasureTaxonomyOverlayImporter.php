<?php

namespace App\Service;

use App\Entity\Department;
use App\Entity\Measure;
use App\Entity\MeasureVerificationSource;
use App\Entity\Protocol;
use App\Entity\VerificationSource;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class MeasureTaxonomyOverlayImporter
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array{protocolCode:string, departments:list<string>, rows:list<array{number:int,catalogId:?string,departments:list<string>,evidences:array<string,string>}>,departmentLinks:int,evidenceLinks:int} $overlay
     * @return array{measures:int,departmentLinks:int,evidenceLinks:int}
     */
    public function import(array $overlay): array
    {
        $protocol = $this->em->getRepository(Protocol::class)->findOneBy(['code' => $overlay['protocolCode']]);
        if (!$protocol instanceof Protocol) {
            throw new RuntimeException(sprintf('Protocolo no encontrado: "%s".', $overlay['protocolCode']));
        }

        $departments = [];
        foreach ($overlay['departments'] as $name) {
            $matches = $this->em->getRepository(Department::class)->findBy(['name' => $name, 'projectType' => $protocol->getType()]);
            if (count($matches) !== 1) {
                throw new RuntimeException(sprintf('Departamento no resuelto de forma inequívoca para %s: "%s".', $protocol->getCode(), $name));
            }
            $departments[$name] = $matches[0];
        }

        $sources = [];
        foreach (VerificationSource::CANONICAL_SOURCES as $code => $name) {
            $source = $this->em->getRepository(VerificationSource::class)->findOneBy(['code' => $code]);
            if (!$source instanceof VerificationSource || $source->getName() !== $name) {
                throw new RuntimeException(sprintf('Fuente EVI canónica no resuelta: "%s".', $code));
            }
            $sources[$code] = $source;
        }

        $operations = [];
        foreach ($overlay['rows'] as $row) {
            $criteria = $row['catalogId'] !== null
                ? ['protocol' => $protocol, 'catalogId' => $row['catalogId']]
                : ['protocol' => $protocol, 'sourceRow' => $row['number'] + 2];
            $matches = $this->em->getRepository(Measure::class)->findBy($criteria);
            if (count($matches) !== 1) {
                throw new RuntimeException(sprintf('Medida %d no resuelta de forma inequívoca para %s.', $row['number'], $protocol->getCode()));
            }
            $operations[] = ['measure' => $matches[0], 'row' => $row];
        }

        $this->em->wrapInTransaction(function () use ($operations, $departments, $sources): void {
            foreach ($operations as $operation) {
                /** @var Measure $measure */
                $measure = $operation['measure'];
                $row = $operation['row'];

                foreach ($measure->getDepartments()->toArray() as $department) {
                    $measure->removeDepartment($department);
                }
                foreach ($row['departments'] as $name) {
                    $measure->addDepartment($departments[$name]);
                }
                $measure->setDepartment($measure->getPrimaryDepartment());

                foreach ($measure->getVerificationSourceLinks()->toArray() as $link) {
                    $measure->removeVerificationSourceLink($link);
                }
                foreach ($row['evidences'] as $code => $quality) {
                    $measure->addVerificationSourceLink(
                        (new MeasureVerificationSource())
                            ->setVerificationSource($sources[$code])
                            ->setSuggestedQuality($quality)
                    );
                }
            }

            $this->em->flush();
        });

        return [
            'measures' => count($operations),
            'departmentLinks' => $overlay['departmentLinks'],
            'evidenceLinks' => $overlay['evidenceLinks'],
        ];
    }
}
