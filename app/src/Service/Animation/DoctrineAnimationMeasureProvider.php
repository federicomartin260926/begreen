<?php

namespace App\Service\Animation;

use App\Entity\Measure;
use App\Entity\Protocol;
use App\Repository\MeasureRepository;
use Doctrine\Persistence\ManagerRegistry;

final readonly class DoctrineAnimationMeasureProvider implements AnimationMeasureProvider
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function forProtocol(Protocol $protocol): array
    {
        $repository = $this->registry->getRepository(Measure::class);
        if (!$repository instanceof MeasureRepository) {
            throw new \LogicException('No está disponible el repositorio de medidas Animation.');
        }

        return $repository->getAnimationCatalogMeasures($protocol);
    }
}
