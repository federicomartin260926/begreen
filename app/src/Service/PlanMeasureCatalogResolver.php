<?php

namespace App\Service;

use App\Entity\Measure;
use App\Entity\Protocol;
use App\Entity\Project;
use App\Enum\CommercialPhase;
use App\Service\ProjectFeatureGate;
use Doctrine\ORM\QueryBuilder;

final class PlanMeasureCatalogResolver
{
    public const BE_GREEN_MY_FILM_CODE = 'be-green-my-film';
    public const BE_GREEN_MY_EVENT_CODE = 'be-green-my-event';
    public const CATALOG_IMPORT_VERSION = 'v23';
    public const BE_GREEN_MY_FILM_IMPORT_VERSION = self::CATALOG_IMPORT_VERSION;
    public const BE_GREEN_MY_EVENT_IMPORT_VERSION = self::CATALOG_IMPORT_VERSION;

    private const IMPORT_VERSIONS_BY_PROTOCOL = [
        self::BE_GREEN_MY_FILM_CODE => self::CATALOG_IMPORT_VERSION,
        self::BE_GREEN_MY_EVENT_CODE => self::CATALOG_IMPORT_VERSION,
    ];

    public function __construct(private readonly ProjectFeatureGate $featureGate)
    {
    }

    public function isCanonicalProtocol(?Protocol $protocol): bool
    {
        return $this->getImportVersionForProtocol($protocol) !== null;
    }

    public function getImportVersionForProtocol(?Protocol $protocol): ?string
    {
        $code = $protocol?->getCode();

        return $code !== null ? (self::IMPORT_VERSIONS_BY_PROTOCOL[$code] ?? null) : null;
    }

    public function applyCatalogFilter(QueryBuilder $qb, string $measureAlias, string $protocolAlias, ?Project $project = null): void
    {
        $qb->andWhere(sprintf('(COALESCE(%s.code, \'\') NOT IN (:catalogProtocolCodes) OR (%s.importVersion = :catalogImportVersion%s))', $protocolAlias, $measureAlias, $project ? ' AND ' . $measureAlias . '.score IN (:catalogAllowedScores)' : ''))
            ->setParameter('catalogProtocolCodes', array_keys(self::IMPORT_VERSIONS_BY_PROTOCOL))
            ->setParameter('catalogImportVersion', self::CATALOG_IMPORT_VERSION);

        if ($project) {
            $qb->setParameter('catalogAllowedScores', $this->featureGate->getAllowedScores($project, CommercialPhase::ELABORATION));
        }
    }

    public function isCatalogMeasure(Measure $measure, ?Project $project = null): bool
    {
        $protocol = $measure->getProtocol();
        if (!$this->isCanonicalProtocol($protocol)) {
            return true;
        }

        if ($measure->getImportVersion() !== $this->getImportVersionForProtocol($protocol)) {
            return false;
        }

        return $project ? in_array((int) ($measure->getScore() ?? 0), $this->featureGate->getAllowedScores($project, CommercialPhase::ELABORATION), true) : true;
    }
}
