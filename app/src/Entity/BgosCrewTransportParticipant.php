<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BgosCrewTransportParticipantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BgosCrewTransportParticipantRepository::class)]
#[ORM\Table(name: 'bgos_crew_transport_participant')]
#[ORM\UniqueConstraint(
    name: 'uniq_bgos_crew_transport_participant_segment_member',
    columns: ['segment_id', 'crew_member_id'],
)]
class BgosCrewTransportParticipant
{
    public const ROLE_DRIVER = 'driver';
    public const ROLE_PASSENGER = 'passenger';

    public const ROLES = [
        self::ROLE_DRIVER,
        self::ROLE_PASSENGER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?BgosCrewTransportSegment $segment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?CrewMember $crewMember = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::ROLES)]
    private string $role = self::ROLE_PASSENGER;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSegment(): ?BgosCrewTransportSegment
    {
        return $this->segment;
    }

    public function setSegment(?BgosCrewTransportSegment $segment): self
    {
        $this->segment = $segment;

        return $this;
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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported BGoS crew transport participant role "%s".',
                $role,
            ));
        }

        $this->role = $role;

        return $this;
    }
}
