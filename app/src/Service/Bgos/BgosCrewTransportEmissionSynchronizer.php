<?php

declare(strict_types=1);

namespace App\Service\Bgos;

use App\Entity\BgosCrewTransportJourney;
use App\Entity\BgosCrewTransportSegment;
use App\Entity\Category;
use App\Entity\EmissionRecord;
use App\Entity\Project;
use App\Repository\CategoryRepository;
use App\Repository\ProjectRepository;
use App\Service\Emission\EmissionCountryCatalog;
use App\Service\Emission\Transport\TransportEmissionInput;
use App\Service\Emission\Transport\TransportEmissionRecordService;
use App\Service\Emission\Transport\TransportUiCatalog;
use Doctrine\ORM\EntityManagerInterface;

class BgosCrewTransportEmissionSynchronizer
{
    public function __construct(
        private readonly TransportEmissionRecordService $recordService,
        private readonly TransportUiCatalog $transportCatalog,
        private readonly EmissionCountryCatalog $countryCatalog,
        private readonly CategoryRepository $categoryRepository,
        private readonly ProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @param list<BgosCrewTransportSegment> $removedSegments */
    public function synchronize(
        BgosCrewTransportJourney $journey,
        array $removedSegments = [],
    ): void {
        $project = $this->journeyProject($journey);
        foreach ($removedSegments as $segment) {
            $this->removeSegmentRecord($project, $segment);
        }

        $segmentsWithDistance = array_values(array_filter(
            $journey->getSegments()->toArray(),
            static fn (BgosCrewTransportSegment $segment): bool => null !== $segment->getDistanceKm(),
        ));
        foreach ($journey->getSegments() as $segment) {
            if (null === $segment->getDistanceKm()) {
                $this->removeSegmentRecord($project, $segment);
            }
        }
        if ([] === $segmentsWithDistance) {
            return;
        }

        $date = $journey->getDate();
        if (!$date instanceof \DateTimeImmutable) {
            throw new \LogicException('A BGoS crew transport journey requires a date before emission synchronization.');
        }
        $category = $this->transportCategory();
        $phase = $this->projectRepository->findPhaseByDate($project, $date);
        if (null === $phase) {
            throw new \LogicException('No project phase is available for the BGoS crew transport journey date.');
        }
        $country = $this->countryCatalog->iso2FromIso3(
            $this->countryCatalog->iso3ForForm((string) $project->getCountry()),
        );

        foreach ($segmentsWithDistance as $segment) {
            $record = $segment->getEmissionRecord();
            $this->assertRecordProject($project, $record);
            $result = $this->recordService->write(
                $project,
                $category,
                $phase,
                $this->input($journey, $segment, $country),
                record: $record,
                presentation: $this->presentation($segment),
            );
            if (!$result->isPersisted()) {
                throw new \LogicException(sprintf(
                    'BGoS crew transport emission could not be persisted: %s.',
                    $result->calculation->status,
                ));
            }
            if (null !== $record && $result->record !== $record) {
                throw new \LogicException('BGoS crew transport emission update did not reuse its linked record.');
            }
            $segment->setEmissionRecord($result->record);
        }
    }

    public function remove(BgosCrewTransportJourney $journey): void
    {
        $project = $this->journeyProject($journey);
        foreach ($journey->getSegments() as $segment) {
            $this->removeSegmentRecord($project, $segment);
        }
    }

    private function input(
        BgosCrewTransportJourney $journey,
        BgosCrewTransportSegment $segment,
        string $country,
    ): TransportEmissionInput {
        $date = $journey->getDate();
        $distance = $segment->getDistanceKm();
        if (!$date instanceof \DateTimeImmutable || null === $distance) {
            throw new \LogicException('A dated segment distance is required for emission synchronization.');
        }

        $mode = $journey->getMode();
        $category = $this->transportCategoryKey($mode);
        $participants = (string) $segment->getParticipants()->count();
        $method = match ($mode) {
            'car', 'taxi', 'urban_bus' => 'distance',
            default => 'passenger_distance',
        };
        $activityValue = 'passenger_distance' === $method
            ? $this->multiplyDistance($distance, $participants)
            : $distance;

        return new TransportEmissionInput(
            $category,
            $mode,
            $method,
            $country,
            $date,
            $date,
            $activityValue,
            'passenger_distance' === $method ? 'passenger-km' : 'km',
            '1',
            'urban_bus' === $mode ? $participants : null,
            vehicleType: $journey->getVehicleType(),
            fuel: $journey->getFuel(),
            thermalFuel: $journey->getThermalFuel(),
        );
    }

    private function transportCategoryKey(string $mode): string
    {
        foreach ($this->transportCatalog->categories() as $category => $modes) {
            if (in_array($mode, $modes, true)) {
                return $category;
            }
        }

        throw new \LogicException(sprintf('No transport category is available for BGoS mode "%s".', $mode));
    }

    /** @return array<string, string> */
    private function presentation(BgosCrewTransportSegment $segment): array
    {
        $presentation = [
            'origin' => $segment->getOrigin(),
            'destination' => $segment->getDestination(),
        ];
        foreach ([
            'originLatitude' => $segment->getOriginLatitude(),
            'originLongitude' => $segment->getOriginLongitude(),
            'destinationLatitude' => $segment->getDestinationLatitude(),
            'destinationLongitude' => $segment->getDestinationLongitude(),
        ] as $field => $value) {
            if (null !== $value) {
                $presentation[$field] = $value;
            }
        }

        return $presentation;
    }

    private function removeSegmentRecord(Project $project, BgosCrewTransportSegment $segment): void
    {
        $record = $segment->getEmissionRecord();
        if (null === $record) {
            return;
        }

        $this->assertRecordProject($project, $record);
        $segment->setEmissionRecord(null);
        $this->entityManager->remove($record);
    }

    private function assertRecordProject(Project $project, ?EmissionRecord $record): void
    {
        if (null !== $record && $record->getProject() !== $project) {
            throw new \LogicException('A linked BGoS emission record belongs to another project.');
        }
    }

    private function journeyProject(BgosCrewTransportJourney $journey): Project
    {
        $project = $journey->getProject();
        if (!$project instanceof Project) {
            throw new \LogicException('A BGoS crew transport journey requires a project.');
        }

        return $project;
    }

    private function transportCategory(): Category
    {
        $category = $this->categoryRepository->findOneBy(['name' => 'Transporte']);
        if (!$category instanceof Category || !$category->isEnabledInEmissionCalculator()) {
            throw new \LogicException('The transport emission category is not available.');
        }

        return $category;
    }

    private function multiplyDistance(string $distance, string $participants): string
    {
        if (!function_exists('bcmul')) {
            throw new \LogicException('The BCMath extension is required for BGoS transport emissions.');
        }

        $value = bcmul($distance, $participants, 3);

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
