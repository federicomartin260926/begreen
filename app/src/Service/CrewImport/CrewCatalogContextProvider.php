<?php

namespace App\Service\CrewImport;

use App\Entity\CrewDepartment;
use App\Entity\CrewPosition;
use App\Entity\Project;
use App\Exception\CrewImport\MissingCrewCatalogTranslationException;
use App\Repository\CrewDepartmentRepository;
use App\Repository\CrewPositionRepository;
use App\Service\CrewCatalogScopeResolver;
use Doctrine\Persistence\ManagerRegistry;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use Gedmo\Translatable\Entity\Translation;

final readonly class CrewCatalogContextProvider
{
    public function __construct(
        private CrewCatalogScopeResolver $scopeResolver,
        private CrewDepartmentRepository $departmentRepository,
        private CrewPositionRepository $positionRepository,
        private ManagerRegistry $doctrine,
    ) {
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: array{es: string, en: string},
     *     positions: list<array{id: int, name: array{es: string, en: string}}>
     * }>
     */
    public function provide(Project $project): array
    {
        $translationRepository = $this->translationRepository();
        $context = [];

        foreach ($this->departmentRepository->findByScope($this->scopeResolver->resolve($project)) as $department) {
            $departmentId = $department->getId();
            if ($departmentId === null) {
                throw new \LogicException('Crew catalog departments must be persisted before building context.');
            }

            $positions = [];
            foreach ($this->positionRepository->findByCrewDepartment($department) as $position) {
                $positionId = $position->getId();
                if ($positionId === null) {
                    throw new \LogicException('Crew catalog positions must be persisted before building context.');
                }

                $positions[] = [
                    'id' => $positionId,
                    'name' => $this->names(
                        $position,
                        $this->positionRepository->canonicalName($positionId),
                        $translationRepository
                    ),
                ];
            }

            $context[] = [
                'id' => $departmentId,
                'name' => $this->names(
                    $department,
                    $this->departmentRepository->canonicalName($departmentId),
                    $translationRepository
                ),
                'positions' => $positions,
            ];
        }

        return $context;
    }

    /** @return array{es: string, en: string} */
    private function names(
        CrewDepartment|CrewPosition $entity,
        string $spanish,
        TranslationRepository $translationRepository,
    ): array {
        $spanish = trim($spanish);
        if ($spanish === '') {
            throw new \LogicException(sprintf('Missing canonical Spanish name for %s.', $entity::class));
        }

        $english = trim((string) ($translationRepository->findTranslations($entity)['en']['name'] ?? ''));
        if ($english === '') {
            throw MissingCrewCatalogTranslationException::forEntity($entity);
        }

        return [
            'es' => $spanish,
            'en' => $english,
        ];
    }

    private function translationRepository(): TranslationRepository
    {
        $repository = $this->doctrine->getRepository(Translation::class);
        if (!$repository instanceof TranslationRepository) {
            throw new \LogicException('The crew catalog translation repository is not available.');
        }

        return $repository;
    }
}
