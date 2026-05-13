<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Protocol;
use Doctrine\ORM\QueryBuilder;

final class PlanMeasureCatalogResolver
{
    public const BE_GREEN_MY_FILM_CODE = 'be-green-my-film';
    public const BE_GREEN_MY_FILM_IMPORT_VERSION = 'v23';

    public function isCanonicalProtocol(?Protocol $protocol): bool
    {
        return $protocol?->getCode() === self::BE_GREEN_MY_FILM_CODE;
    }

    public function getImportVersionForProtocol(?Protocol $protocol): ?string
    {
        return $this->isCanonicalProtocol($protocol) ? self::BE_GREEN_MY_FILM_IMPORT_VERSION : null;
    }

    public function applyCatalogFilter(QueryBuilder $qb, string $measureAlias, string $protocolAlias): void
    {
        $qb->andWhere(sprintf('(COALESCE(%s.code, \'\') <> :catalogProtocolCode OR %s.importVersion = :catalogImportVersion)', $protocolAlias, $measureAlias))
            ->setParameter('catalogProtocolCode', self::BE_GREEN_MY_FILM_CODE)
            ->setParameter('catalogImportVersion', self::BE_GREEN_MY_FILM_IMPORT_VERSION);
    }

    public function isCatalogMeasure(Measure $measure): bool
    {
        $protocol = $measure->getProtocol();
        if (!$this->isCanonicalProtocol($protocol)) {
            return true;
        }

        return $measure->getImportVersion() === self::BE_GREEN_MY_FILM_IMPORT_VERSION;
    }
}
