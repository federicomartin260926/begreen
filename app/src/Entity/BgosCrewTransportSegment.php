<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BgosCrewTransportSegmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BgosCrewTransportSegmentRepository::class)]
#[ORM\Table(name: 'bgos_crew_transport_segment')]
#[ORM\UniqueConstraint(
    name: 'uniq_bgos_crew_transport_segment_journey_position',
    columns: ['journey_id', 'position'],
)]
class BgosCrewTransportSegment
{
    public const DISTANCE_SOURCE_ORS = 'ors';
    public const DISTANCE_SOURCE_MANUAL = 'manual';

    public const DISTANCE_SOURCES = [
        self::DISTANCE_SOURCE_ORS,
        self::DISTANCE_SOURCE_MANUAL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'segments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?BgosCrewTransportJourney $journey = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $origin = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $destination = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $originLatitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $originLongitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $destinationLatitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 7, nullable: true)]
    private ?string $destinationLongitude = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $distanceKm = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: self::DISTANCE_SOURCES)]
    private ?string $distanceSource = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmissionRecord $emissionRecord = null;

    /** @var Collection<int, BgosCrewTransportParticipant> */
    #[ORM\OneToMany(
        mappedBy: 'segment',
        targetEntity: BgosCrewTransportParticipant::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[Assert\Valid]
    private Collection $participants;

    public function __construct()
    {
        $this->participants = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJourney(): ?BgosCrewTransportJourney
    {
        return $this->journey;
    }

    public function setJourney(?BgosCrewTransportJourney $journey): self
    {
        $this->journey = $journey;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function getDestination(): string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): self
    {
        $this->destination = $destination;

        return $this;
    }

    public function getOriginLatitude(): ?string
    {
        return $this->originLatitude;
    }

    public function setOriginLatitude(?string $originLatitude): self
    {
        $this->originLatitude = $originLatitude;

        return $this;
    }

    public function getOriginLongitude(): ?string
    {
        return $this->originLongitude;
    }

    public function setOriginLongitude(?string $originLongitude): self
    {
        $this->originLongitude = $originLongitude;

        return $this;
    }

    public function getDestinationLatitude(): ?string
    {
        return $this->destinationLatitude;
    }

    public function setDestinationLatitude(?string $destinationLatitude): self
    {
        $this->destinationLatitude = $destinationLatitude;

        return $this;
    }

    public function getDestinationLongitude(): ?string
    {
        return $this->destinationLongitude;
    }

    public function setDestinationLongitude(?string $destinationLongitude): self
    {
        $this->destinationLongitude = $destinationLongitude;

        return $this;
    }

    public function getDistanceKm(): ?string
    {
        return $this->distanceKm;
    }

    public function setDistanceKm(?string $distanceKm): self
    {
        $this->distanceKm = $distanceKm;

        return $this;
    }

    public function getDistanceSource(): ?string
    {
        return $this->distanceSource;
    }

    public function setDistanceSource(?string $distanceSource): self
    {
        $this->distanceSource = $distanceSource;

        return $this;
    }

    public function getEmissionRecord(): ?EmissionRecord
    {
        return $this->emissionRecord;
    }

    public function setEmissionRecord(?EmissionRecord $emissionRecord): self
    {
        $this->emissionRecord = $emissionRecord;

        return $this;
    }

    /** @return Collection<int, BgosCrewTransportParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(BgosCrewTransportParticipant $participant): self
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
            $participant->setSegment($this);
        }

        return $this;
    }

    public function removeParticipant(BgosCrewTransportParticipant $participant): self
    {
        if ($this->participants->removeElement($participant) && $participant->getSegment() === $this) {
            $participant->setSegment(null);
        }

        return $this;
    }
}
