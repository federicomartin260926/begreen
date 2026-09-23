<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BgosCrewTransportJourneyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BgosCrewTransportJourneyRepository::class)]
#[ORM\Table(name: 'bgos_crew_transport_journey')]
class BgosCrewTransportJourney
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Project $project = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $mode = '';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $vehicleType = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $fuel = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $thermalFuel = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, BgosCrewTransportSegment> */
    #[ORM\OneToMany(
        mappedBy: 'journey',
        targetEntity: BgosCrewTransportSegment::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $segments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->segments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getVehicleType(): ?string
    {
        return $this->vehicleType;
    }

    public function setVehicleType(?string $vehicleType): self
    {
        $this->vehicleType = $vehicleType;

        return $this;
    }

    public function getFuel(): ?string
    {
        return $this->fuel;
    }

    public function setFuel(?string $fuel): self
    {
        $this->fuel = $fuel;

        return $this;
    }

    public function getThermalFuel(): ?string
    {
        return $this->thermalFuel;
    }

    public function setThermalFuel(?string $thermalFuel): self
    {
        $this->thermalFuel = $thermalFuel;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, BgosCrewTransportSegment> */
    public function getSegments(): Collection
    {
        return $this->segments;
    }

    public function addSegment(BgosCrewTransportSegment $segment): self
    {
        if (!$this->segments->contains($segment)) {
            $this->segments->add($segment);
            $segment->setJourney($this);
        }

        return $this;
    }

    public function removeSegment(BgosCrewTransportSegment $segment): self
    {
        if ($this->segments->removeElement($segment) && $segment->getJourney() === $this) {
            $segment->setJourney(null);
        }

        return $this;
    }
}
