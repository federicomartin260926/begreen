<?php
namespace App\Entity;

use App\Repository\ProjectMembershipRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectMembershipRepository::class)]
#[ORM\Table(name: 'project_membership')]
#[ORM\UniqueConstraint(name: 'uniq_user_project', columns: ['user_id','project_id'])]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['project_id'])]
class ProjectMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'projectMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'projectMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    // Opcional: rol dentro del proyecto
    #[ORM\Column(length: 20, options: ['default' => 'member'])]
    private string $projectRole = 'member';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }
    public function getProject(): ?Project { return $this->project; }
    public function setProject(?Project $p): self { $this->project = $p; return $this; }
    public function getProjectRole(): string { return $this->projectRole; }
    public function setProjectRole(string $r): self { $this->projectRole = $r; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
