<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Service\ProjectFeatureGate;
use Doctrine\ORM\QueryBuilder;

final class PlanMeasureCatalogResolver
{
    public const BE_GREEN_MY_FILM_CODE = 'be-green-my-film';
    public const BE_GREEN_MY_FILM_IMPORT_VERSION = 'v23';

    public function __construct(private readonly ProjectFeatureGate $featureGate)
    {
    }

    public function isCanonicalProtocol(?Protocol $protocol): bool
    {
        return $protocol?->getCode() === self::BE_GREEN_MY_FILM_CODE;
    }

    public function getImportVersionForProtocol(?Protocol $protocol): ?string
    {
        return $this->isCanonicalProtocol($protocol) ? self::BE_GREEN_MY_FILM_IMPORT_VERSION : null;
    }

    public function applyCatalogFilter(QueryBuilder $qb, string $measureAlias, string $protocolAlias, ?Project $project = null): void
    {
        $qb->andWhere(sprintf('(COALESCE(%s.code, \'\') <> :catalogProtocolCode OR (%s.importVersion = :catalogImportVersion%s))', $protocolAlias, $measureAlias, $project ? ' AND ' . $measureAlias . '.score IN (:catalogAllowedScores)' : ''))
            ->setParameter('catalogProtocolCode', self::BE_GREEN_MY_FILM_CODE)
            ->setParameter('catalogImportVersion', self::BE_GREEN_MY_FILM_IMPORT_VERSION);

        if ($project) {
            $qb->setParameter('catalogAllowedScores', $this->featureGate->getAllowedScores($project));
        }
    }

    public function isCatalogMeasure(Measure $measure, ?Project $project = null): bool
    {
        $protocol = $measure->getProtocol();
        if (!$this->isCanonicalProtocol($protocol)) {
            return true;
        }

        if ($measure->getImportVersion() !== self::BE_GREEN_MY_FILM_IMPORT_VERSION) {
            return false;
        }

        return $project ? in_array((int) ($measure->getScore() ?? 0), $this->featureGate->getAllowedScores($project), true) : true;
    }
}
