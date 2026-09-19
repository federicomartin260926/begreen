<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BgosCrewProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: BgosCrewProfileRepository::class)]
#[ORM\Table(name: 'bgos_crew_profile')]
class BgosCrewProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?CrewMember $crewMember = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CrewMemberAssignment $defaultAssignment = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $defaultOrigin = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $defaultMode = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $defaultVehicleType = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $defaultFuel = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $defaultThermalFuel = null;

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

    public function getDefaultAssignment(): ?CrewMemberAssignment
    {
        return $this->defaultAssignment;
    }

    public function setDefaultAssignment(?CrewMemberAssignment $defaultAssignment): self
    {
        $this->defaultAssignment = $defaultAssignment;

        return $this;
    }

    public function getDefaultOrigin(): ?string
    {
        return $this->defaultOrigin;
    }

    public function setDefaultOrigin(?string $defaultOrigin): self
    {
        $this->defaultOrigin = $defaultOrigin;

        return $this;
    }

    public function getDefaultMode(): ?string
    {
        return $this->defaultMode;
    }

    public function setDefaultMode(?string $defaultMode): self
    {
        $this->defaultMode = $defaultMode;

        return $this;
    }

    public function getDefaultVehicleType(): ?string
    {
        return $this->defaultVehicleType;
    }

    public function setDefaultVehicleType(?string $defaultVehicleType): self
    {
        $this->defaultVehicleType = $defaultVehicleType;

        return $this;
    }

    public function getDefaultFuel(): ?string
    {
        return $this->defaultFuel;
    }

    public function setDefaultFuel(?string $defaultFuel): self
    {
        $this->defaultFuel = $defaultFuel;

        return $this;
    }

    public function getDefaultThermalFuel(): ?string
    {
        return $this->defaultThermalFuel;
    }

    public function setDefaultThermalFuel(?string $defaultThermalFuel): self
    {
        $this->defaultThermalFuel = $defaultThermalFuel;

        return $this;
    }

    #[Assert\Callback]
    public function validateDefaultAssignment(ExecutionContextInterface $context): void
    {
        if (null === $this->crewMember || null === $this->defaultAssignment) {
            return;
        }

        if ($this->defaultAssignment->getCrewMember() !== $this->crewMember) {
            $context->buildViolation('La asignación habitual debe pertenecer al miembro del equipo.')
                ->atPath('defaultAssignment')
                ->addViolation();
        }
    }
}
