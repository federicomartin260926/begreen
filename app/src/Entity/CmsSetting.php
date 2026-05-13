<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cms_setting')]
#[ORM\UniqueConstraint(name: 'uniq_cms_setting_key', columns: ['s_key'])]
class CmsSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type:'integer')]
    private ?int $id = null;

    #[ORM\Column(name:'s_key', type:'string', length:64)]
    private string $key;

    #[ORM\Column(name:'s_value', type:'string', length:255)]
    private string $value;

    public function getId(): ?int { return $this->id; }

    public function getKey(): string { return $this->key; }
    public function setKey(string $key): self { $this->key = $key; return $this; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): self { $this->value = $value; return $this; }
}
