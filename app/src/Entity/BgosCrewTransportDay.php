<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BgosCrewTransportDayRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: BgosCrewTransportDayRepository::class)]
#[ORM\Table(name: 'bgos_crew_transport_day')]
#[ORM\UniqueConstraint(
    name: 'uniq_bgos_crew_transport_day_member_date',
    columns: ['crew_member_id', 'tracking_date']
)]
class BgosCrewTransportDay
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RESOLVED,
        self::STATUS_NOT_APPLICABLE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?CrewMember $crewMember = null;

    #[ORM\Column(name: 'tracking_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CrewMemberAssignment $crewAssignment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $origin = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $vehicleType = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $fuel = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $thermalFuel = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCrewMember(): ?CrewMember
    {
        return $this->crewMember;
    }

    public function setCrewMember(?CrewMember $crewMember): self
    {
        $this->crewMember = $crewMember;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported BGoS crew transport status "%s".',
                $status
            ));
        }

        $this->status = $status;

        return $this;
    }

    public function getCrewAssignment(): ?CrewMemberAssignment
    {
        return $this->crewAssignment;
    }

    public function setCrewAssignment(?CrewMemberAssignment $crewAssignment): self
    {
        $this->crewAssignment = $crewAssignment;

        return $this;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): self
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

    #[Assert\Callback]
    public function validateCrewAssignment(ExecutionContextInterface $context): void
    {
        if (null === $this->crewMember || null === $this->crewAssignment) {
            return;
        }

        if ($this->crewAssignment->getCrewMember() !== $this->crewMember) {
            $context->buildViolation('La asignación de la jornada debe pertenecer al miembro del equipo.')
                ->atPath('crewAssignment')
                ->addViolation();
        }
    }
}
