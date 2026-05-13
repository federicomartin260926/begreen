<?php

namespace App\Entity;

use App\Repository\ProtocolRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProtocolRepository::class)]
#[Gedmo\TranslationEntity(class: \Gedmo\Translatable\Entity\Translation::class)]
class Protocol
{
    // ===== Constantes de agrupación =====
    public const GROUP_BY_CATEGORY   = 'category';
    public const GROUP_BY_DEPARTMENT = 'department';

    // ===== Constantes de tipo de protocolo =====
    public const TYPE_RODAJE = 'rodaje';
    public const TYPE_EVENTO = 'evento';
    public const TYPE_AMBOS  = 'ambos';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 80, nullable: true, unique: true)]
    private ?string $code = null;

    #[Gedmo\Translatable]
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $name = null;

    // rodaje, evento, o ambos
    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::TYPE_RODAJE, self::TYPE_EVENTO, self::TYPE_AMBOS])]
    private ?string $type = null;

    // cómo se agrupan/ordenan las medidas en el plan
    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::GROUP_BY_CATEGORY])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::GROUP_BY_CATEGORY, self::GROUP_BY_DEPARTMENT])]
    private string $groupingBy = self::GROUP_BY_CATEGORY;

    public function __toString(): string
    {
        return $this->name ?? '—';
    }

    // ===== Getters/Setters =====
    public function getId(): ?int { return $this->id; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): static { $this->code = $code; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getGroupingBy(): string { return $this->groupingBy; }
    public function setGroupingBy(string $groupingBy): self { $this->groupingBy = $groupingBy; return $this; }
}
